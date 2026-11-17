<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Card;
use App\Models\Division;
use App\Models\Event;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Term $term;
    private Division $humas;
    private Division $inti;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->inti = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
    }

    private function as(User $u) { return $this->actingAs($u, 'sanctum'); }

    private function member(string $no, ?Division $div = null, string $status = 'active'): Member
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $no, 'name' => "Anggota $no"]);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'division_id' => $div?->id, 'status' => $status]);

        return $m;
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('anggota.csv', $content);
    }

    public function test_member_crud_with_membership_and_soft_delete_blocks_card(): void
    {
        $res = $this->as($this->admin)->postJson('/api/members', [
            'member_no' => 'A-01', 'name' => 'Budi', 'email' => 'budi@x.test', 'cohort' => '2024', 'division_id' => $this->humas->id,
        ])->assertCreated();
        $id = $res['id'];
        $this->assertDatabaseHas('memberships', ['member_id' => $id, 'term_id' => $this->term->id, 'division_id' => $this->humas->id, 'status' => 'active']);

        $this->as($this->admin)->postJson('/api/members', ['member_no' => 'A-01', 'name' => 'Dobel'])->assertUnprocessable();
        $this->as($this->admin)->patchJson("/api/members/$id", ['status' => 'alumni'])->assertOk();
        $this->assertDatabaseHas('memberships', ['member_id' => $id, 'status' => 'alumni']);

        $this->as($this->admin)->getJson('/api/members?q=bud')->assertOk()->assertJsonPath('total', 1);
        $this->as($this->admin)->getJson('/api/members?division_id='.$this->inti->id)->assertJsonPath('total', 0);

        $card = Card::create(['organization_id' => $this->org->id, 'public_id' => (string) str()->ulid(), 'member_id' => $id, 'status' => 'active']);
        $this->as($this->admin)->deleteJson("/api/members/$id")->assertNoContent();
        $this->assertSoftDeleted('members', ['id' => $id]);
        $this->assertSame('blocked', $card->fresh()->status);
    }

    public function test_csv_import_previews_errors_then_commits(): void
    {
        $this->member('001', $this->inti); // akan di-update
        $csv = "\xEF\xBB\xBFmember_no;name;email;cohort;division;status\n"
            ."001;Nama Baru;baru@x.test;2023;humas;\n"
            ."002;Siti;siti@x.test;2024;Inti;active\n"
            ."003;;salah-email;2024;Gaib;bukan\n"
            ."002;Dobel;;;;\n\n";

        $prev = $this->as($this->admin)->post('/api/members/import/preview', ['file' => $this->csv($csv)], ['Accept' => 'application/json'])->assertOk();
        $prev->assertJsonPath('summary.valid', 2)->assertJsonPath('summary.create', 1)->assertJsonPath('summary.update', 1)->assertJsonPath('summary.errors', 2);
        $this->assertSame([4, 5], array_column($prev['errors'], 'line'));

        $this->as($this->admin)->postJson('/api/members/import/commit', ['token' => $prev['token']])->assertUnprocessable();
        $this->as($this->admin)->postJson('/api/members/import/commit', ['token' => $prev['token'], 'skip_errors' => true])
            ->assertOk()->assertJson(['created' => 1, 'updated' => 1]);

        $this->assertDatabaseHas('members', ['member_no' => '001', 'name' => 'Nama Baru', 'cohort' => '2023']);
        $updated = Member::where('member_no', '001')->first();
        $this->assertDatabaseHas('memberships', ['member_id' => $updated->id, 'division_id' => $this->humas->id]);
        $this->as($this->admin)->postJson('/api/members/import/commit', ['token' => $prev['token'], 'skip_errors' => true])->assertUnprocessable(); // token sekali pakai
    }

    public function test_import_requires_header(): void
    {
        $this->as($this->admin)->post('/api/members/import/preview', ['file' => $this->csv("nim,nama\n1,a\n")], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }

    public function test_only_one_current_term(): void
    {
        $this->as($this->admin)->postJson('/api/terms', ['name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'is_current' => true])->assertCreated();
        $this->assertSame(['2027'], Term::where('is_current', true)->pluck('name')->all());
    }

    private function eventWithAttendance(): Event
    {
        $event = Event::create([
            'organization_id' => $this->org->id, 'term_id' => $this->term->id, 'division_id' => $this->humas->id, 'title' => 'Rapat Humas',
            'starts_at' => now(), 'ends_at' => now()->addHour(), 'checkin_opens_at' => now()->subHour(),
            'late_after' => now(), 'checkin_closes_at' => now()->addHour(),
        ]);
        $session = $event->defaultSession();
        foreach ([['h1', 'hadir'], ['h2', 'telat'], ['h3', 'alpa'], ['h4', null]] as [$no, $status]) {
            $m = $this->member($no, $this->humas);
            if ($status) {
                Attendance::create(['event_id' => $event->id, 'session_id' => $session->id, 'member_id' => $m->id, 'status' => $status, 'scanned_at' => now()]);
            }
        }
        $this->member('i1', $this->inti); // divisi lain, ikut target karena event tanpa target eksplisit

        return $event;
    }

    public function test_event_and_term_reports(): void
    {
        $event = $this->eventWithAttendance();

        $rep = $this->as($this->admin)->getJson("/api/reports/events/{$event->id}")->assertOk();
        $rep->assertJsonPath('summary.total', 5)->assertJsonPath('summary.by_status.hadir', 1)
            ->assertJsonPath('summary.by_status.belum_absen', 2)->assertJsonPath('summary.present_rate', 40);

        $term = $this->as($this->admin)->getJson("/api/reports/terms/{$this->term->id}")->assertOk();
        $h1 = collect($term['rows'])->firstWhere('member_no', 'h1');
        $this->assertSame(100.0, (float) $h1['attendance_rate']);
        $this->assertCount(5, $term['rows']);
    }

    public function test_division_admin_is_limited_to_own_division(): void
    {
        $event = $this->eventWithAttendance();
        $intiAdmin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $this->inti->id]);

        $this->as($intiAdmin)->getJson("/api/reports/events/{$event->id}")->assertForbidden(); // event Humas
        $rows = $this->as($intiAdmin)->getJson("/api/reports/terms/{$this->term->id}?division_id={$this->humas->id}")->assertOk()['rows'];
        $this->assertSame(['i1'], array_column($rows, 'member_no')); // dipaksa ke divisi Inti
        $this->as($intiAdmin)->getJson('/api/members')->assertForbidden();
    }

    public function test_exports_generate_xlsx_and_pdf(): void
    {
        $event = $this->eventWithAttendance();

        foreach (['xlsx' => ['report' => 'event', 'event_id' => $event->id], 'pdf' => ['report' => 'term', 'term_id' => $this->term->id]] as $format => $params) {
            $res = $this->as($this->admin)->postJson('/api/exports', $params + ['format' => $format])->assertAccepted();
            $export = $this->as($this->admin)->getJson("/api/exports/{$res['id']}")->assertOk();
            $this->assertSame('ready', $export['status'], (string) $export['error']);
            $this->assertStringEndsWith(".$format", \App\Models\Export::find($res['id'])->path);
            $this->as($this->admin)->get("/api/exports/{$res['id']}/download")->assertOk()->assertDownload();
        }

        $other = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $this->humas->id]);
        $this->as($other)->getJson("/api/exports/{$res['id']}")->assertForbidden();
    }

    public function test_member_photo_is_private(): void
    {
        $m = $this->member('p1');
        $this->as($this->admin)->post("/api/members/{$m->id}/photo", ['photo' => UploadedFile::fake()->image('f.jpg', 200, 200)], ['Accept' => 'application/json'])->assertOk();
        $path = $m->fresh()->photo_path;
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith("members/{$this->org->id}/", $path);

        $scanner = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'scanner']);
        $this->as($scanner)->get("/api/members/{$m->id}/photo")->assertOk();
        $this->assertStringContainsString("/api/members/{$m->id}/photo", route('api.members.photo', $m));
        $otherMember = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $this->member('p2')->id]);
        $this->as($otherMember)->get("/api/members/{$m->id}/photo")->assertForbidden();
        $self = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $m->id]);
        $this->as($self)->get("/api/members/{$m->id}/photo")->assertOk();

        $org2 = Organization::create(['name' => 'Lain', 'slug' => 'lain']);
        $admin2 = User::factory()->create(['organization_id' => $org2->id, 'role' => 'super_admin']);
        $this->as($admin2)->get("/api/members/{$m->id}/photo")->assertNotFound();
    }
}
