<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Event;
use App\Models\Export;
use App\Models\Fine;
use App\Models\Member;
use App\Models\MemberQr;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Support\CardPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Kebijakan denda: absen pakai QR padahal punya kartu aktif (kartu tertinggal). */
class FineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private User $admin;

    private User $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->scanner = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'scanner']);
    }

    private function member(string $no, bool $withCard): Member
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $no, 'name' => "Anggota $no"]);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'status' => 'active']);
        if ($withCard) {
            Card::create(['organization_id' => $this->org->id, 'public_id' => (string) Str::ulid(), 'key_id' => 'k1', 'status' => 'active', 'member_id' => $m->id, 'issued_at' => now()]);
        }

        return $m;
    }

    private function event(): Event
    {
        return Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => 'Rapat '.Str::random(4),
            'starts_at' => now()->addMinutes(10), 'ends_at' => now()->addHours(2), 'checkin_opens_at' => now()->subMinutes(20),
            'late_after' => now()->addMinutes(15), 'checkin_closes_at' => now()->addHour()]);
    }

    private function scanQr(Member $m, Event $e): array
    {
        $q = MemberQr::updateOrCreate(['member_id' => $m->id], ['token' => Str::random(40), 'rotated_at' => now()]);
        Sanctum::actingAs($this->scanner);

        return $this->postJson("/api/events/{$e->id}/scan", ['payload' => CardPayload::makeQr($q->token), 'source' => 'qr'])->assertOk()->json();
    }

    private function policy(bool $on, int $amount = 5000, int $free = 0): void
    {
        $this->org->update(['fine_enabled' => $on, 'fine_amount' => $amount, 'fine_free_per_term' => $free]);
    }

    public function test_no_fine_when_disabled_or_member_has_no_active_card(): void
    {
        $withCard = $this->member('A', true);
        $this->assertSame(0, $this->scanQr($withCard, $this->event())['fine']); // kebijakan nonaktif
        $this->policy(true);
        $noCard = $this->member('B', false);
        $res = $this->scanQr($noCard, $this->event());
        $this->assertSame([0, 'Berhasil.'], [$res['fine'], $res['message']]);
        $this->assertSame(0, Fine::count());
    }

    public function test_fine_after_free_quota_and_message_for_scanner(): void
    {
        $this->policy(true, 7500, 1);
        $m = $this->member('A', true);
        $first = $this->scanQr($m, $this->event());
        $this->assertSame(0, $first['fine']); // jatah gratis
        $second = $this->scanQr($m, $this->event());
        $this->assertSame(7500, $second['fine']);
        $this->assertStringContainsString('Denda QR Rp7.500', $second['message']);
        $this->assertSame([Fine::FREE, Fine::UNPAID], Fine::orderBy('id')->pluck('status')->all());

        // Anggota melihat dendanya di riwayat.
        Sanctum::actingAs(User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $m->id]));
        $this->getJson('/api/me/history')->assertJsonPath('fines.unpaid_total', 7500)->assertJsonCount(1, 'fines.items');
    }

    public function test_lost_card_reported_means_no_fine(): void
    {
        $this->policy(true);
        $m = $this->member('A', true);
        Card::where('member_id', $m->id)->update(['status' => Card::LOST]); // laporan "hilang" memblokir kartu
        $this->assertSame(0, $this->scanQr($m, $this->event())['fine']);
    }

    public function test_admin_marks_paid_waives_and_correction_waives_automatically(): void
    {
        $this->policy(true);
        $m = $this->member('A', true);
        $this->scanQr($m, $this->event());
        $this->scanQr($m, $this->event());
        [$f1, $f2] = Fine::orderBy('id')->get();
        auth()->forgetGuards();

        $this->actingAs($this->admin, 'web')->get('/denda')->assertInertia(fn (Assert $p) => $p->component('fines/index')
            ->where('summary.unpaid_total', 10000)->has('fines.data', 2));
        $this->actingAs($this->admin, 'web')->post('/denda/tandai', ['ids' => [$f1->id], 'status' => 'paid'])->assertSessionHas('status', '1 denda ditandai lunas.');
        $this->assertSame([Fine::PAID, $this->admin->id], [$f1->fresh()->status, $f1->fresh()->handled_by]);

        // Absensi kedua dibatalkan admin → dendanya otomatis dibebaskan.
        $this->actingAs($this->admin, 'web')->patch("/absensi/{$f2->attendance_id}", ['status' => 'void', 'reason' => 'Salah scan']);
        $this->assertSame(Fine::WAIVED, $f2->fresh()->status);

        $this->actingAs($this->admin, 'web')->put('/pengaturan/denda', ['fine_enabled' => false, 'fine_amount' => 2000, 'fine_free_per_term' => 2])->assertSessionHas('status');
        $this->assertSame([false, 2000, 2], [$this->org->fresh()->fine_enabled, $this->org->fresh()->fine_amount, $this->org->fresh()->fine_free_per_term]);
    }

    public function test_term_report_and_export_include_fines(): void
    {
        Storage::fake('local');
        $this->policy(true, 5000);
        $m = $this->member('A', true);
        $this->member('B', false);
        $this->scanQr($m, $this->event());
        $this->scanQr($m, $this->event());
        Fine::orderBy('id')->first()->update(['status' => Fine::PAID]);
        auth()->forgetGuards();

        $this->actingAs($this->admin, 'web')->get('/rekap?tab=periode')->assertInertia(fn (Assert $p) => $p
            ->where('report.show_fines', true)->where('report.fines_unpaid_total', 5000)
            ->where('report.rows', fn ($rows) => collect($rows)->firstWhere('member_no', 'A')['fines_count'] === 2
                && collect($rows)->firstWhere('member_no', 'A')['fines_paid'] === 5000 && collect($rows)->firstWhere('member_no', 'B')['fines_count'] === 0));

        $this->actingAs($this->admin, 'web')->post(route('exports.store'), ['report' => 'term', 'term_id' => $this->term->id, 'format' => 'pdf'])->assertRedirect();
        $export = Export::sole();
        $this->assertSame('ready', $export->fresh()->status);

        $this->policy(false);
        Fine::query()->delete();
        $this->actingAs($this->admin, 'web')->get('/rekap?tab=periode')->assertInertia(fn (Assert $p) => $p->where('report.show_fines', false));
    }

    public function test_only_super_admin_manages_fines(): void
    {
        $da = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin']);
        $this->actingAs($da, 'web')->get('/denda')->assertForbidden();
        $this->actingAs($da, 'web')->put('/pengaturan/denda', ['fine_enabled' => true, 'fine_amount' => 1, 'fine_free_per_term' => 0])->assertForbidden();
    }
}
