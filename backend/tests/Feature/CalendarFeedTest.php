<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Event;
use App\Models\EventTarget;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Langganan jadwal .ics per akun. */
class CalendarFeedTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Term $term;
    private Division $humas;
    private Division $inti;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->inti = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
    }

    private function event(string $title, ?Division $owner = null, ?Division $target = null, ?Organization $org = null, ?Term $term = null): Event
    {
        $s = now()->addDays(3)->setTime(9, 0);
        $e = Event::create(['organization_id' => ($org ?? $this->org)->id, 'term_id' => ($term ?? $this->term)->id, 'title' => $title, 'location' => 'Aula, Lt. 2',
            'division_id' => $owner?->id, 'is_mandatory' => true,
            'starts_at' => $s, 'ends_at' => $s->copy()->addHours(2), 'checkin_opens_at' => $s->copy()->subMinutes(30),
            'late_after' => $s->copy()->addMinutes(10), 'checkin_closes_at' => $s->copy()->addHour()]);
        if ($target) {
            EventTarget::create(['event_id' => $e->id, 'division_id' => $target->id]);
        }

        return $e;
    }

    private function memberUser(Division $d): User
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => 'B01', 'name' => 'Budi']);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'division_id' => $d->id, 'status' => 'active']);

        return User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $m->id, 'calendar_token' => str_repeat('a', 48)]);
    }

    public function test_member_feed_lists_only_targeted_events_as_valid_ics(): void
    {
        $this->memberUser($this->humas);
        $this->event('Rapat; umum, semua');
        $this->event('Rapat Humas', target: $this->humas);
        $this->event('Rapat Inti', target: $this->inti);
        $otherOrg = Organization::create(['name' => 'Lain', 'slug' => 'lain']);
        $this->event('Rahasia organisasi lain', org: $otherOrg, term: Term::create(['organization_id' => $otherOrg->id, 'name' => 'x', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]));

        $res = $this->get('/jadwal/'.str_repeat('a', 48).'.ics')->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $ics = $res->getContent();

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('SUMMARY:Rapat\; umum\, semua', $ics);
        $this->assertStringContainsString('SUMMARY:Rapat Humas', $ics);
        $this->assertStringContainsString('LOCATION:Aula\, Lt. 2', $ics);
        $this->assertStringNotContainsString('Rapat Inti', $ics);
        $this->assertStringNotContainsString('Rahasia', $ics);
        // Waktu ditulis dalam UTC (09.00 zona aplikasi; produksi Asia/Jakarta → 02.00Z, CI UTC → 09.00Z).
        $this->assertStringContainsString('DTSTART:'.now()->addDays(3)->setTime(9, 0)->utc()->format('Ymd\THis\Z'), $ics);
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
    }

    public function test_division_admin_feed_shows_own_and_general_events(): void
    {
        User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $this->humas->id, 'calendar_token' => str_repeat('b', 48)]);
        $this->event('Umum');
        $this->event('Milik Humas', owner: $this->humas);
        $this->event('Milik Inti', owner: $this->inti);

        $ics = $this->get('/jadwal/'.str_repeat('b', 48).'.ics')->assertOk()->getContent();
        $this->assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringNotContainsString('Milik Inti', $ics);
    }

    public function test_unknown_token_or_disabled_account_is_not_found(): void
    {
        $u = $this->memberUser($this->humas);
        $this->get('/jadwal/'.str_repeat('z', 48).'.ics')->assertNotFound();
        $u->update(['disabled_at' => now()]);
        $this->get('/jadwal/'.str_repeat('a', 48).'.ics')->assertNotFound();
    }

    public function test_member_gets_stable_url_from_api_and_can_reset_it(): void
    {
        $u = $this->memberUser($this->humas);
        $u->update(['calendar_token' => null]);
        Sanctum::actingAs($u);

        $first = $this->getJson('/api/me/calendar')->assertOk()->json('url');
        $this->assertSame($first, $this->getJson('/api/me/calendar')->json('url'));
        $this->assertMatchesRegularExpression('#/jadwal/[A-Za-z0-9]{48}\.ics$#', $first);
        $this->assertArrayNotHasKey('calendar_token', $u->fresh()->toArray());

        $new = $this->postJson('/api/me/calendar/reset')->assertOk()->json('url');
        $this->assertNotSame($first, $new);
    }

    public function test_staff_resets_link_from_account_page(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin', 'calendar_token' => str_repeat('c', 48)]);
        $this->actingAs($admin)->get('/akun')->assertOk();
        $this->actingAs($admin)->post('/akun/kalender')->assertRedirect()->assertSessionHas('status');
        $this->assertNotSame(str_repeat('c', 48), $admin->fresh()->calendar_token);
    }
}
