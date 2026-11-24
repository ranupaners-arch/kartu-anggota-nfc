<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Division;
use App\Models\Event;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Duplikat kegiatan & kegiatan berulang. */
class DuplicateEventTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private User $admin;

    private Division $humas;

    private Event $src;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-01-20 08:00:00'));
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $s = Carbon::parse('2026-01-31 19:00:00');
        $this->src = Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'division_id' => $this->humas->id, 'title' => 'Rapat Humas', 'type' => 'rapat',
            'location' => 'Sekre', 'is_mandatory' => false, 'starts_at' => $s, 'ends_at' => $s->copy()->addHours(2),
            'checkin_opens_at' => $s->copy()->subMinutes(30), 'late_after' => $s->copy()->addMinutes(10), 'checkin_closes_at' => $s->copy()->addHour()]);
        $this->src->targets()->create(['division_id' => $this->humas->id]);
        $this->src->sessions()->create(['title' => 'Lanjutan', 'starts_at' => $s->copy()->addDay()]);
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => '1', 'name' => 'A']);
        Attendance::create(['event_id' => $this->src->id, 'session_id' => $this->src->defaultSession()->id, 'member_id' => $m->id, 'status' => 'hadir']);
    }

    public function test_duplicate_once_copies_settings_and_shifts_times(): void
    {
        $this->actingAs($this->admin)->post("/kegiatan/{$this->src->id}/duplikat", ['starts_at' => '2026-02-07 13:00:00', 'repeat' => 'none'])->assertRedirect();
        $copy = Event::where('id', '!=', $this->src->id)->sole();
        $this->assertSame(['Rapat Humas', 'rapat', 'Sekre', false, $this->humas->id], [$copy->title, $copy->type, $copy->location, $copy->is_mandatory, $copy->division_id]);
        $this->assertSame(['12:30', '13:10', '14:00', '15:00'], [$copy->checkin_opens_at->format('H:i'), $copy->late_after->format('H:i'), $copy->checkin_closes_at->format('H:i'), $copy->ends_at->format('H:i')]);
        $this->assertSame([$this->humas->id], $copy->targets()->pluck('division_id')->all());
        $this->assertSame(['Rapat Humas', 'Lanjutan'], $copy->sessions()->orderBy('id')->pluck('title')->all());
        $this->assertSame('2026-02-08 13:00', $copy->sessions()->orderBy('id')->skip(1)->first()->starts_at->format('Y-m-d H:i')); // ikut bergeser
        $this->assertSame(0, Attendance::where('event_id', $copy->id)->count()); // absensi tidak disalin
        $this->assertNull($copy->closed_at);
    }

    public function test_repeat_weekly_and_monthly_without_overflow(): void
    {
        $this->actingAs($this->admin)->post("/kegiatan/{$this->src->id}/duplikat", ['starts_at' => '2026-02-03 19:00:00', 'repeat' => 'weekly', 'count' => 3])->assertRedirect();
        $this->assertSame(['2026-02-03', '2026-02-10', '2026-02-17'], Event::where('id', '!=', $this->src->id)->orderBy('starts_at')->get()->map(fn ($e) => $e->starts_at->format('Y-m-d'))->all());

        Event::where('id', '!=', $this->src->id)->each(fn ($e) => $e->delete());
        $this->actingAs($this->admin)->post("/kegiatan/{$this->src->id}/duplikat", ['starts_at' => '2026-01-31 19:00:00', 'repeat' => 'monthly', 'count' => 3])->assertRedirect();
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], Event::where('id', '!=', $this->src->id)->orderBy('starts_at')->get()->map(fn ($e) => $e->starts_at->format('Y-m-d'))->all());

        $this->actingAs($this->admin)->post("/kegiatan/{$this->src->id}/duplikat", ['starts_at' => '2026-05-01 19:00:00', 'repeat' => 'weekly', 'count' => 13])->assertSessionHasErrors('count');
    }

    public function test_division_admin_cannot_duplicate_other_division_event(): void
    {
        $inti = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
        $da = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $inti->id]);
        $this->actingAs($da)->post("/kegiatan/{$this->src->id}/duplikat", ['starts_at' => '2026-02-07 13:00:00', 'repeat' => 'none'])->assertForbidden();
        $this->assertSame(1, Event::count());
    }
}
