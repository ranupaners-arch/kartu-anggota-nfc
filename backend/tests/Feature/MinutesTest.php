<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Division;
use App\Models\Event;
use App\Models\EventDocument;
use App\Models\EventMinutes;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MinutesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private User $admin;

    private Division $division;

    private Event $event;

    private User $participant;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->division = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->participant = $this->member('A', $this->division->id);
        $this->outsider = $this->member('B', null);
        $s = now()->subDay();
        $this->event = Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => 'Rapat', 'starts_at' => $s, 'ends_at' => $s->copy()->addHours(2),
            'checkin_opens_at' => $s, 'late_after' => $s->copy()->addMinutes(10), 'checkin_closes_at' => $s->copy()->addHour(), 'division_id' => $this->division->id]);
        $this->event->targets()->create(['division_id' => $this->division->id]);
    }

    private function member(string $no, ?int $division): User
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $no, 'name' => "Anggota $no"]);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'status' => 'active', 'division_id' => $division]);

        return User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $m->id]);
    }

    private function as_(User $u, string $guard = 'web'): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u->fresh(), $guard);
    }

    private function save(string $content, int $version, ?User $by = null)
    {
        return $this->as_($by ?? $this->admin)->put("/kegiatan/{$this->event->id}/notulen", ['content' => $content, 'version' => $version]);
    }

    private function publish(bool $on = true)
    {
        return $this->as_($this->admin)->post("/kegiatan/{$this->event->id}/notulen/terbit", ['published' => $on]);
    }

    private function upload(array $extra = [], ?User $by = null)
    {
        return $this->as_($by ?? $this->admin)->post("/kegiatan/{$this->event->id}/dokumen", $extra + ['title' => 'Materi Rapat', 'for_members' => '1', 'file' => UploadedFile::fake()->image('a.png')]);
    }

    private function list_(User $u): array
    {
        return $this->as_($u, 'sanctum')->getJson('/api/me/minutes')->assertOk()->json('data');
    }

    public function test_draft_is_hidden_until_published_and_hidden_again_when_withdrawn(): void
    {
        $this->publish()->assertStatus(422);
        $this->save('Hasil rapat', 0)->assertSessionHasNoErrors();
        $this->assertSame([], $this->list_($this->participant));
        $this->publish()->assertSessionHasNoErrors();
        $this->assertSame('Hasil rapat', $this->list_($this->participant)[0]['content']);
        $this->assertSame([], $this->list_($this->outsider));
        $notices = fn (User $u) => collect($this->as_($u, 'sanctum')->getJson('/api/me/notifications')->json('data'))->where('type', 'minutes')->count();
        $this->assertSame([1, 0], [$notices($this->participant), $notices($this->outsider)]);
        $this->publish(false);
        $this->assertSame([[], 0], [$this->list_($this->participant), $notices($this->participant)]);
    }

    public function test_second_editor_cannot_overwrite(): void
    {
        $this->save('Satu', 0);
        $this->save('Menimpa', 0)->assertSessionHasErrors('content');
        $this->save('Dua', 1)->assertSessionHasNoErrors();
        $this->save('Basi', 1)->assertSessionHasErrors('content');
        $this->assertSame(['Dua', 2], [EventMinutes::sole()->content, EventMinutes::sole()->version]);
    }

    public function test_attendee_outside_target_may_read(): void
    {
        $this->save('Hasil', 0);
        $this->publish();
        Attendance::create(['event_id' => $this->event->id, 'session_id' => $this->event->defaultSession()->id, 'member_id' => $this->outsider->member_id, 'status' => 'hadir', 'source' => 'nfc', 'scanned_at' => now()]);
        $this->assertCount(1, $this->list_($this->outsider));
    }

    public function test_documents_respect_visibility(): void
    {
        $this->upload()->assertSessionHasNoErrors();
        $this->upload(['title' => 'Rahasia', 'for_members' => '0']);
        [$open, $closed] = EventDocument::orderBy('id')->get()->all();
        $this->assertSame(['materi-rapat.png'], array_column($this->list_($this->participant)[0]['documents'], 'file_name'));
        $res = $this->as_($this->participant, 'sanctum')->get("/api/me/documents/{$open->id}")->assertOk();
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->as_($this->participant, 'sanctum')->get("/api/me/documents/{$closed->id}")->assertNotFound();
        $this->as_($this->outsider, 'sanctum')->get("/api/me/documents/{$open->id}")->assertNotFound();
        $this->as_($this->participant)->get("/saya/dokumen/{$open->id}")->assertOk();
        $this->as_($this->participant)->get('/saya/notulen')->assertInertia(fn ($p) => $p->component('portal/minutes')->has('items', 1));
        $this->as_($this->admin)->get("/kegiatan-dokumen/{$closed->id}")->assertOk();
        $this->as_($this->admin)->patch("/kegiatan-dokumen/{$open->id}", ['for_members' => '0']);
        $this->as_($this->participant, 'sanctum')->get("/api/me/documents/{$open->id}")->assertNotFound();
    }

    public function test_file_rules_limit_and_cleanup(): void
    {
        $this->upload(['file' => UploadedFile::fake()->create('x.exe', 5)])->assertSessionHasErrors('file');
        $this->upload(['file' => UploadedFile::fake()->image('x.html')])->assertSessionHasErrors('file');
        $this->upload(['file' => UploadedFile::fake()->create('x.pdf', 5121, 'application/pdf')])->assertSessionHasErrors('file');
        $this->assertSame([], Storage::disk('local')->allFiles());
        for ($i = 0; $i < 20; $i++) {
            EventDocument::create(['event_id' => $this->event->id, 'title' => "D$i", 'path' => "x/$i", 'extension' => 'pdf', 'size' => 1]);
        }
        $this->upload()->assertSessionHasErrors('file');
        $this->assertSame([], Storage::disk('local')->allFiles());
        EventDocument::query()->delete();
        $this->upload();
        $this->as_($this->admin)->delete('/kegiatan-dokumen/'.EventDocument::sole()->id)->assertSessionHasNoErrors();
        $this->assertSame([[], 0], [Storage::disk('local')->allFiles(), EventDocument::count()]);
    }

    public function test_only_managers_of_the_event(): void
    {
        $other = Division::create(['organization_id' => $this->org->id, 'name' => 'Lain']);
        $own = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $this->division->id]);
        $foreignDiv = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $other->id]);
        $foreignOrg = User::factory()->create(['organization_id' => Organization::create(['name' => 'L', 'slug' => 'l'])->id, 'role' => 'super_admin']);
        $this->save('Ok', 0, $own)->assertSessionHasNoErrors();
        $this->upload([], $own)->assertSessionHasNoErrors();
        $doc = EventDocument::sole();
        $this->save('X', 1, $foreignDiv)->assertForbidden();
        $this->upload([], $foreignDiv)->assertForbidden();
        $this->as_($foreignDiv)->get("/kegiatan-dokumen/{$doc->id}")->assertForbidden();
        $this->as_($foreignDiv)->delete("/kegiatan-dokumen/{$doc->id}")->assertForbidden();
        $this->save('X', 1, $foreignOrg)->assertNotFound();
        $this->as_($foreignOrg)->get("/kegiatan-dokumen/{$doc->id}")->assertNotFound();
        $this->as_($own)->get("/kegiatan/{$this->event->id}?tab=notulen")->assertOk()->assertInertia(fn ($p) => $p->where('minutes.minutes.content', 'Ok')->has('minutes.documents', 1)->where('canDelete', false));
    }

    public function test_event_with_minutes_cannot_be_deleted(): void
    {
        $this->save('Ok', 0);
        $this->as_($this->admin)->delete("/kegiatan/{$this->event->id}")->assertSessionHasErrors('event');
        $this->assertModelExists($this->event);
    }
}
