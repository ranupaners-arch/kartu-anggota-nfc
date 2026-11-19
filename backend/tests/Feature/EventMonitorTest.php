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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EventMonitorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Term $term;
    private Division $humas;
    private User $admin;
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->event = Event::create([
            'organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => 'Rapat',
            'starts_at' => now(), 'ends_at' => now()->addHours(2), 'checkin_opens_at' => now()->subMinutes(30),
            'late_after' => now()->addMinutes(10), 'checkin_closes_at' => now()->addHour(),
        ]);
    }

    private function member(string $no, ?Division $d = null): Member
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $no, 'name' => "Anggota $no"]);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'division_id' => $d?->id, 'status' => 'active']);

        return $m;
    }

    private function attend(Member $m, string $status, int $minutesAgo): Attendance
    {
        return Attendance::create(['event_id' => $this->event->id, 'session_id' => $this->event->defaultSession()->id,
            'member_id' => $m->id, 'status' => $status, 'source' => 'nfc', 'scanned_at' => now()->subMinutes($minutesAgo)]);
    }

    public function test_monitor_shows_live_counts_latest_scans_and_missing(): void
    {
        $a = $this->member('A', $this->humas);
        $b = $this->member('B');
        $c = $this->member('C');
        $this->member('D');
        $this->attend($a, 'hadir', 10);
        $this->attend($b, 'telat', 2);
        ExcuseRequest::create(['event_id' => $this->event->id, 'member_id' => $c->id, 'type' => 'sakit', 'reason' => 'x', 'status' => 'approved']);
        Attendance::create(['event_id' => $this->event->id, 'session_id' => $this->event->defaultSession()->id, 'member_id' => $c->id, 'status' => 'sakit']);

        $this->actingAs($this->admin)->get(route('events.monitor', $this->event))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('events/monitor')
            ->where('stats.target', 4)->where('stats.present', 2)->where('stats.hadir', 1)->where('stats.telat', 1)
            ->where('stats.excused', 1)->where('stats.missing', 1)->where('stats.rate', 50)
            ->has('latest', 2)->where('latest.0.member.name', 'Anggota B')->where('latest.1.member.division', 'Humas')
            ->where('missing.total', 1)->where('missing.people.0.name', 'Anggota D')
            ->has('serverTime'));
    }

    public function test_monitor_charts_arrivals_per_time_bin_and_rate_per_division(): void
    {
        $a = $this->member('A', $this->humas);
        $b = $this->member('B');
        $c = $this->member('C');
        $this->member('D');
        $this->attend($a, 'hadir', 10);
        $this->attend($b, 'telat', 2);
        $this->attend($c, 'alpa', 1); // bukan kedatangan

        // Check-in dibuka 30 menit lalu → per 2 menit: 15 batang penuh + 1 batang berjalan (≤ 24).
        $this->actingAs($this->admin)->get(route('events.monitor', $this->event))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('arrivals.bin_minutes', 2)
            ->has('arrivals.counts', 16)
            ->where('arrivals.counts.10', 1)->where('arrivals.counts.14', 1)
            ->where('arrivals.counts', fn ($c) => collect($c)->sum() === 2)
            ->where('divisions.0.division', 'Tanpa divisi')->where('divisions.0.present', 1)->where('divisions.0.total', 3)
            ->where('divisions.1.division', 'Humas')->where('divisions.1.rate', fn ($r) => (float) $r === 100.0));
    }

    public function test_polling_partial_reload_returns_only_requested_props(): void
    {
        $this->member('A');
        $first = $this->actingAs($this->admin)->get(route('events.monitor', $this->event));
        $version = $first->viewData('page')['version'];

        $res = $this->actingAs($this->admin)->get(route('events.monitor', $this->event), [
            'X-Inertia' => 'true', 'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'events/monitor', 'X-Inertia-Partial-Data' => 'stats,serverTime',
        ])->assertOk();

        $props = $res->json('props');
        $this->assertArrayHasKey('stats', $props);
        $this->assertArrayHasKey('serverTime', $props);
        $this->assertArrayNotHasKey('latest', $props);
        $this->assertArrayNotHasKey('missing', $props);
        $this->assertArrayNotHasKey('event', $props);
    }

    public function test_division_admin_access_follows_event_division(): void
    {
        $inti = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
        $humasAdmin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $this->humas->id]);
        $intiEvent = Event::create($this->event->only(['organization_id', 'term_id', 'starts_at', 'ends_at', 'checkin_opens_at', 'late_after', 'checkin_closes_at'])
            + ['title' => 'Rapat Inti', 'division_id' => $inti->id]);

        $this->actingAs($humasAdmin)->get(route('events.monitor', $this->event))->assertOk(); // kegiatan umum
        $this->actingAs($humasAdmin)->get(route('events.monitor', $intiEvent))->assertForbidden();
    }

    public function test_monitor_photo_only_for_participants_of_that_event(): void
    {
        $inti = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
        $this->event->targets()->create(['division_id' => $this->humas->id]); // hanya Humas yang jadi peserta
        $participant = $this->member('P', $this->humas);
        $outsider = $this->member('O', $inti);
        foreach ([$participant, $outsider] as $m) {
            $this->actingAs($this->admin)->post("/anggota/{$m->id}/foto", ['photo' => UploadedFile::fake()->image('f.jpg', 300, 300)])->assertRedirect();
        }
        $this->attend($participant, 'hadir', 1);

        $url = $this->actingAs($this->admin)->get(route('events.monitor', $this->event))->viewData('page')['props']['latest'][0]['member']['photo_url'];
        $this->assertStringContainsString("/kegiatan/{$this->event->id}/monitor/foto/{$participant->id}", $url);
        $this->actingAs($this->admin)->get($url)->assertOk();
        $this->actingAs($this->admin)->get(route('events.monitor.photo', [$this->event, $outsider]))->assertNotFound();

        $org2 = Organization::create(['name' => 'Lain', 'slug' => 'lain']);
        $admin2 = User::factory()->create(['organization_id' => $org2->id, 'role' => 'super_admin']);
        $this->actingAs($admin2)->get($url)->assertNotFound();
    }
}
