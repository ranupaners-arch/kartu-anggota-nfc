<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Card;
use App\Models\Event;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Support\CardPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Waktu dari app dikirim dalam UTC, server produksi Asia/Jakarta: momen yang tersimpan harus sama. */
class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $scanner;

    private User $admin;

    private Event $event;

    private Member $member;

    private string $payload;

    protected function setUp(): void
    {
        parent::setUp();
        config(['attendance.card_keys.k1' => 'test-secret', 'attendance.payload_prefix' => 'card']);
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
        Carbon::setTestNow('2026-11-24T02:00:00Z');
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->scanner = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'scanner']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->event = Event::create(['organization_id' => $this->org->id, 'term_id' => $term->id, 'title' => 'Rapat',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(2), 'checkin_opens_at' => now()->subHour(),
            'late_after' => now()->addMinutes(10), 'checkin_closes_at' => now()->addHour()]);
        $this->member = Member::create(['organization_id' => $this->org->id, 'member_no' => '1', 'name' => 'Budi']);
        Membership::create(['member_id' => $this->member->id, 'term_id' => $term->id, 'status' => 'active']);
        $card = Card::create(['organization_id' => $this->org->id, 'public_id' => (string) Str::ulid(), 'key_id' => 'k1', 'member_id' => $this->member->id, 'status' => 'active', 'issued_at' => now()]);
        $this->payload = CardPayload::make($card);
    }

    private function stored(): string
    {
        return Attendance::withoutGlobalScopes()->sole()->scanned_at->utc()->toIso8601ZuluString();
    }

    public function test_online_scan_keeps_the_moment(): void
    {
        $this->actingAs($this->scanner, 'sanctum')->postJson("/api/events/{$this->event->id}/scan", ['payload' => $this->payload, 'scanned_at' => '2026-11-24T01:58:00Z'])->assertJson(['status' => 'ok', 'attendance_status' => 'hadir']);
        $this->assertSame('2026-11-24T01:58:00Z', $this->stored());
    }

    public function test_offline_batch_keeps_the_moment_in_any_offset(): void
    {
        $this->actingAs($this->scanner, 'sanctum')->postJson("/api/events/{$this->event->id}/scan/batch", ['scans' => [
            ['payload' => $this->payload, 'scanned_at' => '2026-11-24T10:30:00+09:00', 'client_uuid' => (string) Str::uuid()],
        ]])->assertJsonPath('results.0.status', 'ok');
        $this->assertSame('2026-11-24T01:30:00Z', $this->stored());
    }

    public function test_event_created_and_changed_through_the_api_keeps_the_moment(): void
    {
        $times = ['starts_at' => '2026-11-25T01:00:00Z', 'ends_at' => '2026-11-25T03:00:00Z', 'checkin_opens_at' => '2026-11-25T00:30:00Z', 'late_after' => '2026-11-25T01:10:00Z', 'checkin_closes_at' => '2026-11-25T02:00:00Z'];
        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/events', ['title' => 'Baru', 'is_mandatory' => false, 'rsvp_enabled' => true, 'rsvp_deadline' => '2026-11-24T12:00:00Z'] + $times)->assertCreated()->json('id');
        $e = Event::findOrFail($id);
        $this->assertSame('2026-11-25T01:00:00Z', $e->starts_at->utc()->toIso8601ZuluString());
        $this->assertSame('2026-11-24T12:00:00Z', $e->rsvp_deadline->utc()->toIso8601ZuluString());
        $this->assertSame('2026-11-25T00:30:00Z', $e->sessions()->sole()->checkin_opens_at->utc()->toIso8601ZuluString());
        $this->patchJson("/api/events/$id", ['ends_at' => '2026-11-25T04:00:00Z', 'starts_at' => '2026-11-25T01:00:00Z'])->assertOk();
        $this->assertSame('2026-11-25T04:00:00Z', $e->fresh()->ends_at->utc()->toIso8601ZuluString());
    }

    public function test_dashboard_input_without_offset_is_local_time(): void
    {
        $this->withoutVite();
        $this->actingAs($this->admin, 'web')->post('/kegiatan', ['title' => 'Lokal', 'starts_at' => '2026-11-25T08:00', 'ends_at' => '2026-11-25T10:00', 'checkin_opens_at' => '2026-11-25T07:30',
            'late_after' => '2026-11-25T08:10', 'checkin_closes_at' => '2026-11-25T09:00'])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-25T01:00:00Z', Event::where('title', 'Lokal')->sole()->starts_at->utc()->toIso8601ZuluString());
    }

    public function test_repair_command_fixes_only_shifted_rows_and_is_a_dry_run_by_default(): void
    {
        $make = function (string $no, string $scanned, array $extra = []) {
            $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $no, 'name' => $no]);

            return Attendance::create($extra + ['event_id' => $this->event->id, 'session_id' => $this->event->defaultSession()->id, 'member_id' => $m->id,
                'status' => 'hadir', 'source' => 'nfc', 'scanned_at' => Carbon::parse($scanned)->setTimezone('Asia/Jakarta'), 'synced_at' => now()]);
        };
        $shifted = $make('A', '2026-11-23T18:58:00Z');
        $right = $make('B', '2026-11-24T01:58:00Z');
        $override = $make('C', '2026-11-23T18:58:00Z', ['override' => true]);
        $manual = $make('D', '2026-11-23T18:58:00Z', ['source' => 'manual']);
        $odd = $make('E', '2026-11-20T01:00:00Z');
        $at = fn ($a) => $a->fresh()->scanned_at->utc()->toIso8601ZuluString();

        $this->artisan('scan:perbaiki-waktu')->expectsOutputToContain('simulasi): 1. Tidak disentuh karena tidak pasti: 1')->assertSuccessful();
        $this->assertSame('2026-11-23T18:58:00Z', $at($shifted));
        $this->artisan('scan:perbaiki-waktu --terapkan')->assertSuccessful();
        $this->assertSame(['2026-11-24T01:58:00Z', '2026-11-24T01:58:00Z'], [$at($shifted), $at($right)]);
        $this->assertSame(['2026-11-23T18:58:00Z', '2026-11-23T18:58:00Z', '2026-11-20T01:00:00Z'], [$at($override), $at($manual), $at($odd)]);
        $this->artisan('scan:perbaiki-waktu --terapkan')->expectsOutputToContain('Diperbaiki: 0')->assertSuccessful(); // aman diulang
    }

    public function test_repair_detects_online_scans_inside_a_long_window(): void
    {
        $this->event->update(['checkin_opens_at' => now()->subHours(12), 'starts_at' => now()->subHours(12)]);
        $a = Attendance::create(['event_id' => $this->event->id, 'session_id' => $this->event->defaultSession()->id, 'member_id' => $this->member->id,
            'status' => 'hadir', 'source' => 'nfc', 'scanned_at' => now()->subHours(7)]);
        $this->artisan('scan:perbaiki-waktu --terapkan')->expectsOutputToContain('Diperbaiki: 1')->assertSuccessful();
        $this->assertTrue($a->fresh()->scanned_at->equalTo(now()));
    }
}
