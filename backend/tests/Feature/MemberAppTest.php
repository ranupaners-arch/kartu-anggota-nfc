<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Division;
use App\Models\Event;
use App\Models\ExcuseRequest;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MemberAppTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Term $term;
    private Member $me;
    private User $user;
    private Event $open;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->me = $this->member('M1');
        $this->user = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $this->me->id]);
        $this->open = $this->event('Rapat Besok', now()->addDay());
    }

    private function member(string $no, ?Division $d = null): Member
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $no, 'name' => "Anggota $no"]);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'division_id' => $d?->id, 'status' => 'active']);

        return $m;
    }

    private function event(string $title, $start, array $extra = []): Event
    {
        return Event::create($extra + [
            'organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => $title,
            'starts_at' => $start, 'ends_at' => $start->copy()->addHours(2), 'checkin_opens_at' => $start->copy()->subMinutes(30),
            'late_after' => $start->copy()->addMinutes(10), 'checkin_closes_at' => $start->copy()->addHour(),
        ]);
    }

    private function as() { return $this->actingAs($this->user, 'sanctum'); }

    private function excuse(array $data) { return $this->as()->post('/api/excuses', $data + ['event_id' => $this->open->id, 'type' => 'sakit', 'reason' => 'Demam'], ['Accept' => 'application/json']); }

    public function test_my_events_only_where_i_am_target_with_status_and_excuse_info(): void
    {
        $humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $notMine = $this->event('Rapat Humas', now()->addDay());
        $notMine->targets()->create(['division_id' => $humas->id]);
        $past = $this->event('Rapat Kemarin', now()->subDay());
        Attendance::create(['event_id' => $past->id, 'session_id' => $past->defaultSession()->id, 'member_id' => $this->me->id, 'status' => 'telat', 'scanned_at' => now()->subDay()]);
        ExcuseRequest::create(['event_id' => $this->open->id, 'member_id' => $this->me->id, 'type' => 'izin', 'reason' => 'x', 'status' => 'pending']);

        $data = collect($this->as()->getJson('/api/me/events')->assertOk()->json('data'))->keyBy('title');
        $this->assertSame(['Rapat Kemarin', 'Rapat Besok'], $data->keys()->all());
        $this->assertSame('telat', $data['Rapat Kemarin']['my_status']);
        $this->assertFalse($data['Rapat Kemarin']['can_request_excuse']); // sudah hadir
        $this->assertSame('pending', $data['Rapat Besok']['excuse']['status']);
        $this->assertTrue($data['Rapat Besok']['can_request_excuse']); // masih bisa diperbarui
    }

    public function test_excuse_rules_for_members(): void
    {
        $humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $notMine = $this->event('Rapat Humas', now()->addDay());
        $notMine->targets()->create(['division_id' => $humas->id]);
        $this->excuse(['event_id' => $notMine->id])->assertStatus(422); // bukan peserta

        $first = UploadedFile::fake()->image('surat.jpg');
        $this->excuse(['attachment' => $first])->assertCreated();
        $x = ExcuseRequest::firstOrFail();
        $firstPath = $x->attachment_path;
        Storage::disk('local')->assertExists($firstPath);

        $this->excuse(['reason' => 'Demam tinggi'])->assertCreated(); // kirim ulang tanpa lampiran: lampiran lama tetap
        $this->assertSame([$firstPath, 'Demam tinggi'], [$x->fresh()->attachment_path, $x->fresh()->reason]);

        $this->excuse(['attachment' => UploadedFile::fake()->create('surat.pdf', 100, 'application/pdf')])->assertCreated();
        Storage::disk('local')->assertMissing($firstPath); // lampiran lama diganti & dihapus

        $x->update(['status' => 'rejected']);
        $this->excuse([])->assertStatus(422); // yang sudah diputuskan tidak bisa dibuka ulang
        $this->assertSame('rejected', $x->fresh()->status);

        $this->excuse(['attachment' => UploadedFile::fake()->create('besar.pdf', 6000, 'application/pdf')])->assertStatus(422); // > 5 MB
    }

    public function test_cannot_request_excuse_when_already_present_or_event_closed(): void
    {
        Attendance::create(['event_id' => $this->open->id, 'session_id' => $this->open->defaultSession()->id, 'member_id' => $this->me->id, 'status' => 'hadir', 'scanned_at' => now()]);
        $this->excuse([])->assertStatus(422);

        $closed = $this->event('Sudah Tutup', now()->subDay(), ['closed_at' => now()]);
        $this->excuse(['event_id' => $closed->id])->assertStatus(422);
    }

    public function test_history_summary(): void
    {
        foreach (['hadir', 'hadir', 'telat', 'alpa'] as $i => $status) {
            $e = $this->event("K$i", now()->subDays($i + 1));
            Attendance::create(['event_id' => $e->id, 'session_id' => $e->defaultSession()->id, 'member_id' => $this->me->id, 'status' => $status]);
        }
        $this->as()->getJson('/api/me/history')->assertOk()
            ->assertJsonPath('total', 4)->assertJsonPath('by_status.hadir', 2)->assertJsonPath('attendance_rate', 75)
            ->assertJsonPath('recent.0.event', 'K0')->assertJsonCount(4, 'recent');
    }

    public function test_history_monthly_breakdown_in_time_order(): void
    {
        $thisMonth = now()->startOfMonth()->addHours(12);
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(5)->addHours(12);
        foreach ([[$thisMonth, 'hadir'], [$lastMonth, 'telat'], [$lastMonth->copy()->addDay(), 'alpa'], [$lastMonth->copy()->addDays(2), 'hadir']] as $i => [$at, $status]) {
            $e = $this->event("M$i", $at);
            Attendance::create(['event_id' => $e->id, 'session_id' => $e->defaultSession()->id, 'member_id' => $this->me->id, 'status' => $status]);
        }
        $monthly = $this->as()->getJson('/api/me/history')->assertOk()->json('monthly');

        $this->assertSame([$lastMonth->format('Y-m'), $thisMonth->format('Y-m')], array_column($monthly, 'month'));
        $this->assertSame(['total' => 3, 'hadir' => 1, 'telat' => 1, 'alpa' => 1], array_intersect_key($monthly[0], array_flip(['total', 'hadir', 'telat', 'alpa'])));
        $this->assertSame([1, 1, 0], [$monthly[1]['total'], $monthly[1]['hadir'], $monthly[1]['izin']]);
    }

    public function test_account_without_member_link_gets_clear_error(): void
    {
        $u = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => null]);
        $this->actingAs($u, 'sanctum')->getJson('/api/me/events')->assertStatus(422)->assertJsonPath('message', 'Akun belum terdaftar sebagai anggota. Isi e-profil dulu di tab Kartu.');
    }
}
