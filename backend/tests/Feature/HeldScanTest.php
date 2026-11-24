<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Event;
use App\Models\HeldScan;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** US-10: scan offline dari perangkat yang dicabut ditahan untuk tinjauan. */
class HeldScanTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $scanner;

    private Event $event;

    private Member $member;

    private string $payload;

    private string $token;

    private int $deviceId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['attendance.card_keys.k1' => 'test-secret', 'attendance.payload_prefix' => 'card']);
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->scanner = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'scanner', 'password' => 'secret123']);
        $this->event = Event::create(['organization_id' => $this->org->id, 'term_id' => $term->id, 'title' => 'Rapat',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(2), 'checkin_opens_at' => now()->subMinutes(30),
            'late_after' => now()->addMinutes(10), 'checkin_closes_at' => now()->addHour(), 'is_mandatory' => true]);
        $this->member = Member::create(['organization_id' => $this->org->id, 'member_no' => '1', 'name' => 'Budi']);
        Membership::create(['member_id' => $this->member->id, 'term_id' => $term->id, 'status' => 'active']);
        $card = $this->actingAs($this->admin, 'sanctum')->postJson('/api/cards/init')->assertCreated();
        $this->postJson("/api/cards/{$card['id']}/assign", ['member_id' => $this->member->id])->assertOk();
        $this->payload = $card['payload'];

        $login = $this->postJson('/api/auth/login', ['email' => $this->scanner->email, 'password' => 'secret123', 'device_uuid' => 'dev-1', 'device_name' => 'HP Petugas'])->assertOk();
        [$this->token, $this->deviceId] = [$login['token'], $login['device_id']];
    }

    private function revoke(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/devices/{$this->deviceId}/revoke")->assertOk()->assertJsonMissingPath('held_token_hash');
        $this->app['auth']->forgetGuards();
    }

    private function item(array $extra = []): array
    {
        return $extra + ['client_uuid' => (string) Str::uuid(), 'event_id' => $this->event->id, 'payload' => $this->payload, 'scanned_at' => now()->toIso8601String()];
    }

    private function upload(array $scans, ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token)->postJson('/api/devices/held-scans', ['scans' => $scans]);
    }

    private function web(User $u)
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->withoutToken()->actingAs($u, 'web');
    }

    public function test_revoked_device_scans_are_held_not_recorded(): void
    {
        $this->revoke();
        $scan = $this->item();
        $this->upload([$scan])->assertOk()->assertJsonPath('results.0.status', 'held');
        $this->upload([$scan])->assertOk()->assertJsonPath('results.0.status', 'held'); // kirim ulang: tidak ganda
        $this->assertDatabaseCount('held_scans', 1);
        $this->assertDatabaseCount('attendances', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'scan.held', 'entity_id' => $this->deviceId]);
        // Token lama tetap tidak bisa dipakai untuk hal lain.
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->getJson('/api/me')->assertUnauthorized();
        $this->postJson("/api/events/{$this->event->id}/scan/batch", ['scans' => [$scan]])->assertUnauthorized();
    }

    public function test_only_the_revoked_devices_own_token_works_and_only_for_seven_days(): void
    {
        $this->upload([$this->item()])->assertForbidden(); // belum dicabut
        $this->revoke();
        $this->upload([$this->item()], Str::before($this->token, '|').'|salah')->assertForbidden();
        $this->upload([$this->item()], 'sampah')->assertForbidden();
        $this->travel(8)->days();
        $this->upload([$this->item()])->assertForbidden();
        $this->assertDatabaseCount('held_scans', 0);
    }

    public function test_revoking_again_does_not_extend_the_window(): void
    {
        $this->revoke();
        $this->travel(6)->days();
        $this->revoke();
        $this->travel(2)->days();
        $this->upload([$this->item()])->assertForbidden();
    }

    public function test_fake_cards_and_other_organizations_events_are_rejected(): void
    {
        $this->revoke();
        $org = Organization::create(['name' => 'Lain', 'slug' => 'lain']);
        $term = Term::create(['organization_id' => $org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $other = $this->event->replicate()->fill(['organization_id' => $org->id, 'term_id' => $term->id]);
        $other->save();
        $this->upload([$this->item(['payload' => substr($this->payload, 0, -4).'0000']), $this->item(['event_id' => $other->id]), $this->item(['event_id' => 999999])])
            ->assertOk()->assertJsonPath('results.0.status', 'invalid')->assertJsonPath('results.1.status', 'invalid')->assertJsonPath('results.2.status', 'invalid');
        $this->assertDatabaseCount('held_scans', 0);
        $this->upload([$this->item(['scanned_at' => now()->addDay()->toIso8601String()])])->assertUnprocessable();
    }

    public function test_per_device_limit(): void
    {
        config(['attendance.held_max' => 2]);
        $this->revoke();
        $this->upload([$this->item(), $this->item(), $this->item()])->assertUnprocessable();
        $this->assertDatabaseCount('held_scans', 0);
    }

    public function test_admin_accepts_and_attendance_uses_scan_time(): void
    {
        $this->revoke();
        $this->upload([$this->item()])->assertOk();
        $held = HeldScan::withoutGlobalScopes()->firstOrFail();
        $this->travel(5)->hours(); // kegiatan sudah lewat saat ditinjau

        $this->web($this->admin)->get('/tinjauan?tab=tertahan')->assertOk()->assertInertia(fn ($p) => $p
            ->component('reviews/index')->where('items.data.0.member.name', 'Budi')->where('items.data.0.device.name', 'HP Petugas')
            ->missing('items.data.0.payload')->missing('items.data.0.device.held_token_hash'));
        $this->web($this->admin)->post("/tinjauan/tertahan/{$held->id}/terima", ['reason' => 'Saksi ada'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('attendances', ['member_id' => $this->member->id, 'status' => 'hadir', 'scanned_by' => $this->scanner->id, 'device_id' => $this->deviceId]);
        $this->assertDatabaseHas('held_scans', ['id' => $held->id, 'status' => 'accepted', 'payload' => null, 'reviewed_by' => $this->admin->id]);
        $this->web($this->admin)->post("/tinjauan/tertahan/{$held->id}/batalkan", ['reason' => 'ulang'])->assertStatus(422);
    }

    public function test_reject_and_unacceptable_scan_stays_pending(): void
    {
        $this->revoke();
        $this->upload([$this->item(['scanned_at' => now()->subDay()->toIso8601String()])])->assertOk(); // di luar jendela
        $held = HeldScan::withoutGlobalScopes()->firstOrFail();
        $this->web($this->admin)->post("/tinjauan/tertahan/{$held->id}/terima", ['reason' => 'coba'])->assertSessionHasErrors('reason');
        $this->assertSame('pending', $held->fresh()->status);
        $this->web($this->admin)->post("/tinjauan/tertahan/{$held->id}/batalkan", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->web($this->admin)->post("/tinjauan/tertahan/{$held->id}/batalkan", ['reason' => 'HP hilang'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('held_scans', ['id' => $held->id, 'status' => 'rejected', 'payload' => null]);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_division_admin_and_other_org_cannot_review(): void
    {
        $humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $riset = Division::create(['organization_id' => $this->org->id, 'name' => 'Riset']);
        $this->event->update(['division_id' => $humas->id]);
        $this->revoke();
        $this->upload([$this->item()])->assertOk();
        $held = HeldScan::withoutGlobalScopes()->firstOrFail();
        $da = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $riset->id]);
        $this->web($da)->get('/tinjauan?tab=tertahan')->assertInertia(fn ($p) => $p->has('items.data', 0));
        $this->web($da)->post("/tinjauan/tertahan/{$held->id}/terima", ['reason' => 'coba'])->assertForbidden();
        $own = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $humas->id]);
        $this->web($own)->get('/tinjauan?tab=tertahan')->assertInertia(fn ($p) => $p->has('items.data', 1));
        $foreign = User::factory()->create(['organization_id' => Organization::create(['name' => 'Lain', 'slug' => 'lain'])->id, 'role' => 'super_admin']);
        $this->web($foreign)->post("/tinjauan/tertahan/{$held->id}/terima", ['reason' => 'coba'])->assertNotFound();
        $this->assertSame('pending', HeldScan::withoutGlobalScopes()->find($held->id)->status);
    }

    public function test_role_change_and_disable_hold_scans_too(): void
    {
        $users = app(\App\Services\UserService::class);
        $cases = [
            fn () => app(\App\Services\UserService::class)->update($this->admin, $this->scanner->fresh(), ['role' => 'member']),
            fn () => $users->setDisabled($this->admin, $this->scanner->fresh(), true),
        ];
        foreach ($cases as $i => $end) {
            $this->app['auth']->forgetGuards();
            $this->scanner->fresh()->update(['role' => 'scanner', 'disabled_at' => null]);
            $login = $this->withoutToken()->postJson('/api/auth/login', ['email' => $this->scanner->email, 'password' => 'secret123', 'device_uuid' => "hp-$i", 'device_name' => 'HP'])->assertOk();
            $this->actingAs($this->admin);
            $end();
            $this->upload([$this->item()], $login['token'])->assertOk()->assertJsonPath('results.0.status', 'held');
            $this->assertDatabaseHas('held_scans', ['device_id' => $login['device_id'], 'user_id' => $this->scanner->id]);
        }
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_next_login_on_the_device_closes_the_old_token_and_owner_stays_on_the_scan(): void
    {
        app(\App\Services\HeldScanService::class)->signOut($this->scanner);
        $this->upload([$this->item()])->assertOk();
        $other = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'scanner', 'password' => 'secret123']);
        $this->app['auth']->forgetGuards();
        $this->withoutToken()->postJson('/api/auth/login', ['email' => $other->email, 'password' => 'secret123', 'device_uuid' => 'dev-1', 'device_name' => 'HP'])->assertOk();
        $this->upload([$this->item()])->assertForbidden();

        $held = HeldScan::withoutGlobalScopes()->sole();
        $this->web($this->admin)->post("/tinjauan/tertahan/{$held->id}/terima", ['reason' => 'sah'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendances', ['member_id' => $this->member->id, 'scanned_by' => $this->scanner->id]);
    }

    public function test_password_reset_does_not_open_the_upload_path(): void
    {
        $this->actingAs($this->admin);
        app(\App\Services\UserService::class)->resetPassword($this->admin, $this->scanner->fresh());
        $this->upload([$this->item()])->assertForbidden();
    }
}
