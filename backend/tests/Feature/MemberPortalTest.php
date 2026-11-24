<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Event;
use App\Models\EventTarget;
use App\Models\ExcuseRequest;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Portal web anggota (/saya) untuk yang tidak memakai app Android. */
class MemberPortalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private Division $humas;

    private Member $budi;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->budi = Member::create(['organization_id' => $this->org->id, 'member_no' => 'B01', 'name' => 'Budi']);
        Membership::create(['member_id' => $this->budi->id, 'term_id' => $this->term->id, 'division_id' => $this->humas->id, 'status' => 'active']);
        $this->member = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $this->budi->id,
            'email' => 'budi@mhs.test', 'password' => 'RahasiaLama123']);
    }

    private function event(string $title, ?Division $target = null): Event
    {
        $s = now()->addDay();
        $e = Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => $title, 'is_mandatory' => true,
            'starts_at' => $s, 'ends_at' => $s->copy()->addHour(), 'checkin_opens_at' => $s->copy()->subMinutes(30),
            'late_after' => $s->copy()->addMinutes(10), 'checkin_closes_at' => $s->copy()->addHour()]);
        if ($target) {
            EventTarget::create(['event_id' => $e->id, 'division_id' => $target->id]);
        }

        return $e;
    }

    public function test_login_routes_members_to_portal_and_scanners_to_scan_page(): void
    {
        $this->post('/masuk', ['email' => 'budi@mhs.test', 'password' => 'RahasiaLama123'])->assertRedirect('/saya');
        $this->get('/')->assertRedirect('/saya'); // dashboard → kembali ke portal
        $this->post('/keluar')->assertRedirect();
        $this->assertGuest();

        User::factory()->create(['organization_id' => $this->org->id, 'role' => 'scanner', 'email' => 'petugas@hmps.test', 'password' => 'Petugas12345']);
        $this->post('/masuk', ['email' => 'petugas@hmps.test', 'password' => 'Petugas12345'])->assertRedirect('/pindai'); // mode petugas di browser
        $this->get('/saya')->assertRedirect('/pindai'); // petugas → halaman pindai, bukan portal
        $this->post('/keluar');

        $admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->actingAs($admin)->get('/saya')->assertRedirect('/');
    }

    public function test_qr_page_and_polling_returns_rotating_payload(): void
    {
        $this->actingAs($this->member)->get('/saya')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('portal/qr')->where('profile.name', 'Budi')->where('profile.division', 'Humas')
            ->where('qr.payload', fn ($v) => is_string($v) && strlen($v) > 20)->where('qr.expires_in', 90));
    }

    public function test_events_and_excuse_submission_for_self_only(): void
    {
        $mine = $this->event('Rapat Humas', $this->humas);
        $other = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
        $this->event('Rapat Inti', $other);

        $this->actingAs($this->member)->get('/saya/kegiatan')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('portal/events')->has('events', 1)->where('events.0.title', 'Rapat Humas')->where('events.0.can_request_excuse', true));

        $someoneElse = Member::create(['organization_id' => $this->org->id, 'member_no' => 'C01', 'name' => 'Citra']);
        $this->actingAs($this->member)->post('/saya/izin', [
            'event_id' => $mine->id, 'type' => 'sakit', 'reason' => 'Demam', 'member_id' => $someoneElse->id,
            'attachment' => UploadedFile::fake()->image('surat.jpg'),
        ])->assertRedirect()->assertSessionHas('status');

        $x = ExcuseRequest::sole();
        $this->assertSame([$this->budi->id, 'sakit', 'pending'], [$x->member_id, $x->type, $x->status]);
        Storage::disk('local')->assertExists($x->attachment_path);

        $this->actingAs($this->member)->post('/saya/izin', ['event_id' => $mine->id, 'type' => 'izin', 'reason' => ''])->assertSessionHasErrors('reason');
    }

    public function test_history_and_report_card(): void
    {
        $this->actingAs($this->member)->get('/saya/riwayat')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('portal/history')->where('history.term', '2026')->where('history.total', 0));
        $this->actingAs($this->member)->get('/saya/rapor')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_temporary_password_must_be_changed_first(): void
    {
        $this->member->update(['must_change_password' => true]);
        $this->member->createToken('hp');
        $this->actingAs($this->member)->get('/saya')->assertRedirect('/saya/akun');
        $this->actingAs($this->member)->get('/saya/akun')->assertOk()->assertInertia(fn (Assert $p) => $p->where('mustChangePassword', true));

        $this->actingAs($this->member)->put('/saya/password', ['current_password' => 'RahasiaLama123', 'password' => 'RahasiaLama123', 'password_confirmation' => 'RahasiaLama123'])
            ->assertSessionHasErrors('password');
        $this->actingAs($this->member)->put('/saya/password', ['current_password' => 'RahasiaLama123', 'password' => 'RahasiaBaru456', 'password_confirmation' => 'RahasiaBaru456'])
            ->assertRedirect('/saya');
        $this->assertFalse($this->member->fresh()->must_change_password);
        $this->assertSame(0, $this->member->tokens()->count());
        $this->actingAs($this->member->fresh())->get('/saya')->assertOk();
    }

    public function test_calendar_link_from_portal(): void
    {
        $this->actingAs($this->member)->post('/saya/kalender')->assertRedirect();
        $this->assertNotNull($this->member->fresh()->calendar_token);
        $this->actingAs($this->member->fresh())->get('/saya/akun')->assertInertia(fn (Assert $p) => $p->where('calendarUrl', fn ($u) => str_contains($u, '/jadwal/')));
    }
}
