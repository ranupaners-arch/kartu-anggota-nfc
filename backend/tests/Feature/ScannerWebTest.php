<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Division;
use App\Models\Event;
use App\Models\Member;
use App\Models\MemberQr;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Support\CardPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Mode petugas di browser: pindai QR Saya anggota dengan kamera. */
class ScannerWebTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private Division $humas;

    private Member $budi;

    private User $scanner;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->budi = Member::create(['organization_id' => $this->org->id, 'member_no' => 'B01', 'name' => 'Budi']);
        Membership::create(['member_id' => $this->budi->id, 'term_id' => $this->term->id, 'division_id' => $this->humas->id, 'status' => 'active']);
        $this->scanner = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'scanner', 'email' => 'petugas@hmps.test', 'password' => 'Petugas12345']);
        $this->event = $this->event('Rapat');
    }

    private function event(string $title, ?Division $owner = null): Event
    {
        return Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => $title, 'division_id' => $owner?->id,
            'starts_at' => now()->addMinutes(10), 'ends_at' => now()->addHours(2), 'checkin_opens_at' => now()->subMinutes(20),
            'late_after' => now()->addMinutes(15), 'checkin_closes_at' => now()->addHour()]);
    }

    private function qr(Member $m): string
    {
        $q = MemberQr::create(['member_id' => $m->id, 'token' => Str::random(40), 'rotated_at' => now()]);

        return CardPayload::makeQr($q->token);
    }

    public function test_scanner_logs_in_to_scan_page_and_sees_open_events(): void
    {
        $this->post('/masuk', ['email' => 'petugas@hmps.test', 'password' => 'Petugas12345'])->assertRedirect('/pindai');
        $this->get('/')->assertRedirect('/pindai');
        $this->get('/pindai')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('scanner/index')->has('events', 1)->where('events.0.title', 'Rapat')->where('events.0.open', true));
        $this->get("/pindai/{$this->event->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('scanner/scan')->where('event.title', 'Rapat'));
    }

    public function test_scan_records_qr_attendance_like_the_app(): void
    {
        $payload = $this->qr($this->budi);
        $this->actingAs($this->scanner)->postJson("/pindai/{$this->event->id}/scan", ['payload' => $payload])->assertOk()
            ->assertJsonPath('status', 'ok')->assertJsonPath('attendance_status', 'hadir')->assertJsonPath('member.name', 'Budi');
        $a = Attendance::sole();
        $this->assertSame(['qr', null, $this->scanner->id], [$a->source, $a->device_id, $a->scanned_by]);

        $this->actingAs($this->scanner)->postJson("/pindai/{$this->event->id}/scan", ['payload' => $payload])->assertJsonPath('status', 'duplicate');
        $this->actingAs($this->scanner)->postJson("/pindai/{$this->event->id}/scan", ['payload' => 'bukan-qr'])->assertJsonPath('status', 'invalid');
        $this->actingAs($this->scanner)->postJson("/pindai/{$this->event->id}/scan", [])->assertUnprocessable();
    }

    public function test_access_rules(): void
    {
        $inti = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
        $other = $this->event('Rapat Inti', $inti);
        $da = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $this->humas->id]);
        $this->actingAs($da)->postJson("/pindai/{$other->id}/scan", ['payload' => $this->qr($this->budi)])->assertForbidden();

        $memberUser = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $this->budi->id]);
        $this->actingAs($memberUser)->get('/pindai')->assertRedirect('/saya');

        $this->scanner->update(['must_change_password' => true]);
        $this->actingAs($this->scanner)->get('/pindai')->assertRedirect('/pindai/password');
        $this->actingAs($this->scanner)->put('/pindai/password', ['current_password' => 'Petugas12345', 'password' => 'PetugasBaru123', 'password_confirmation' => 'PetugasBaru123'])
            ->assertRedirect('/pindai');
        $this->assertFalse($this->scanner->fresh()->must_change_password);
    }
}
