<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Division;
use App\Models\Event;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Statistik kehadiran & peringatan anggota yang perlu perhatian. */
class StatsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private Division $humas;

    private Division $inti;

    private User $admin;

    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->inti = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        for ($i = 1; $i <= 5; $i++) {
            $s = now()->subDays(10 - $i);
            $this->events[] = Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => "Rapat $i",
                'starts_at' => $s, 'ends_at' => $s->copy()->addHour(), 'checkin_opens_at' => $s->copy()->subMinutes(30),
                'late_after' => $s->copy()->addMinutes(10), 'checkin_closes_at' => $s->copy()->addHour(), 'closed_at' => $s->copy()->addHours(2)]);
        }
    }

    /** Anggota dengan status per kegiatan (urut Rapat 1..5). */
    private function member(string $name, Division $d, array $statuses): Member
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $name, 'name' => $name]);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'division_id' => $d->id, 'status' => 'active']);
        foreach ($statuses as $i => $st) {
            Attendance::create(['event_id' => $this->events[$i]->id, 'session_id' => $this->events[$i]->defaultSession()->id, 'member_id' => $m->id, 'status' => $st]);
        }

        return $m;
    }

    public function test_trend_divisions_and_at_risk_on_home(): void
    {
        $this->member('Rajin', $this->humas, ['hadir', 'hadir', 'telat', 'hadir', 'hadir']);
        $this->member('Bolos', $this->humas, ['hadir', 'hadir', 'alpa', 'alpa', 'alpa']);   // 3x alpa berturut-turut
        $this->member('Jarang', $this->inti, ['hadir', 'alpa', 'izin', 'alpa', 'hadir']);    // 40% < 60%
        $this->member('Pulih', $this->inti, ['alpa', 'alpa', 'alpa', 'hadir', 'hadir']);     // streak putus, 40%

        $this->actingAs($this->admin)->get('/')->assertInertia(fn (Assert $p) => $p
            ->has('stats.trend', 5)
            ->where('stats.trend.0.title', 'Rapat 1')->where('stats.trend.0.rate', fn ($v) => (float) $v === 75.0) // Pulih alpa
            ->where('stats.trend.2.rate', fn ($v) => (float) $v === 25.0) // telat, alpa, izin, alpa
            ->where('stats.divisions.0.division', 'Inti') // terendah dulu
            ->where('stats.atRisk.0.name', 'Bolos')->where('stats.atRisk.0.alpa_streak', 3)
            ->where('stats.atRisk', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['Bolos', 'Jarang', 'Pulih']));
    }

    public function test_compares_with_previous_term_overall_and_per_division(): void
    {
        $this->member('Rajin', $this->humas, ['hadir', 'hadir', 'hadir', 'hadir', 'hadir']); // Humas 100%
        $this->member('Jarang', $this->inti, ['hadir', 'alpa', 'alpa', 'alpa', 'hadir']);   // Inti 40%

        // Belum ada periode sebelumnya → tidak ada perbandingan.
        $this->actingAs($this->admin)->get('/')->assertInertia(fn (Assert $p) => $p->where('stats.comparison', null));

        $old = Term::create(['organization_id' => $this->org->id, 'name' => '2025', 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31', 'is_current' => false]);
        $s = now()->subYear();
        $e = Event::create(['organization_id' => $this->org->id, 'term_id' => $old->id, 'title' => 'Rapat lama', 'starts_at' => $s, 'ends_at' => $s->copy()->addHour(),
            'checkin_opens_at' => $s->copy()->subMinutes(30), 'late_after' => $s->copy()->addMinutes(10), 'checkin_closes_at' => $s->copy()->addHour(), 'closed_at' => $s->copy()->addHours(2)]);
        foreach (['Rajin' => [$this->humas, 'alpa'], 'Jarang' => [$this->inti, 'hadir']] as $no => [$d, $st]) {
            $m = Member::where('member_no', $no)->first();
            Membership::create(['member_id' => $m->id, 'term_id' => $old->id, 'division_id' => $d->id, 'status' => 'active']);
            Attendance::create(['event_id' => $e->id, 'session_id' => $e->defaultSession()->id, 'member_id' => $m->id, 'status' => $st]);
        }

        $this->actingAs($this->admin)->get('/')->assertInertia(fn (Assert $p) => $p
            ->where('stats.comparison.current.name', '2026')->where('stats.comparison.current.rate', fn ($v) => (float) $v === 70.0) // 7/10
            ->where('stats.comparison.previous.name', '2025')->where('stats.comparison.previous.rate', fn ($v) => (float) $v === 50.0) // 1/2
            ->where('stats.comparison.divisions.0.division', 'Humas')
            ->where('stats.comparison.divisions.0.current', fn ($v) => (float) $v === 100.0)->where('stats.comparison.divisions.0.previous', fn ($v) => (float) $v === 0.0)
            ->where('stats.comparison.divisions.1.current', fn ($v) => (float) $v === 40.0)->where('stats.comparison.divisions.1.previous', fn ($v) => (float) $v === 100.0));

        // Admin divisi hanya melihat divisinya.
        $da = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $this->inti->id]);
        $this->actingAs($da)->get('/')->assertInertia(fn (Assert $p) => $p
            ->has('stats.comparison.divisions', 1)->where('stats.comparison.divisions.0.division', 'Inti')
            ->where('stats.comparison.current.rate', fn ($v) => (float) $v === 40.0)->where('stats.comparison.previous.rate', fn ($v) => (float) $v === 100.0));
    }

    public function test_division_admin_sees_only_own_division(): void
    {
        $this->member('Bolos', $this->humas, ['alpa', 'alpa', 'alpa', 'alpa', 'alpa']);
        $this->member('Jarang', $this->inti, ['alpa', 'alpa', 'alpa', 'alpa', 'alpa']);
        $da = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $this->humas->id]);
        $this->actingAs($da)->get('/')->assertInertia(fn (Assert $p) => $p
            ->has('stats.atRisk', 1)->where('stats.atRisk.0.name', 'Bolos')->has('stats.divisions', 1)->where('stats.divisions.0.division', 'Humas'));
    }

    public function test_member_sees_own_warning_in_app_history(): void
    {
        $m = $this->member('Bolos', $this->humas, ['hadir', 'hadir', 'alpa', 'alpa', 'alpa']);
        $u = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $m->id]);
        $this->actingAs($u, 'sanctum')->getJson('/api/me/history')->assertOk()
            ->assertJsonPath('warning.at_risk', true)->assertJsonPath('warning.alpa_streak', 3);
    }
}
