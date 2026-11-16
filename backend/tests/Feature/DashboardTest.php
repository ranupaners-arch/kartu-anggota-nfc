<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Card;
use App\Models\Device;
use App\Models\Division;
use App\Models\Event;
use App\Models\ExcuseRequest;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Term $term;
    private Division $humas;
    private Division $inti;
    private User $admin;
    private User $humasAdmin;
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->inti = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin', 'password' => 'rahasia123']);
        $this->humasAdmin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $this->humas->id]);
        $this->event = Event::create([
            'organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => 'Rapat Umum',
            'starts_at' => now(), 'ends_at' => now()->addHours(2), 'checkin_opens_at' => now()->subMinutes(30),
            'late_after' => now()->addMinutes(10), 'checkin_closes_at' => now()->addHour(), 'is_mandatory' => true,
        ]);
    }

    private function member(string $no, ?Division $d = null): Member
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $no, 'name' => "Anggota $no"]);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'division_id' => $d?->id, 'status' => 'active']);

        return $m;
    }

    private function attend(Member $m, array $extra = []): Attendance
    {
        return Attendance::create(['event_id' => $this->event->id, 'session_id' => $this->event->defaultSession()->id,
            'member_id' => $m->id, 'status' => 'hadir', 'scanned_at' => now()] + $extra);
    }

    public function test_login_only_for_active_admins(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->post(route('login'), ['email' => $this->admin->email, 'password' => 'salah'])->assertSessionHasErrors('email');
        $this->post(route('login'), ['email' => $this->admin->email, 'password' => 'rahasia123'])->assertRedirect(route('home'));
        $this->get('/')->assertOk()->assertSee('Beranda');
        $this->post(route('logout'))->assertRedirect(route('login'));

        $scanner = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'scanner', 'password' => 'rahasia123']);
        $this->post(route('login'), ['email' => $scanner->email, 'password' => 'rahasia123'])->assertSessionHasErrors('email');
        $this->assertGuest('web');

        $this->admin->update(['disabled_at' => now()]);
        $this->post(route('login'), ['email' => $this->admin->email, 'password' => 'rahasia123'])->assertSessionHasErrors('email');
    }

    public function test_all_pages_render_for_super_admin(): void
    {
        $m = $this->member('001', $this->humas);
        $this->attend($m);
        Card::create(['organization_id' => $this->org->id, 'public_id' => (string) Str::ulid(), 'member_id' => $m->id, 'status' => 'active']);
        $this->actingAs($this->admin);

        foreach ([
            '/', '/akun', route('events.index'), route('events.index', ['tab' => 'selesai']), route('events.create'),
            route('events.show', $this->event), route('events.show', [$this->event, 'tab' => 'hadir']),
            route('events.show', [$this->event, 'tab' => 'sesi']), route('events.show', [$this->event, 'tab' => 'target']),
            route('events.edit', $this->event), route('excuses.index'), route('excuses.index', ['tab' => 'riwayat']),
            route('reviews.index'), route('reviews.index', ['tab' => 'override']), route('reviews.index', ['tab' => 'anomali']),
            route('members.index'), route('members.index', ['tab' => 'semua', 'q' => 'Angg']), route('members.create'),
            route('members.show', $m), route('members.show', [$m, 'tab' => 'riwayat']), route('members.show', [$m, 'tab' => 'kartu']),
            route('members.edit', $m), route('members.import'), route('cards.index'), route('cards.index', ['tab' => 'ditolak']),
            route('reports.index'), route('reports.index', ['tab' => 'divisi', 'division_id' => $this->humas->id]), route('reports.index', ['tab' => 'ekspor']),
            route('users.index'), route('users.create'), route('users.edit', $this->humasAdmin), route('devices.index'), route('logs.index'),
            route('settings.index'), route('settings.index', ['tab' => 'periode']), route('settings.index', ['tab' => 'divisi']),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_division_admin_sees_only_own_scope(): void
    {
        $intiEvent = Event::create($this->event->only(['organization_id', 'term_id', 'starts_at', 'ends_at', 'checkin_opens_at', 'late_after', 'checkin_closes_at'])
            + ['title' => 'Rapat Inti', 'division_id' => $this->inti->id]);
        $this->actingAs($this->humasAdmin);

        $this->get(route('events.index'))->assertOk()->assertSee('Rapat Umum')->assertDontSee('Rapat Inti');
        $this->get(route('events.show', $intiEvent))->assertForbidden();
        foreach ([route('members.index'), route('cards.index'), route('users.index'), route('logs.index'), route('settings.index')] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/')->assertOk()->assertDontSee('Pengguna &amp; Peran', false);
    }

    public function test_event_lifecycle_and_correction(): void
    {
        $this->actingAs($this->admin);
        $res = $this->post(route('events.store'), [
            'title' => 'Seminar', 'type' => 'seminar', 'starts_at' => now()->addDay()->format('Y-m-d H:i'),
            'ends_at' => now()->addDay()->addHours(2)->format('Y-m-d H:i'), 'checkin_opens_at' => now()->addDay()->subHour()->format('Y-m-d H:i'),
            'late_after' => now()->addDay()->format('Y-m-d H:i'), 'checkin_closes_at' => now()->addDay()->addHour()->format('Y-m-d H:i'),
            'is_mandatory' => '1', 'target_division_ids' => [$this->humas->id],
        ]);
        $seminar = Event::where('title', 'Seminar')->firstOrFail();
        $res->assertRedirect(route('events.show', $seminar));
        $this->assertDatabaseHas('event_targets', ['event_id' => $seminar->id, 'division_id' => $this->humas->id]);

        // Jendela waktu kegiatan berjalan butuh alasan
        $this->put(route('events.update', $this->event), ['late_after' => now()->addMinutes(20)->format('Y-m-d H:i')])->assertSessionHasErrors('reason');

        $present = $this->member('A');
        $absent = $this->member('B');
        $att = $this->attend($present);
        $this->patch(route('attendances.correct', $att), ['status' => 'telat', 'reason' => 'Datang terlambat'])->assertRedirect();
        $this->assertSame('telat', $att->fresh()->status);

        $this->post(route('events.close', $this->event))->assertSessionHas('status');
        $this->assertDatabaseHas('attendances', ['member_id' => $absent->id, 'status' => 'alpa']);
        $this->delete(route('events.destroy', $this->event))->assertSessionHasErrors();
        $this->delete(route('events.destroy', $seminar))->assertRedirect(route('events.index'));
    }

    public function test_excuse_review_and_review_queue(): void
    {
        $m = $this->member('C');
        $x = ExcuseRequest::create(['event_id' => $this->event->id, 'member_id' => $m->id, 'type' => 'sakit', 'reason' => 'demam']);
        $flagged = $this->attend($this->member('D'), ['needs_review' => true, 'review_reason' => 'card_blocked_after_scan']);
        $this->actingAs($this->admin);

        $this->get(route('excuses.index'))->assertSee('demam');
        $this->patch(route('excuses.review', $x), ['status' => 'approved'])->assertRedirect();
        $this->assertSame('approved', $x->fresh()->status);

        $this->get(route('reviews.index'))->assertSee('card_blocked_after_scan');
        $this->post(route('reviews.accept', $flagged), ['reason' => 'sah'])->assertRedirect();
        $this->assertFalse($flagged->fresh()->needs_review);
        $this->assertDatabaseHas('attendance_changes', ['attendance_id' => $flagged->id]);
    }

    public function test_member_crud_import_and_card_block(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('members.store'), ['member_no' => 'X1', 'name' => 'Budi', 'division_id' => $this->humas->id, 'status' => 'active'])->assertRedirect();
        $m = Member::where('member_no', 'X1')->firstOrFail();
        $this->put(route('members.update', $m), ['name' => 'Budi S', 'division_id' => $this->inti->id, 'status' => 'alumni'])->assertRedirect();
        $this->assertDatabaseHas('memberships', ['member_id' => $m->id, 'division_id' => $this->inti->id, 'status' => 'alumni']);

        $this->post(route('members.photo.upload', $m), ['photo' => UploadedFile::fake()->image('f.jpg')])->assertRedirect();
        $this->get(route('members.photo', $m))->assertOk();

        $csv = UploadedFile::fake()->createWithContent('a.csv', "member_no;name;division\nY1;Siti;Humas\nY2;;Gaib\n");
        $preview = $this->post(route('members.import.preview'), ['file' => $csv])->assertOk()->assertSee('1 baris valid');
        preg_match('/name="token" value="([^"]+)"/', $preview->getContent(), $mm);
        $this->post(route('members.import.commit'), ['token' => $mm[1], 'skip_errors' => '1'])->assertRedirect(route('members.index'));
        $this->assertDatabaseHas('members', ['member_no' => 'Y1']);

        $card = Card::create(['organization_id' => $this->org->id, 'public_id' => (string) Str::ulid(), 'member_id' => $m->id, 'status' => 'active']);
        $this->post(route('cards.block', $card), ['status' => 'lost'])->assertRedirect();
        $this->assertSame('lost', $card->fresh()->status);

        $this->delete(route('members.destroy', $m))->assertRedirect(route('members.index'));
        $this->assertSoftDeleted('members', ['id' => $m->id]);
    }

    public function test_user_management_device_settings_and_export(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('users.store'), ['name' => 'Petugas', 'email' => 'p@x.test', 'role' => 'scanner'])->assertSessionHas('status');
        $p = User::where('email', 'p@x.test')->firstOrFail();
        $this->assertSame($this->org->id, $p->organization_id);
        $this->post(route('users.store'), ['name' => 'X', 'email' => 'x@x.test', 'role' => 'division_admin'])->assertSessionHasErrors('division_id');
        $this->post(route('users.reset-password', $p))->assertSessionHas('status');
        $this->post(route('users.toggle', $p));
        $this->assertNotNull($p->fresh()->disabled_at);
        $this->post(route('users.toggle', $this->admin))->assertStatus(422);

        $device = Device::create(['user_id' => $p->id, 'uuid' => 'd1', 'name' => 'HP']);
        $this->post(route('devices.revoke', $device))->assertRedirect();
        $this->assertNotNull($device->fresh()->revoked_at);

        $this->post(route('settings.terms.store'), ['name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
        $t2 = Term::where('name', '2027')->firstOrFail();
        $this->post(route('settings.terms.current', $t2));
        $this->assertTrue($t2->fresh()->is_current);
        $this->assertFalse($this->term->fresh()->is_current);

        $this->post(route('exports.store'), ['report' => 'event', 'event_id' => $this->event->id, 'format' => 'xlsx'])->assertRedirect(route('reports.index', ['tab' => 'ekspor']));
        $export = \App\Models\Export::firstOrFail();
        $this->assertSame('ready', $export->status);
        $this->get(route('exports.download', $export))->assertOk()->assertDownload();
        $this->assertTrue(AuditLog::where('action', 'user.created')->exists());
    }

    public function test_other_organization_data_is_not_reachable(): void
    {
        $org2 = Organization::create(['name' => 'Lain', 'slug' => 'lain']);
        $term2 = Term::create(['organization_id' => $org2->id, 'name' => 'X', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $m2 = Member::create(['organization_id' => $org2->id, 'member_no' => 'Z', 'name' => 'Orang Lain']);
        $e2 = Event::create($this->event->only(['starts_at', 'ends_at', 'checkin_opens_at', 'late_after', 'checkin_closes_at'])
            + ['organization_id' => $org2->id, 'term_id' => $term2->id, 'title' => 'Acara Lain']);
        $u2 = User::factory()->create(['organization_id' => $org2->id, 'role' => 'scanner']);
        $this->actingAs($this->admin);

        $this->get(route('events.show', $e2))->assertNotFound();
        $this->get(route('members.show', $m2))->assertNotFound();
        $this->get(route('users.edit', $u2))->assertNotFound();
        $this->post(route('users.reset-password', $u2))->assertNotFound();
        $this->get(route('events.index', ['tab' => 'berlangsung']))->assertDontSee('Acara Lain');
        $this->get(route('users.index'))->assertDontSee($u2->email);
    }
}
