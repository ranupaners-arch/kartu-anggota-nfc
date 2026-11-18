<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\ExcuseRequest;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Services\CloseEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Kegiatan multi-sesi: tiap sesi = satu kehadiran dengan jendela check-in sendiri. */
class MultiSessionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Term $term;
    private User $admin;
    private User $scanner;
    private Event $event;
    private EventSession $day1;
    private EventSession $day2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['attendance.card_keys.k1' => 'test-secret', 'attendance.payload_prefix' => 'card']);
        $this->travelTo(Carbon::parse('2026-11-20 08:00:00'));

        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->scanner = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'scanner']);

        // Pelatihan 2 hari: hari 1 mulai 08.00 (buka 07.30, telat 08.10, tutup 09.00); hari 2 mulai besok 13.00.
        $this->event = Event::create([
            'organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => 'Pelatihan',
            'starts_at' => now(), 'ends_at' => now()->addHours(4),
            'checkin_opens_at' => now()->subMinutes(30), 'late_after' => now()->addMinutes(10),
            'checkin_closes_at' => now()->addHour(), 'is_mandatory' => true,
        ]);
        $this->day1 = $this->event->defaultSession();
        $this->actingAs($this->admin)->post("/kegiatan/{$this->event->id}/sesi", ['title' => 'Hari 2', 'starts_at' => '2026-11-21 13:00:00'])->assertSessionHasNoErrors();
        $this->day2 = $this->event->sessions()->where('title', 'Hari 2')->firstOrFail();
    }

    private function member(string $no): Member
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $no, 'name' => "Anggota $no"]);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'status' => 'active']);

        return $m;
    }

    private function card(Member $m): string
    {
        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/cards/init')->assertCreated();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/cards/{$res['id']}/assign", ['member_id' => $m->id])->assertOk();

        return $res['payload'];
    }

    private function scan(string $payload, array $extra = [])
    {
        return $this->actingAs($this->scanner, 'sanctum')->postJson("/api/events/{$this->event->id}/scan", ['payload' => $payload] + $extra);
    }

    public function test_session_window_is_shifted_from_event_and_resynced_on_change(): void
    {
        $this->assertSame(
            ['2026-11-20 07:30', '2026-11-20 08:10', '2026-11-20 09:00'],
            [$this->day1->checkin_opens_at->format('Y-m-d H:i'), $this->day1->late_after->format('Y-m-d H:i'), $this->day1->checkin_closes_at->format('Y-m-d H:i')],
        );
        $this->assertSame(
            ['2026-11-21 12:30', '2026-11-21 13:10', '2026-11-21 14:00'],
            [$this->day2->checkin_opens_at->format('Y-m-d H:i'), $this->day2->late_after->format('Y-m-d H:i'), $this->day2->checkin_closes_at->format('Y-m-d H:i')],
        );

        $this->event->update(['late_after' => now()->addMinutes(20)]); // batas telat kegiatan diperlonggar
        $this->assertSame('13:20', $this->day2->fresh()->late_after->format('H:i'));
        $this->assertSame('08:20', $this->day1->fresh()->late_after->format('H:i'));

        $this->actingAs($this->admin)->post("/kegiatan/{$this->event->id}/sesi", ['title' => 'Tanpa jam'])->assertSessionHasErrors('starts_at');
    }

    public function test_scan_goes_to_the_session_open_now_with_its_own_late_rule(): void
    {
        $payload = $this->card($this->member('1'));
        $this->scan($payload)->assertJson(['status' => 'ok', 'attendance_status' => 'hadir', 'session' => ['id' => $this->day1->id]]);
        $this->scan($payload)->assertJson(['status' => 'duplicate', 'message' => 'Sudah absen di sesi ini.']);

        $this->travelTo(Carbon::parse('2026-11-21 13:15:00')); // hari 2, lewat batas telat 13.10
        $this->scan($payload)->assertJson(['status' => 'ok', 'attendance_status' => 'telat', 'session' => ['id' => $this->day2->id]]);
        $this->assertSame(2, Attendance::count());

        $this->travelTo(Carbon::parse('2026-11-21 10:00:00')); // di antara sesi
        $this->scan($this->card($this->member('2')))->assertJson(['status' => 'outside_window']);
    }

    public function test_explicit_session_must_belong_to_event_and_follows_its_window(): void
    {
        $other = Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => 'Lain',
            'starts_at' => now(), 'ends_at' => now()->addHour(), 'checkin_opens_at' => now()->subHour(), 'late_after' => now(), 'checkin_closes_at' => now()->addHour()]);
        $payload = $this->card($this->member('1'));
        $this->scan($payload, ['session_id' => $other->defaultSession()->id])->assertJson(['status' => 'invalid']);
        $this->scan($payload, ['session_id' => $this->day2->id])->assertJson(['status' => 'outside_window']); // hari 2 belum dibuka

        // Offline (batch): scan hari 2 yang dikirim belakangan tetap masuk sesi hari 2.
        $this->travelTo(Carbon::parse('2026-11-21 16:00:00'));
        $this->actingAs($this->scanner, 'sanctum')->postJson("/api/events/{$this->event->id}/scan/batch", ['scans' => [
            ['payload' => $payload, 'client_uuid' => (string) \Illuminate\Support\Str::uuid(), 'scanned_at' => Carbon::parse('2026-11-21 12:50:00')->toIso8601String(), 'session_id' => $this->day2->id],
        ]])->assertJsonPath('results.0.status', 'ok')->assertJsonPath('results.0.attendance_status', 'hadir');
    }

    public function test_ongoing_lists_and_current_session_follow_sessions(): void
    {
        $this->travelTo(Carbon::parse('2026-11-21 12:45:00')); // jendela kegiatan (hari 1) sudah lewat; hari 2 buka
        $this->actingAs($this->scanner, 'sanctum')->getJson('/api/events?status=ongoing')->assertOk()
            ->assertJsonPath('data.0.id', $this->event->id)->assertJsonPath('data.0.current_session_id', $this->day2->id)
            ->assertJsonCount(2, 'data.0.sessions');
        $this->actingAs($this->admin)->get('/')->assertInertia(fn ($p) => $p->has('ongoing', 1));

        $this->travelTo(Carbon::parse('2026-11-21 10:00:00'));
        $this->actingAs($this->scanner, 'sanctum')->getJson('/api/events?status=ongoing')->assertJsonCount(0, 'data');
    }

    public function test_close_marks_each_held_session_and_auto_close_waits_for_last_session(): void
    {
        $a = $this->member('1');
        $b = $this->member('2');
        $c = $this->member('3');
        ExcuseRequest::create(['event_id' => $this->event->id, 'member_id' => $c->id, 'type' => 'sakit', 'reason' => 'x', 'status' => 'approved']);
        $this->scan($this->card($a))->assertJson(['status' => 'ok']);

        $this->travelTo(Carbon::parse('2026-11-20 20:00:00'));
        $this->artisan('events:close')->assertSuccessful();
        $this->assertNull($this->event->fresh()->closed_at); // hari 2 belum lewat

        $this->travelTo(Carbon::parse('2026-11-22 00:00:00'));
        $this->artisan('events:close')->assertSuccessful();
        $this->assertNotNull($this->event->fresh()->closed_at);

        $status = fn (Member $m, EventSession $s) => Attendance::where('member_id', $m->id)->where('session_id', $s->id)->value('status');
        $this->assertSame(['hadir', 'alpa', 'alpa', 'alpa', 'sakit', 'sakit'],
            [$status($a, $this->day1), $status($a, $this->day2), $status($b, $this->day1), $status($b, $this->day2), $status($c, $this->day1), $status($c, $this->day2)]);

        // Rekap periode: tiap sesi satu kehadiran.
        $row = collect($this->actingAs($this->admin, 'sanctum')->getJson("/api/reports/terms/{$this->term->id}")->json('rows'))->firstWhere('member_no', '1');
        $this->assertSame([1, 1, 2, 50], [$row['hadir'], $row['alpa'], $row['total'], (int) $row['attendance_rate']]);
    }

    public function test_closing_early_skips_sessions_not_yet_opened(): void
    {
        $this->member('1');
        app(CloseEvent::class)->handle($this->event);
        $this->assertSame(1, Attendance::where('session_id', $this->day1->id)->count());
        $this->assertSame(0, Attendance::where('session_id', $this->day2->id)->count());
    }

    public function test_dashboard_report_and_monitor_per_session(): void
    {
        $a = $this->member('1');
        $this->scan($this->card($a));
        $this->actingAs($this->admin)->get("/kegiatan/{$this->event->id}?tab=hadir&sesi={$this->day2->id}")
            ->assertInertia(fn ($p) => $p->where('report.session.id', $this->day2->id)->where('report.summary.by_status.belum_absen', 1)->has('sessions', 2));
        $this->actingAs($this->admin)->get("/kegiatan/{$this->event->id}?tab=hadir")
            ->assertInertia(fn ($p) => $p->where('report.session.id', $this->day1->id)->where('report.summary.by_status.hadir', 1));

        $this->actingAs($this->admin)->get("/kegiatan/{$this->event->id}/monitor")
            ->assertInertia(fn ($p) => $p->where('session.id', $this->day1->id)->where('stats.present', 1)->has('sessions', 2));
        $this->travelTo(Carbon::parse('2026-11-21 12:45:00'));
        $this->actingAs($this->admin)->get("/kegiatan/{$this->event->id}/monitor") // ikut sesi yang sedang buka
            ->assertInertia(fn ($p) => $p->where('session.id', $this->day2->id)->where('stats.present', 0)->has('latest', 0));
    }

    public function test_member_app_shows_sessions_and_allows_excuse_for_missed_sessions(): void
    {
        $m = $this->member('1');
        $u = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $m->id]);
        $this->scan($this->card($m));

        $this->actingAs($u, 'sanctum')->getJson('/api/me/events')->assertJsonPath('data.0.sessions', 2)
            ->assertJsonPath('data.0.present_sessions', 1)->assertJsonPath('data.0.can_request_excuse', true);
        $this->actingAs($u, 'sanctum')->postJson('/api/excuses', ['event_id' => $this->event->id, 'type' => 'izin', 'reason' => 'Hari 2 kuliah'])->assertCreated();
    }

    public function test_event_export_has_a_row_per_session(): void
    {
        \Maatwebsite\Excel\Facades\Excel::fake();
        $this->member('1');
        $export = \App\Models\Export::create(['organization_id' => $this->org->id, 'user_id' => $this->admin->id, 'report' => 'event', 'format' => 'xlsx', 'params' => ['event_id' => $this->event->id]]);
        (new \App\Jobs\GenerateExport($export->id))->handle(app(\App\Services\ReportService::class));

        \Maatwebsite\Excel\Facades\Excel::assertStored($export->fresh()->path, 'local', function (\App\Exports\ReportSheet $sheet) {
            return $sheet->headings()[0] === 'Sesi' && array_column($sheet->array(), 0) === ['Pelatihan', 'Hari 2'];
        });
    }
}
