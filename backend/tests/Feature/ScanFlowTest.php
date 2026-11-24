<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Card;
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
use Illuminate\Support\Str;
use Tests\TestCase;

class ScanFlowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private Event $event;

    private User $admin;

    private User $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['attendance.card_keys.k1' => 'test-secret', 'attendance.payload_prefix' => 'card']);

        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->scanner = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'scanner']);

        $this->event = Event::create([
            'organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => 'Rapat',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(2),
            'checkin_opens_at' => now()->subMinutes(30), 'late_after' => now()->addMinutes(10),
            'checkin_closes_at' => now()->addHour(), 'is_mandatory' => true,
        ]);
    }

    private function member(string $no = '1001', ?Organization $org = null, ?Term $term = null): Member
    {
        $org ??= $this->org;
        $m = Member::create(['organization_id' => $org->id, 'member_no' => $no, 'name' => "Anggota $no"]);
        Membership::create(['member_id' => $m->id, 'term_id' => ($term ?? $this->term)->id, 'status' => 'active']);

        return $m;
    }

    private function issueCard(Member $m): string
    {
        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/cards/init')->assertCreated();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/cards/{$res['id']}/assign", ['member_id' => $m->id])->assertOk();

        return $res['payload'];
    }

    private function scan(string $payload, array $extra = [])
    {
        return $this->actingAs($this->scanner, 'sanctum')
            ->postJson("/api/events/{$this->event->id}/scan", ['payload' => $payload] + $extra);
    }

    public function test_valid_scan_is_hadir_then_duplicate(): void
    {
        $payload = $this->issueCard($this->member());

        $this->scan($payload)->assertOk()->assertJson(['status' => 'ok', 'attendance_status' => 'hadir']);
        $this->scan($payload)->assertOk()->assertJson(['status' => 'duplicate']);
        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_scan_after_late_after_is_telat(): void
    {
        $payload = $this->issueCard($this->member());

        $this->event->update(['late_after' => now()->subMinutes(5)]);

        $this->scan($payload)->assertJson(['status' => 'ok', 'attendance_status' => 'telat']);
    }

    public function test_tampered_hmac_is_invalid(): void
    {
        $payload = $this->issueCard($this->member());
        $bad = substr($payload, 0, -4).'0000';

        $this->scan($bad)->assertJson(['status' => 'invalid']);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_new_card_blocks_old_card_and_blocked_is_rejected(): void
    {
        $m = $this->member();
        $old = $this->issueCard($m);
        $new = $this->issueCard($m);

        $this->assertSame(1, Card::where('member_id', $m->id)->where('status', 'active')->count());
        $this->scan($old)->assertJson(['status' => 'blocked']);
        $this->scan($new)->assertJson(['status' => 'ok']);
    }

    public function test_outside_window_rejected_unless_override_with_reason(): void
    {
        $payload = $this->issueCard($this->member());
        $early = now()->subHours(3)->toIso8601String();

        $this->scan($payload, ['scanned_at' => $early, 'client_uuid' => (string) Str::uuid()])
            ->assertJson(['status' => 'clock_drift']); // online: jam jauh dari server

        $this->event->update(['checkin_opens_at' => now()->addMinutes(5), 'late_after' => now()->addMinutes(15), 'checkin_closes_at' => now()->addMinutes(30)]);
        $this->scan($payload)->assertJson(['status' => 'outside_window']);
        $this->scan($payload, ['override_reason' => 'Panitia terlambat membuka'])->assertJson(['status' => 'ok']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'scan.override']);
    }

    public function test_not_target_member_rejected(): void
    {
        $div = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->event->targets()->create(['division_id' => $div->id]);
        $payload = $this->issueCard($this->member()); // anggota tanpa divisi Humas

        $this->scan($payload)->assertJson(['status' => 'not_target']);
    }

    public function test_offline_batch_is_idempotent(): void
    {
        $payload = $this->issueCard($this->member());
        $uuid = (string) Str::uuid();
        $body = ['scans' => [['payload' => $payload, 'client_uuid' => $uuid, 'scanned_at' => now()->subMinutes(5)->toIso8601String()]]];
        $url = "/api/events/{$this->event->id}/scan/batch";

        $this->actingAs($this->scanner, 'sanctum')->postJson($url, $body)->assertOk()->assertJsonPath('results.0.status', 'ok');
        $this->actingAs($this->scanner, 'sanctum')->postJson($url, $body)->assertOk()->assertJsonPath('results.0.status', 'duplicate');
        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_offline_scan_of_card_blocked_afterwards_is_flagged_for_review(): void
    {
        $m = $this->member();
        $payload = $this->issueCard($m);
        $scanTime = now()->subMinutes(10);
        Card::where('member_id', $m->id)->update(['status' => 'blocked', 'revoked_at' => now()]);

        $this->actingAs($this->scanner, 'sanctum')->postJson("/api/events/{$this->event->id}/scan/batch", [
            'scans' => [['payload' => $payload, 'client_uuid' => (string) Str::uuid(), 'scanned_at' => $scanTime->toIso8601String()]],
        ])->assertJsonPath('results.0.status', 'ok');

        $this->assertDatabaseHas('attendances', ['member_id' => $m->id, 'needs_review' => true]);
    }

    public function test_offline_scan_sent_after_event_closed_replaces_alpa(): void
    {
        $m = $this->member();
        $payload = $this->issueCard($m);
        $at = now()->toIso8601String();

        $this->travel(3)->hours();
        app(CloseEvent::class)->handle($this->event->fresh());
        $this->assertDatabaseHas('attendances', ['member_id' => $m->id, 'status' => 'alpa']);

        $this->actingAs($this->scanner, 'sanctum')->postJson("/api/events/{$this->event->id}/scan/batch", ['scans' => [
            ['payload' => $payload, 'source' => 'nfc', 'client_uuid' => (string) Str::uuid(), 'scanned_at' => $at],
        ]])->assertOk()->assertJsonPath('results.0.status', 'ok');

        $this->assertDatabaseCount('attendances', 1);
        $this->assertDatabaseHas('attendances', ['member_id' => $m->id, 'status' => 'hadir', 'source' => 'nfc']);
    }

    public function test_closing_event_marks_alpa_and_excused(): void
    {
        $present = $this->member('1');
        $absent = $this->member('2');
        $sick = $this->member('3');
        $this->scan($this->issueCard($present))->assertJson(['status' => 'ok']);
        ExcuseRequest::create(['event_id' => $this->event->id, 'member_id' => $sick->id, 'type' => 'sakit', 'reason' => 'demam', 'status' => 'approved']);

        app(CloseEvent::class)->handle($this->event);

        $this->assertDatabaseHas('attendances', ['member_id' => $absent->id, 'status' => 'alpa']);
        $this->assertDatabaseHas('attendances', ['member_id' => $sick->id, 'status' => 'sakit']);
        $this->assertDatabaseHas('attendances', ['member_id' => $present->id, 'status' => 'hadir']);
    }

    public function test_correction_keeps_history_and_requires_reason(): void
    {
        $this->scan($this->issueCard($this->member()))->assertJson(['status' => 'ok']);
        $att = Attendance::first();

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/attendances/{$att->id}", ['status' => 'izin'])->assertUnprocessable();
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/attendances/{$att->id}", ['status' => 'izin', 'reason' => 'Salah catat'])->assertOk();
        $this->assertDatabaseHas('attendance_changes', ['attendance_id' => $att->id, 'from_status' => 'hadir', 'to_status' => 'izin']);
    }

    public function test_roles_are_enforced(): void
    {
        $this->actingAs($this->scanner, 'sanctum')->postJson('/api/cards/init')->assertForbidden();
        $this->actingAs($this->scanner, 'sanctum')->getJson('/api/devices')->assertForbidden();
        $member = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member']);
        $this->actingAs($member, 'sanctum')->getJson('/api/events')->assertForbidden();
    }

    public function test_login_registers_device_and_revoke_blocks_it(): void
    {
        $this->scanner->update(['password' => 'secret123']);
        $login = $this->postJson('/api/auth/login', [
            'email' => $this->scanner->email, 'password' => 'secret123', 'device_uuid' => 'dev-1', 'device_name' => 'HP Petugas',
        ])->assertOk();

        $this->withToken($login['token'])->getJson('/api/me')->assertOk();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/devices/'.$login['device_id'].'/revoke')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($login['token'])->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/auth/login', [
            'email' => $this->scanner->email, 'password' => 'secret123', 'device_uuid' => 'dev-1', 'device_name' => 'HP Petugas',
        ])->assertForbidden();
    }

    public function test_qr_fallback_works_and_expires(): void
    {
        $m = $this->member();
        $memberUser = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $m->id]);

        $first = $this->actingAs($memberUser, 'sanctum')->getJson('/api/me/qr')->assertOk()['payload'];
        $again = $this->actingAs($memberUser, 'sanctum')->getJson('/api/me/qr')->assertOk()['payload'];
        $this->assertSame($first, $again); // belum waktunya rotasi

        $this->travel(2)->minutes();
        $this->scan($first, ['source' => 'qr'])->assertJson(['status' => 'invalid']); // kedaluwarsa

        $fresh = $this->actingAs($memberUser, 'sanctum')->getJson('/api/me/qr')->assertOk()['payload'];
        $this->assertNotSame($first, $fresh);
        $this->scan($fresh, ['source' => 'qr'])->assertJson(['status' => 'ok']);
        $this->assertDatabaseHas('attendances', ['member_id' => $m->id, 'source' => 'qr']);
    }

    public function test_payload_uses_configured_prefix(): void
    {
        $payload = $this->issueCard($this->member());
        $this->assertStringStartsWith('card:v1:k1:', $payload);
        $init = $this->actingAs($this->admin, 'sanctum')->postJson('/api/cards/init')->assertCreated();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $init['ntag']['pwd']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{4}$/', $init['ntag']['pack']);

        config(['attendance.payload_prefix' => 'lain']);
        $this->scan($payload)->assertJson(['status' => 'invalid']); // prefix berbeda = kartu asing
    }

    public function test_organizations_are_isolated(): void
    {
        // Organisasi kedua dengan admin, anggota, kartu, dan kegiatannya sendiri.
        $org2 = Organization::create(['name' => 'Karang Taruna', 'slug' => 'karang-taruna']);
        $term2 = Term::create(['organization_id' => $org2->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $admin2 = User::factory()->create(['organization_id' => $org2->id, 'role' => 'super_admin']);
        $member2 = $this->member('9001', $org2, $term2);
        $event2 = Event::create([
            'organization_id' => $org2->id, 'term_id' => $term2->id, 'title' => 'Kerja Bakti',
            'starts_at' => now(), 'ends_at' => now()->addHour(), 'checkin_opens_at' => now()->subMinutes(5),
            'late_after' => now()->addMinutes(10), 'checkin_closes_at' => now()->addHour(),
        ]);
        $init2 = $this->actingAs($admin2, 'sanctum')->postJson('/api/cards/init')->assertCreated();
        $this->actingAs($admin2, 'sanctum')->postJson("/api/cards/{$init2['id']}/assign", ['member_id' => $member2->id])->assertOk();
        $this->assertDatabaseHas('cards', ['id' => $init2['id'], 'organization_id' => $org2->id]);

        // Org 1 tidak bisa melihat atau menyentuh data org 2.
        $titles = collect($this->actingAs($this->scanner, 'sanctum')->getJson('/api/events')->json('data'))->pluck('title');
        $this->assertNotContains('Kerja Bakti', $titles);
        $this->actingAs($this->scanner, 'sanctum')->getJson("/api/events/{$event2->id}/roster")->assertNotFound();
        $this->actingAs($this->scanner, 'sanctum')->postJson("/api/events/{$event2->id}/scan", ['payload' => $init2['payload']])->assertNotFound();
        $this->scan($init2['payload'])->assertJson(['status' => 'invalid']); // kartu org 2 di event org 1

        $init1 = $this->actingAs($this->admin, 'sanctum')->postJson('/api/cards/init')->assertCreated();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/cards/{$init1['id']}/assign", ['member_id' => $member2->id])->assertNotFound();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/cards/{$init2['id']}/block")->assertNotFound();
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/members/{$member2->id}/summary")->assertNotFound();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/events', [
            'title' => 'X', 'starts_at' => now(), 'ends_at' => now()->addHour(), 'checkin_opens_at' => now(),
            'late_after' => now(), 'checkin_closes_at' => now()->addHour(), 'target_member_ids' => [$member2->id],
        ])->assertUnprocessable();
    }

    public function test_user_without_organization_sees_nothing(): void
    {
        $orphan = User::factory()->create(['organization_id' => null, 'role' => 'scanner']);
        $this->actingAs($orphan, 'sanctum')->getJson('/api/events')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($orphan, 'sanctum')->getJson("/api/events/{$this->event->id}/roster")->assertNotFound();
    }

    public function test_roster_contract_for_offline_app(): void
    {
        // Kontrak dengan app (lib/offline/roster.dart): sidik jari = sha256 payload kartu persis,
        // dan event memuat jendela check-in yang dipakai pemeriksaan offline.
        $payload = $this->issueCard($this->member());
        $roster = $this->actingAs($this->scanner, 'sanctum')->getJson("/api/events/{$this->event->id}/roster")->assertOk();

        $this->assertSame(hash('sha256', $payload), $roster->json('members.0.cards.0.payload_sha256'));
        $roster->assertJsonStructure([
            'event' => ['id', 'title', 'checkin_opens_at', 'late_after', 'checkin_closes_at', 'closed_at'],
            'members' => [['id', 'member_no', 'name', 'division', 'cards' => [['public_id', 'status', 'payload_sha256']]]],
            'generated_at',
        ]);
    }

    public function test_scan_result_has_photo_url_scanner_can_open(): void
    {
        Storage::fake('local');
        $m = $this->member();
        $payload = $this->issueCard($m);
        $this->actingAs($this->admin, 'sanctum')->post("/api/members/{$m->id}/photo",
            ['photo' => UploadedFile::fake()->image('wajah.jpg', 800, 800)], ['Accept' => 'application/json'])->assertOk();

        $url = $this->scan($payload)->assertJson(['status' => 'ok'])->json('member.photo_url');
        $this->assertStringContainsString("/api/members/{$m->id}/photo", $url);
        $this->actingAs($this->scanner, 'sanctum')->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // Roster (dipakai app untuk foto offline) memakai URL yang sama; versi berubah saat foto diganti.
        $event = Event::findOrFail($this->event->id);
        $roster = collect($this->actingAs($this->scanner, 'sanctum')->getJson("/api/events/{$event->id}/roster")->json('members'))->firstWhere('id', $m->id);
        $this->assertSame($url, $roster['photo_url']);
        $this->assertMatchesRegularExpression('/\?v=[0-9a-f]{8}$/', $url);
        $this->actingAs($this->admin, 'sanctum')->post("/api/members/{$m->id}/photo",
            ['photo' => UploadedFile::fake()->image('baru.jpg', 300, 300)], ['Accept' => 'application/json'])->assertOk();
        $this->assertNotSame($url, $this->scan($payload)->json('member.photo_url'));
    }
}
