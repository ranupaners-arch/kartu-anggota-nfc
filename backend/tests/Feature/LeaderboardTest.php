<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Services\PrivacyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = $this->newTerm($this->org);
        foreach (['Ani', 'Budi', 'Cici', 'Dodi'] as $n) {
            $this->users[$n] = $this->user($n, $this->org, $this->term);
        }
    }

    private function newTerm(Organization $org, bool $current = true): Term
    {
        return Term::create(['organization_id' => $org->id, 'name' => Str::random(5), 'starts_on' => now()->subYear()->toDateString(), 'ends_on' => now()->addYear()->toDateString(), 'is_current' => $current]);
    }

    private function user(string $name, Organization $org, Term $term, string $status = 'active'): User
    {
        $m = Member::create(['organization_id' => $org->id, 'member_no' => $name, 'name' => $name]);
        Membership::create(['member_id' => $m->id, 'term_id' => $term->id, 'status' => $status]);

        return User::factory()->create(['organization_id' => $org->id, 'role' => 'member', 'member_id' => $m->id]);
    }

    private function attend(string $name, array $statuses, ?Term $term = null, bool $void = false): void
    {
        foreach ($statuses as $s) {
            $at = now()->subDay();
            $e = Event::create(['organization_id' => $this->org->id, 'term_id' => ($term ?? $this->term)->id, 'title' => 'K', 'starts_at' => $at, 'ends_at' => $at->copy()->addHour(),
                'checkin_opens_at' => $at->copy()->subMinutes(10), 'late_after' => $at->copy()->addMinutes(10), 'checkin_closes_at' => $at->copy()->addMinutes(30)]);
            Attendance::create(['event_id' => $e->id, 'session_id' => $e->defaultSession()->id, 'member_id' => $this->users[$name]->member_id, 'status' => $s, 'source' => 'nfc', 'voided_at' => $void ? now() : null]);
        }
    }

    private function as(string $name): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->users[$name]->fresh(), 'sanctum');
    }

    private function join(string ...$names): void
    {
        foreach ($names as $n) {
            $this->as($n)->putJson('/api/me/leaderboard', ['opt_in' => true])->assertOk()->assertJson(['opted_in' => true]);
        }
    }

    public function test_hidden_by_default_and_own_rank_is_always_available(): void
    {
        $this->attend('Ani', ['hadir', 'hadir']);
        $this->attend('Budi', ['hadir']);
        $r = $this->as('Budi')->getJson('/api/me/leaderboard')->assertOk()->json();
        $this->assertSame([false, [], ['points' => 10, 'rank' => 2, 'total' => 4]], [$r['opted_in'], $r['top'], $r['me']]);
    }

    public function test_only_opted_in_members_are_listed_and_ranks_do_not_reveal_hidden_ones(): void
    {
        $this->attend('Ani', ['hadir', 'hadir', 'hadir']); // tersembunyi, poin tertinggi
        $this->attend('Budi', ['hadir', 'hadir']);
        $this->attend('Cici', ['hadir', 'hadir']);
        $this->attend('Dodi', ['telat']);
        $this->join('Dodi', 'Cici', 'Budi');
        $r = $this->as('Cici')->getJson('/api/me/leaderboard')->json();
        $this->assertSame([[1, 'Budi', 20, false], [1, 'Cici', 20, true], [3, 'Dodi', 6, false]],
            array_map(fn ($t) => [$t['rank'], $t['name'], $t['points'], $t['is_me']], $r['top']));
        $this->assertSame(2, $r['me']['rank']);
        $this->assertStringNotContainsString('Ani', json_encode($r));
        $this->assertSame(['rank', 'name', 'division', 'points', 'is_me'], array_keys($r['top'][0]));

        $this->as('Budi')->putJson('/api/me/leaderboard', ['opt_in' => false])->assertOk()->assertJson(['opted_in' => false]);
        $this->assertNotContains('Budi', array_column($this->as('Cici')->getJson('/api/me/leaderboard')->json('top'), 'name'));
    }

    public function test_points_match_achievements_and_ignore_void_and_other_terms(): void
    {
        $this->attend('Ani', ['hadir', 'telat', 'izin', 'sakit', 'alpa']);
        $this->attend('Ani', ['hadir'], void: true);
        $this->attend('Ani', ['hadir'], $this->newTerm($this->org, false));
        // Multi-sesi: hanya nilai terbaik kegiatan itu.
        $a = Attendance::where('member_id', $this->users['Ani']->member_id)->where('status', 'telat')->firstOrFail();
        $s = $a->event->sessions()->create(['title' => 'Sesi 2', 'starts_at' => now(), 'ends_at' => now()->addHour()]);
        Attendance::create(['event_id' => $a->event_id, 'session_id' => $s->id, 'member_id' => $a->member_id, 'status' => 'hadir', 'source' => 'nfc']);

        $expected = $this->as('Ani')->getJson('/api/me/achievements')->json('points');
        $this->assertSame(24, $expected);
        $this->assertSame($expected, $this->as('Ani')->getJson('/api/me/leaderboard')->json('me.points'));
    }

    public function test_inactive_members_and_other_organizations_are_excluded(): void
    {
        $this->users['Pelamar'] = $this->user('Pelamar', $this->org, $this->term, 'applicant');
        $this->attend('Budi', ['hadir']);
        $this->join('Budi');
        $r = $this->as('Pelamar')->getJson('/api/me/leaderboard')->assertOk()->json();
        $this->assertSame([null, []], [$r['me'], $r['top']]);

        $org = Organization::create(['name' => 'Lain', 'slug' => 'lain']);
        $this->users['Luar'] = $this->user('Luar', $org, $this->newTerm($org));
        $r = $this->as('Luar')->getJson('/api/me/leaderboard')->json();
        $this->assertSame([1, []], [$r['me']['total'], $r['top']]);
    }

    public function test_anonymizing_a_member_withdraws_consent(): void
    {
        $this->join('Dodi');
        $m = Member::find($this->users['Dodi']->member_id);
        $m->memberships()->update(['status' => 'alumni']);
        $this->app['auth']->forgetGuards();
        app(PrivacyService::class)->anonymize($m->fresh(), null, 'uji');
        $this->assertNull($m->fresh()->leaderboard_opt_in_at);
    }

    public function test_portal_shows_board_and_toggles(): void
    {
        $this->attend('Ani', ['hadir']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->users['Ani'], 'web')->put('/saya/peringkat', ['opt_in' => '1'])->assertSessionHas('status');
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->users['Ani']->fresh(), 'web')->get('/saya/riwayat')->assertInertia(fn ($p) => $p->where('leaderboard.opted_in', true)->where('leaderboard.top.0.name', 'Ani'));
    }
}
