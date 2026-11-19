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
use App\Services\CloseEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Pengurus mencatat izin atas nama anggota; persetujuan setelah kegiatan ditutup mengoreksi Alpa. */
class AdminExcuseTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Term $term;
    private User $admin;
    private Division $humas;
    private Member $budi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->budi = Member::create(['organization_id' => $this->org->id, 'member_no' => 'B01', 'name' => 'Budi']);
        Membership::create(['member_id' => $this->budi->id, 'term_id' => $this->term->id, 'division_id' => $this->humas->id, 'status' => 'active']);
    }

    private function event(?int $division = null, array $extra = []): Event
    {
        return Event::create($extra + ['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => 'Rapat', 'division_id' => $division,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'checkin_opens_at' => now()->addDay()->subMinutes(30),
            'late_after' => now()->addDay()->addMinutes(10), 'checkin_closes_at' => now()->addDay()->addHour(), 'is_mandatory' => true]);
    }

    public function test_admin_records_and_approves_excuse_with_attachment(): void
    {
        $e = $this->event(extra: ['checkin_opens_at' => now()->subHour()]); // check-in sudah dibuka
        $this->actingAs($this->admin)->post('/izin', ['event_id' => $e->id, 'member_no' => 'TIDAKADA', 'type' => 'sakit', 'reason' => 'Demam'])->assertSessionHasErrors('member_no');
        $this->actingAs($this->admin)->post('/izin', [
            'event_id' => $e->id, 'member_no' => ' B01 ', 'type' => 'sakit', 'reason' => 'Demam, kabar via WA',
            'attachment' => UploadedFile::fake()->image('surat.jpg'), 'approve' => '1',
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        $x = ExcuseRequest::firstOrFail();
        $this->assertSame(['sakit', 'approved', $this->admin->id], [$x->type, $x->status, $x->reviewed_by]);
        Storage::disk('local')->assertExists($x->attachment_path);

        app(CloseEvent::class)->handle($e->fresh());
        $this->assertSame('sakit', Attendance::where('member_id', $this->budi->id)->value('status'));
    }

    public function test_approving_after_close_turns_alpa_into_excuse_with_history(): void
    {
        $e = $this->event(extra: ['checkin_opens_at' => now()->subHour()]);
        app(CloseEvent::class)->handle($e);
        $this->assertSame('alpa', Attendance::where('member_id', $this->budi->id)->value('status'));

        // Dicatat tanpa langsung setuju, lalu disetujui dari daftar.
        $this->actingAs($this->admin)->post('/izin', ['event_id' => $e->id, 'member_no' => 'B01', 'type' => 'izin', 'reason' => 'Ada keperluan keluarga'])->assertSessionHasNoErrors();
        $x = ExcuseRequest::firstOrFail();
        $this->assertSame('pending', $x->status);
        $this->actingAs($this->admin)->patch("/izin/{$x->id}", ['status' => 'approved'])->assertRedirect();

        $a = Attendance::where('member_id', $this->budi->id)->firstOrFail();
        $this->assertSame('izin', $a->status);
        $this->assertDatabaseHas('attendance_changes', ['attendance_id' => $a->id, 'from_status' => 'alpa', 'to_status' => 'izin']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'excuse.retroactive']);
    }

    public function test_division_admin_cannot_record_for_other_division_event(): void
    {
        $inti = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
        $intiEvent = $this->event($inti->id);
        $da = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $this->humas->id]);

        $this->actingAs($da)->post('/izin', ['event_id' => $intiEvent->id, 'member_no' => 'B01', 'type' => 'izin', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($da, 'sanctum')->postJson('/api/excuses', ['event_id' => $intiEvent->id, 'member_id' => $this->budi->id, 'type' => 'izin', 'reason' => 'x'])->assertForbidden();
        $this->assertSame(0, ExcuseRequest::count());

        // Kegiatan umum: boleh.
        $this->actingAs($da)->post('/izin', ['event_id' => $this->event()->id, 'member_no' => 'B01', 'type' => 'izin', 'reason' => 'Rapat kampus'])->assertSessionHasNoErrors();
        $this->assertSame(1, ExcuseRequest::count());
    }

    public function test_excuses_page_offers_manageable_events(): void
    {
        $this->event();
        $this->actingAs($this->admin)->get('/izin')->assertInertia(fn ($p) => $p->has('events', 1));
    }
}
