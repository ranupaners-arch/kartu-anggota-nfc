<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EventCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private User $admin;

    private Division $humas;

    private Division $inti;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->inti = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
    }

    private function event(string $title, string $start, ?Division $d = null): Event
    {
        $s = Carbon::parse($start, 'Asia/Jakarta');

        return Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => $title, 'division_id' => $d?->id,
            'starts_at' => $s, 'ends_at' => $s->copy()->addHours(2), 'checkin_opens_at' => $s->copy()->subMinutes(30),
            'late_after' => $s->copy()->addMinutes(10), 'checkin_closes_at' => $s->copy()->addHour()]);
    }

    public function test_month_grid_includes_events_in_visible_weeks_only(): void
    {
        $this->event('Rapat awal', '2026-11-02 09:00');
        $this->event('Akhir Oktober', '2026-10-27 09:00'); // Oktober, tapi masih di minggu pertama kotak November
        $this->event('Desember', '2026-12-20 09:00'); // di luar kotak

        // November 2026: 1 Nov = Minggu → kotak mulai Senin 26 Okt, berakhir Minggu 6 Des.
        $this->actingAs($this->admin)->get('/kegiatan?tab=kalender&bulan=2026-11')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('events/index')->where('tab', 'kalender')
            ->where('calendar.month', '2026-11')->where('calendar.from', '2026-10-26')->where('calendar.to', '2026-12-06')
            ->has('calendar.events', 2)->where('calendar.events.0.title', 'Akhir Oktober')->where('calendar.events.1.title', 'Rapat awal')
            ->has('allDivisions', 2));
    }

    public function test_invalid_month_falls_back_to_current_and_division_admin_sees_own_events(): void
    {
        $da = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $this->humas->id]);
        $this->event('Humas', now()->setTime(9, 0)->toDateTimeString(), $this->humas);
        $this->event('Inti', now()->setTime(9, 0)->toDateTimeString(), $this->inti);
        $this->event('Umum', now()->setTime(13, 0)->toDateTimeString());

        $this->actingAs($da)->get('/kegiatan?tab=kalender&bulan=bukan-bulan')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('calendar.month', now()->format('Y-m'))
            ->where('calendar.events', fn ($e) => collect($e)->pluck('title')->sort()->values()->all() === ['Humas', 'Umum']));
    }
}
