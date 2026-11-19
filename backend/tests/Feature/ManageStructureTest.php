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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Ubah/hapus sesi, periode, dan divisi dari dashboard — termasuk penolakan saat masih dipakai. */
class ManageStructureTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Term $term;
    private User $admin;
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-11-20 08:00:00'));
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->event = Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => 'Pelatihan',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(4), 'checkin_opens_at' => now()->addDay()->subMinutes(30),
            'late_after' => now()->addDay()->addMinutes(10), 'checkin_closes_at' => now()->addDay()->addHour()]);
        $this->actingAs($this->admin);
    }

    private function addSession(string $title, string $start)
    {
        $this->post("/kegiatan/{$this->event->id}/sesi", ['title' => $title, 'starts_at' => $start])->assertSessionHasNoErrors();

        return $this->event->sessions()->where('title', $title)->firstOrFail();
    }

    public function test_update_session_recomputes_window_and_default_keeps_event_schedule(): void
    {
        $day2 = $this->addSession('Hari 2', '2026-11-22 13:00:00');
        $this->put("/kegiatan/{$this->event->id}/sesi/{$day2->id}", ['title' => 'Hari Kedua', 'starts_at' => '2026-11-22 15:00:00'])->assertSessionHasNoErrors();
        $day2->refresh();
        $this->assertSame(['Hari Kedua', '14:30', '15:10'], [$day2->title, $day2->checkin_opens_at->format('H:i'), $day2->late_after->format('H:i')]);

        $main = $this->event->defaultSession();
        $this->put("/kegiatan/{$this->event->id}/sesi/{$main->id}", ['title' => 'Pembukaan', 'starts_at' => '2026-12-01 10:00:00'])->assertSessionHasNoErrors();
        $main->refresh();
        $this->assertSame('Pembukaan', $main->title);
        $this->assertTrue($main->checkin_opens_at->equalTo($this->event->checkin_opens_at)); // jam diabaikan

        $this->put("/kegiatan/{$this->event->id}/sesi/{$day2->id}", ['title' => 'Tanpa jam'])->assertSessionHasErrors('starts_at');
    }

    public function test_changing_time_of_opened_session_needs_reason(): void
    {
        $day2 = $this->addSession('Hari 2', '2026-11-22 13:00:00');
        $this->travelTo(Carbon::parse('2026-11-22 12:45:00')); // check-in hari 2 sudah buka
        $this->put("/kegiatan/{$this->event->id}/sesi/{$day2->id}", ['title' => 'Hari 2', 'starts_at' => '2026-11-22 14:00:00'])->assertSessionHasErrors('reason');
        $this->put("/kegiatan/{$this->event->id}/sesi/{$day2->id}", ['title' => 'Hari 2', 'starts_at' => '2026-11-22 14:00:00', 'reason' => 'Pemateri terlambat datang'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'event.session_updated']);
        $this->put("/kegiatan/{$this->event->id}/sesi/{$day2->id}", ['title' => 'Ganti judul saja', 'starts_at' => '2026-11-22 14:00:00'])->assertSessionHasNoErrors();
    }

    public function test_delete_session_only_when_empty_and_not_main(): void
    {
        $day2 = $this->addSession('Hari 2', '2026-11-22 13:00:00');
        $day3 = $this->addSession('Hari 3', '2026-11-23 13:00:00');
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => '1', 'name' => 'A']);
        Attendance::create(['event_id' => $this->event->id, 'session_id' => $day2->id, 'member_id' => $m->id, 'status' => 'hadir']);

        $this->delete("/kegiatan/{$this->event->id}/sesi/{$day2->id}")->assertStatus(422);
        $this->delete("/kegiatan/{$this->event->id}/sesi/{$this->event->defaultSession()->id}")->assertStatus(422);
        $this->delete("/kegiatan/{$this->event->id}/sesi/{$day3->id}")->assertRedirect();
        $this->assertModelMissing($day3);

        $other = Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => 'Lain',
            'starts_at' => now(), 'ends_at' => now()->addHour(), 'checkin_opens_at' => now(), 'late_after' => now(), 'checkin_closes_at' => now()->addHour()]);
        $this->delete("/kegiatan/{$other->id}/sesi/{$day2->id}")->assertNotFound(); // sesi milik kegiatan lain
    }

    public function test_terms_update_and_delete_only_when_unused(): void
    {
        $old = Term::create(['organization_id' => $this->org->id, 'name' => '2025', 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31']);
        $empty = Term::create(['organization_id' => $this->org->id, 'name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => '1', 'name' => 'A']);
        Membership::create(['member_id' => $m->id, 'term_id' => $old->id, 'status' => 'alumni']);

        $this->put("/pengaturan/periode/{$empty->id}", ['name' => '2027/2028', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'])->assertSessionHasNoErrors();
        $this->assertSame('2027/2028', $empty->fresh()->name);
        $this->put("/pengaturan/periode/{$empty->id}", ['name' => 'x', 'starts_on' => '2027-12-31', 'ends_on' => '2027-01-01'])->assertSessionHasErrors('ends_on');

        $this->delete("/pengaturan/periode/{$this->term->id}")->assertStatus(422); // berjalan
        $this->delete("/pengaturan/periode/{$old->id}")->assertStatus(422);        // masih ada keanggotaan
        $this->assertSame(1, Membership::count());
        $this->delete("/pengaturan/periode/{$empty->id}")->assertRedirect();
        $this->assertModelMissing($empty);
    }

    public function test_division_delete_refused_while_in_use(): void
    {
        $used = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->event->targets()->create(['division_id' => $used->id]);
        $free = Division::create(['organization_id' => $this->org->id, 'name' => 'Kosong']);

        $this->from('/pengaturan?tab=divisi')->withHeader('X-Inertia', 'true')->delete("/pengaturan/divisi/{$used->id}")
            ->assertSessionHasErrors(['error' => 'Divisi Humas masih dipakai (1 target kegiatan); pindahkan dulu, lalu hapus.']);
        $this->assertSame(1, $this->event->targets()->count()); // target kegiatan tidak hilang
        $this->delete("/pengaturan/divisi/{$free->id}")->assertRedirect();
        $this->assertModelMissing($free);
    }

    public function test_division_admin_cannot_manage_terms_or_divisions(): void
    {
        $div = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $da = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $div->id]);
        $this->actingAs($da)->delete("/pengaturan/periode/{$this->term->id}")->assertForbidden();
        $this->actingAs($da)->delete("/pengaturan/divisi/{$div->id}")->assertForbidden();
    }
}
