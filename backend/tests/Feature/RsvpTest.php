<?php

namespace Tests\Feature;

use App\Jobs\SendNotice;
use App\Models\Division;
use App\Models\Event;
use App\Models\EventRsvp;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Services\EventService;
use App\Services\RsvpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RsvpTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private User $admin;

    private Event $event;

    /** @var list<User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        foreach (['A', 'B', 'C', 'D'] as $n) {
            $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $n, 'name' => "Anggota $n"]);
            Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'status' => 'active']);
            $this->users[] = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $m->id]);
        }
        $this->event = Event::create($this->attrs() + ['organization_id' => $this->org->id, 'term_id' => $this->term->id]);
    }

    private function attrs(array $extra = []): array
    {
        $start = now()->addDays(2);

        return $extra + ['title' => 'Makrab', 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(2), 'checkin_opens_at' => $start->copy()->subMinutes(30),
            'late_after' => $start->copy()->addMinutes(10), 'checkin_closes_at' => $start->copy()->addHour(), 'is_mandatory' => false, 'rsvp_enabled' => true, 'quota' => 2];
    }

    private function answer(int $i, bool $going)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->users[$i], 'sanctum')->postJson("/api/me/events/{$this->event->id}/rsvp", ['going' => $going]);
    }

    private function rsvpOf(int $i): ?string
    {
        return EventRsvp::withoutGlobalScopes()->where('member_id', $this->users[$i]->member_id)->value('status');
    }

    public function test_quota_waitlist_and_automatic_promotion_in_order(): void
    {
        $this->answer(0, true)->assertOk()->assertJson(['status' => 'going', 'going' => 1, 'quota' => 2, 'open' => true]);
        $this->answer(1, true)->assertJson(['status' => 'going']);
        $this->answer(2, true)->assertJson(['status' => 'waitlist', 'position' => 1]);
        $this->answer(3, true)->assertJson(['status' => 'waitlist', 'position' => 2]);
        $this->answer(2, true)->assertJson(['position' => 1]); // ulang: posisi tetap
        $this->answer(0, false)->assertJson(['status' => 'declined', 'going' => 2]);
        $this->assertSame(['declined', 'going', 'going', 'waitlist'], array_map($this->rsvpOf(...), [0, 1, 2, 3]));
        Queue::assertPushed(SendNotice::class, 1);
        $this->answer(0, true)->assertJson(['status' => 'waitlist', 'position' => 2]); // kembali = antre paling belakang
    }

    public function test_closed_after_deadline_start_or_when_disabled_and_only_for_targets(): void
    {
        $this->event->update(['rsvp_deadline' => now()->addDay()]);
        $this->travel(25)->hours();
        $this->answer(0, true)->assertUnprocessable();
        $this->event->update(['rsvp_deadline' => null]);
        $this->answer(0, true)->assertOk();
        $this->travel(2)->days();
        $this->answer(0, false)->assertUnprocessable();
        $this->travelBack();
        $this->event->update(['rsvp_enabled' => false]);
        $this->answer(1, true)->assertUnprocessable();
        $this->event->update(['rsvp_enabled' => true]);
        $this->event->targets()->create(['member_id' => $this->users[1]->member_id]);
        $this->answer(2, true)->assertForbidden();
    }

    public function test_other_organization_gets_not_found(): void
    {
        $org = Organization::create(['name' => 'Lain', 'slug' => 'lain']);
        $m = Member::create(['organization_id' => $org->id, 'member_no' => 'X', 'name' => 'X']);
        $this->users[0] = User::factory()->create(['organization_id' => $org->id, 'role' => 'member', 'member_id' => $m->id]);
        $this->answer(0, true)->assertNotFound();
    }

    public function test_raising_quota_or_losing_a_target_fills_from_waitlist_and_lowering_never_kicks(): void
    {
        foreach ([0, 1, 2, 3] as $i) {
            $this->answer($i, true);
        }
        $this->actingAs($this->admin);
        $events = app(EventService::class);
        $events->update($this->event->fresh(), ['quota' => 3]);
        $this->assertSame(['going', 'going', 'going', 'waitlist'], array_map($this->rsvpOf(...), [0, 1, 2, 3]));
        $events->update($this->event->fresh(), ['quota' => 1]);
        $this->assertSame('going', $this->rsvpOf(2));
        $events->update($this->event->fresh(), ['quota' => 3]);
        $events->setTargets($this->event->fresh(), [], [$this->users[1]->member_id, $this->users[2]->member_id, $this->users[3]->member_id]);
        $this->assertSame('going', $this->rsvpOf(3)); // A bukan target lagi: tempatnya diisi
    }

    public function test_rules_deadline_and_mandatory_quota(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->postJson('/api/events', $this->attrs(['rsvp_deadline' => now()->addDays(3)]))->assertJsonValidationErrors('rsvp_deadline');
        $this->postJson('/api/events', $this->attrs(['is_mandatory' => true]))->assertJsonValidationErrors('quota');
        $bare = $this->attrs();
        unset($bare['is_mandatory']);
        $this->postJson('/api/events', $bare)->assertJsonValidationErrors('quota'); // bawaan wajib hadir
        $this->postJson('/api/events', $this->attrs(['is_mandatory' => true, 'quota' => null]))->assertCreated();
        $this->patchJson("/api/events/{$this->event->id}", ['is_mandatory' => true])->assertJsonValidationErrors('quota');
        $this->patchJson("/api/events/{$this->event->id}", ['title' => 'Baru'])->assertOk();
    }

    public function test_member_list_dashboard_portal_and_duplicate(): void
    {
        $this->answer(0, true);
        $plain = Event::create(['rsvp_enabled' => false, 'title' => 'Biasa', 'quota' => null] + $this->attrs() + ['organization_id' => $this->org->id, 'term_id' => $this->term->id]);
        $list = collect($this->actingAs($this->users[0], 'sanctum')->getJson('/api/me/events')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('going', $list[$this->event->id]['rsvp']['status']);
        $this->assertNull($list[$plain->id]['rsvp']);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->users[1], 'web')->post("/saya/kegiatan/{$this->event->id}/rsvp", ['going' => '1'])->assertSessionHas('status', 'Anda terdaftar ikut.');
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'web')->get("/kegiatan/{$this->event->id}?tab=rsvp")->assertOk()->assertInertia(fn ($p) => $p
            ->where('rsvp.counts', ['going' => 2, 'waitlist' => 0, 'declined' => 0, 'none' => 2])->where('rsvp.rows.0.name', 'Anggota A')->has('rsvp.rows', 4));

        $copy = app(EventService::class)->duplicate($this->admin, $this->event->fresh(), now()->addDays(9))->first()->fresh();
        $this->assertTrue($copy->rsvp_enabled);
        $this->assertSame(2, $copy->quota);
        $this->assertSame(0, EventRsvp::where('event_id', $copy->id)->count());
    }

    public function test_division_admin_of_other_division_cannot_see_rsvp(): void
    {
        $a = Division::create(['organization_id' => $this->org->id, 'name' => 'A']);
        $b = Division::create(['organization_id' => $this->org->id, 'name' => 'B']);
        $this->event->update(['division_id' => $a->id]);
        $u = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $b->id]);
        $this->actingAs($u, 'web')->get("/kegiatan/{$this->event->id}?tab=rsvp")->assertForbidden();
    }

    public function test_rsvp_does_not_change_who_can_be_recorded(): void
    {
        $this->answer(0, false);
        $this->assertTrue($this->event->targetMembers()->whereKey($this->users[0]->member_id)->exists());
        $this->assertFalse(app(RsvpService::class)->isOpen(tap($this->event->fresh())->update(['closed_at' => now()])));
    }

    public function test_promotion_appears_in_the_bell_only_for_the_promoted_member(): void
    {
        $this->event->update(['quota' => 1]);
        $this->answer(0, true);
        $this->answer(1, true);
        $this->travel(5)->minutes();
        $this->answer(0, false);
        $bell = function (int $i) {
            $this->app['auth']->forgetGuards();

            return collect($this->actingAs($this->users[$i], 'sanctum')->getJson('/api/me/notifications')->assertOk()->json('data'))->where('type', 'rsvp')->values();
        };
        $this->assertSame('Anda dapat tempat: Makrab', $bell(1)[0]['title']);
        $this->assertCount(0, $bell(0));
        $this->answer(2, true); // jawaban langsung bukan kenaikan
        $this->assertCount(0, $bell(2));
        $this->travel(3)->days();
        $this->assertCount(0, $bell(1)); // kegiatan sudah lewat
    }
}
