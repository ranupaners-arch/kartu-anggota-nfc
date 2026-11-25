<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Division;
use App\Models\Event;
use App\Models\EventSurvey;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SurveyTest extends TestCase
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
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $s = now()->subDay();
        $this->event = Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => 'Seminar', 'starts_at' => $s, 'ends_at' => $s->copy()->addHours(2),
            'checkin_opens_at' => $s, 'late_after' => $s->copy()->addMinutes(10), 'checkin_closes_at' => $s->copy()->addHour(), 'closed_at' => now()]);
        foreach (['hadir', 'hadir', 'telat', 'alpa'] as $i => $status) {
            $m = Member::create(['organization_id' => $this->org->id, 'member_no' => "M$i", 'name' => "Anggota $i"]);
            Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'status' => 'active']);
            Attendance::create(['event_id' => $this->event->id, 'session_id' => $this->event->defaultSession()->id, 'member_id' => $m->id, 'status' => $status, 'source' => 'nfc', 'scanned_at' => $s]);
            $this->users[] = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $m->id]);
        }
    }

    private function as_(User $u, string $guard = 'web'): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u->fresh(), $guard);
    }

    private function open(string $questions = "Materi jelas\nWaktu sesuai", ?User $by = null)
    {
        return $this->as_($by ?? $this->admin)->post("/kegiatan/{$this->event->id}/survei", ['questions' => $questions, 'days' => 7]);
    }

    private function answer(int $i, array $extra = [])
    {
        return $this->as_($this->users[$i], 'sanctum')->postJson('/api/me/surveys/'.EventSurvey::withoutGlobalScopes()->value('id'), $extra + ['rating' => 5 - $i, 'answers' => [4, 3], 'comment' => "Saran $i"]);
    }

    private function pending(int $i): int
    {
        return count($this->as_($this->users[$i], 'sanctum')->getJson('/api/me/surveys')->assertOk()->json('data'));
    }

    private function tab()
    {
        return $this->as_($this->admin)->get("/kegiatan/{$this->event->id}?tab=survei")->assertOk();
    }

    public function test_full_flow_results_only_after_closing(): void
    {
        $this->open()->assertSessionHasNoErrors();
        $this->assertSame([1, 0], [$this->pending(0), $this->pending(3)]);
        foreach ([0, 1, 2] as $i) {
            $this->answer($i)->assertOk();
        }
        $this->assertSame(0, $this->pending(0));
        $this->tab()->assertInertia(fn ($p) => $p->where('survey.survey.responses', 3)->where('survey.result', null));
        $this->as_($this->admin)->post('/kegiatan-survei/'.EventSurvey::sole()->id.'/tutup')->assertSessionHasNoErrors();
        $this->tab()->assertInertia(fn ($p) => $p->where('survey.result.items.0.average', 4)->where('survey.result.items.0.counts', [0, 0, 1, 1, 1])
            ->where('survey.result.items.1.label', 'Materi jelas')->where('survey.result.items.2.average', 3)->has('survey.result.comments', 3));
        $this->open()->assertSessionHasErrors('questions');
    }

    public function test_answers_cannot_be_linked_to_members(): void
    {
        $this->open();
        $before = AuditLog::withoutGlobalScopes()->count();
        $this->answer(0)->assertOk();
        $this->assertSame(['id', 'survey_id', 'rating', 'answers', 'comment'], Schema::getColumnListing('event_survey_responses'));
        $this->assertSame(['survey_id', 'member_id'], Schema::getColumnListing('event_survey_respondents'));
        $this->assertSame($before, AuditLog::withoutGlobalScopes()->count());
    }

    public function test_few_answers_stay_hidden(): void
    {
        $this->open();
        $this->answer(0);
        $this->answer(1);
        $this->as_($this->admin)->post('/kegiatan-survei/'.EventSurvey::sole()->id.'/tutup');
        $this->tab()->assertInertia(fn ($p) => $p->where('survey.survey.responses', 2)->where('survey.result', null));
    }

    public function test_once_only_present_members_and_only_while_open(): void
    {
        $this->open();
        $this->answer(0)->assertOk();
        $this->answer(0)->assertStatus(422);
        $this->answer(3)->assertForbidden();
        $this->answer(1, ['answers' => [5]])->assertStatus(422);
        $this->answer(1, ['answers' => [5, 6]])->assertStatus(422);
        $this->answer(1, ['rating' => 0])->assertStatus(422);
        $this->assertSame(1, DB::table('event_survey_responses')->count());
        $this->assertSame(1, $this->pending(1));
        $this->travel(8)->days();
        $this->answer(1)->assertStatus(422);
        $this->assertSame(0, $this->pending(1));
        $this->tab()->assertInertia(fn ($p) => $p->where('survey.survey.open', false));
    }

    public function test_opening_rules(): void
    {
        $this->open("a\nb\nc\nd\ne\nf")->assertSessionHasErrors('questions');
        $this->open("sama\nsama")->assertSessionHasErrors();
        $this->event->update(['closed_at' => null]);
        $this->open()->assertSessionHasErrors('questions');
        $this->event->update(['closed_at' => now()]);
        Attendance::withoutGlobalScopes()->where('member_id', $this->users[0]->member_id)->update(['voided_at' => now()]);
        $this->open()->assertSessionHasErrors('questions');
        $this->assertSame(0, EventSurvey::withoutGlobalScopes()->count());
    }

    public function test_access(): void
    {
        $div = Division::create(['organization_id' => $this->org->id, 'name' => 'A']);
        $this->event->update(['division_id' => $div->id]);
        $other = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => Division::create(['organization_id' => $this->org->id, 'name' => 'B'])->id]);
        $this->open('', $other)->assertForbidden();
        $this->open('')->assertSessionHasNoErrors();
        $id = EventSurvey::sole()->id;
        $this->as_($other)->post("/kegiatan-survei/$id/tutup")->assertForbidden();
        $foreign = User::factory()->create(['organization_id' => Organization::create(['name' => 'L', 'slug' => 'l'])->id, 'role' => 'super_admin']);
        $this->as_($foreign)->post("/kegiatan-survei/$id/tutup")->assertNotFound();
        $this->as_($this->users[0])->post("/kegiatan-survei/$id/tutup")->assertRedirect();
        $this->assertNull(EventSurvey::withoutGlobalScopes()->find($id)->closed_at);
    }

    public function test_portal_and_notification(): void
    {
        $this->open('');
        $count = fn () => collect($this->as_($this->users[0], 'sanctum')->getJson('/api/me/notifications')->json('data'))->where('type', 'survey')->count();
        $this->assertSame(1, $count());
        $this->as_($this->users[0])->get('/saya/kegiatan')->assertInertia(fn ($p) => $p->has('surveys', 1));
        $this->as_($this->users[0])->post('/saya/survei/'.EventSurvey::withoutGlobalScopes()->value('id'), ['rating' => 5, 'answers' => [], 'comment' => null])->assertSessionHasNoErrors();
        $this->assertSame(0, $count());
    }
}
