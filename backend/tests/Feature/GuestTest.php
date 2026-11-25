<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Division;
use App\Models\Event;
use App\Models\EventGuest;
use App\Models\EventGuestCheckin;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Services\GuestService;
use App\Services\PrivacyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuestTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private User $admin;

    private User $scanner;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->scanner = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'scanner']);
        $this->event = $this->makeEvent();
    }

    private function makeEvent(): Event
    {
        $s = now();

        return Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => 'Seminar', 'starts_at' => $s, 'ends_at' => $s->copy()->addHours(2),
            'checkin_opens_at' => $s->copy()->subMinutes(30), 'late_after' => $s->copy()->addMinutes(10), 'checkin_closes_at' => $s->copy()->addHour()]);
    }

    private function as_(User $u, string $guard = 'web'): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u->fresh(), $guard);
    }

    private function add(string $lines = 'Budi; Kampus', ?User $by = null)
    {
        return $this->as_($by ?? $this->admin)->post("/kegiatan/{$this->event->id}/tamu", ['lines' => $lines]);
    }

    private function guest(): EventGuest
    {
        return EventGuest::withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    private function scan(array $extra = [], ?Event $event = null)
    {
        return $this->as_($this->scanner, 'sanctum')->postJson('/api/events/'.($event ?? $this->event)->id.'/scan', $extra + ['payload' => GuestService::payload($this->guest()), 'source' => 'qr', 'client_uuid' => (string) Str::uuid()])->assertOk();
    }

    public function test_guest_checks_in_once_without_touching_member_attendance(): void
    {
        $this->add()->assertSessionHasNoErrors();
        $this->scan()->assertJson(['status' => 'ok', 'attendance_status' => 'hadir', 'member' => ['id' => null, 'name' => 'Budi', 'division' => 'Kampus', 'member_no' => 'Tamu', 'photo_url' => null]]);
        $this->scan()->assertJson(['status' => 'duplicate']);
        $this->assertSame([1, 0], [EventGuestCheckin::count(), Attendance::withoutGlobalScopes()->count()]);
        $this->as_($this->scanner)->postJson("/pindai/{$this->event->id}/scan", ['payload' => GuestService::payload($this->guest())])->assertOk()->assertJson(['status' => 'duplicate']);
        $this->as_($this->admin)->get("/kegiatan/{$this->event->id}?tab=tamu")->assertInertia(fn ($p) => $p->where('guests.present', 1)->missing('guests.items.0.token'));
    }

    public function test_late_window_override_and_closed(): void
    {
        $this->add("A\nB");
        $this->travel(20)->minutes();
        $this->scan()->assertJson(['status' => 'ok', 'attendance_status' => 'telat']);
        EventGuestCheckin::query()->delete();
        $this->travel(2)->hours();
        $this->scan()->assertJson(['status' => 'outside_window']);
        $this->assertSame(0, EventGuestCheckin::count());
        $this->scan(['override_reason' => 'Tamu penting'])->assertJson(['status' => 'ok']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'guest.override']);
        $this->event->update(['closed_at' => now()]);
        $this->add('C')->assertStatus(422);
    }

    public function test_wrong_event_revoked_unknown_and_offline_batch(): void
    {
        $this->add();
        $this->scan([], $this->makeEvent())->assertJson(['status' => 'not_target', 'member' => null]);
        $this->scan(['payload' => 'card:guest:'.Str::random(40)])->assertJson(['status' => 'invalid']);
        $this->scan(['source' => 'nfc'])->assertJson(['status' => 'invalid']);
        $this->as_($this->scanner, 'sanctum')->postJson("/api/events/{$this->event->id}/scan/batch", ['scans' => [['payload' => GuestService::payload($this->guest()), 'source' => 'qr', 'client_uuid' => (string) Str::uuid(), 'scanned_at' => now()->toIso8601String()]]])
            ->assertOk()->assertJsonPath('results.0.status', 'invalid');
        $this->as_($this->admin)->patch("/kegiatan-tamu/{$this->guest()->id}", ['revoked' => true]);
        $this->scan()->assertJson(['status' => 'blocked']);
        $this->as_($this->admin)->patch("/kegiatan-tamu/{$this->guest()->id}", ['revoked' => false]);
        $this->scan()->assertJson(['status' => 'ok']);
    }

    public function test_each_session_separately(): void
    {
        $this->add();
        $s = $this->event->sessions()->create(['title' => 'Sesi 2', 'starts_at' => now(), 'ends_at' => now()->addHour()]);
        $this->scan()->assertJson(['status' => 'ok']);
        $this->scan(['session_id' => $s->id])->assertJson(['status' => 'ok']);
        $this->scan(['session_id' => $s->id])->assertJson(['status' => 'duplicate']);
        $this->as_($this->admin)->delete("/kegiatan/{$this->event->id}/sesi/{$s->id}")->assertStatus(422);
        $this->assertSame(2, EventGuestCheckin::count());
    }

    public function test_public_pass(): void
    {
        $this->add();
        $url = '/tamu/'.$this->guest()->token;
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex')->assertInertia(fn ($p) => $p->where('guest.name', 'Budi')->where('guest.payload', GuestService::payload($this->guest())));
        $this->get('/tamu/'.str_repeat('x', 40))->assertNotFound();
        $this->guest()->update(['revoked_at' => now()]);
        $this->get($url)->assertInertia(fn ($p) => $p->where('guest.payload', null)->where('guest.revoked', true));
    }

    public function test_input_limits_and_deletion(): void
    {
        $this->add("  \n ")->assertSessionHasErrors('lines');
        $this->add(str_repeat('x', 101))->assertSessionHasErrors('lines');
        $this->add("Ada\n\nDua ; Kampus X ")->assertSessionHasNoErrors();
        $this->assertSame([['Ada', null], ['Dua', 'Kampus X']], EventGuest::orderBy('id')->get()->map(fn ($g) => [$g->name, $g->institution])->all());
        $this->add(implode("\n", array_fill(0, 499, 'N')))->assertSessionHasErrors('lines');
        $this->scan();
        $this->as_($this->admin)->delete("/kegiatan-tamu/{$this->guest()->id}")->assertStatus(422);
        $this->as_($this->admin)->delete("/kegiatan/{$this->event->id}")->assertSessionHasErrors('event');
        $last = EventGuest::withoutGlobalScopes()->latest('id')->first();
        $this->as_($this->admin)->delete("/kegiatan-tamu/{$last->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($last);
    }

    public function test_access(): void
    {
        $a = Division::create(['organization_id' => $this->org->id, 'name' => 'A']);
        $this->event->update(['division_id' => $a->id]);
        $this->add();
        $other = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => Division::create(['organization_id' => $this->org->id, 'name' => 'B'])->id]);
        $foreign = User::factory()->create(['organization_id' => Organization::create(['name' => 'L', 'slug' => 'l'])->id, 'role' => 'super_admin']);
        $id = $this->guest()->id;
        $this->add('X', $other)->assertForbidden();
        $this->as_($other)->delete("/kegiatan-tamu/$id")->assertForbidden();
        $this->as_($other)->get("/kegiatan/{$this->event->id}/tamu/ekspor")->assertForbidden();
        $this->as_($foreign)->patch("/kegiatan-tamu/$id", ['revoked' => true])->assertNotFound();
        $this->assertNull($this->guest()->revoked_at);
    }

    public function test_export_and_retention(): void
    {
        $this->add('=1+1; Kampus');
        $this->scan();
        $csv = $this->as_($this->admin)->get("/kegiatan/{$this->event->id}/tamu/ekspor")->streamedContent();
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString('Hadir', $csv);
        $this->app['auth']->forgetGuards();
        $this->assertSame(0, app(PrivacyService::class)->retention($this->org->fresh())['guests']);
        $this->term->update(['ends_on' => now()->subYears(5)]);
        $this->assertSame(1, app(PrivacyService::class)->retention($this->org->fresh(), true)['guests']);
        $this->assertSame(1, app(PrivacyService::class)->retention($this->org->fresh())['guests']);
        $this->assertSame(['Tamu (dihapus)', null], [$this->guest()->name, $this->guest()->institution]);
        $this->assertSame(1, EventGuestCheckin::count());
    }
}
