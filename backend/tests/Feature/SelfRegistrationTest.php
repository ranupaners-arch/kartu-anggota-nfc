<?php

namespace Tests\Feature;

use App\Models\Division;
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

/** Pendaftaran anggota mandiri lewat tautan publik. */
class SelfRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Term $term;
    private Division $humas;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
    }

    private function open(): string
    {
        $this->actingAs($this->admin)->post('/pengaturan/pendaftaran', ['action' => 'open'])->assertSessionHas('status');
        auth()->forgetGuards();

        return $this->org->fresh()->registration_token;
    }

    private function form(array $extra = []): array
    {
        return $extra + ['name' => 'Dina Ayu', 'member_no' => '2501001', 'email' => 'Dina@Mhs.test', 'cohort' => '2025',
            'division_id' => $this->humas->id, 'consent' => '1', 'photo' => UploadedFile::fake()->image('wajah.jpg', 300, 400)];
    }

    public function test_applicant_registers_with_photo_and_appears_for_admin(): void
    {
        $token = $this->open();
        $this->get("/daftar/$token")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('register/form')->where('organization', 'HMPS')->has('divisions', 1));

        $this->post("/daftar/$token", $this->form())->assertRedirect("/daftar/$token/terkirim");
        $m = Member::where('member_no', '2501001')->sole();
        $this->assertSame([$this->org->id, 'dina@mhs.test', true], [$m->organization_id, $m->email, $m->consented_at !== null]);
        Storage::disk('local')->assertExists($m->photo_path);
        $ms = Membership::where('member_id', $m->id)->sole();
        $this->assertSame(['applicant', $this->humas->id, $this->term->id], [$ms->status, $ms->division_id, $ms->term_id]);

        $this->actingAs($this->admin)->get('/anggota?tab=pendaftar')->assertInertia(fn (Assert $p) => $p
            ->has('members.data', 1)->where('members.data.0.name', 'Dina Ayu')->where('navBadges.applicants', 1));
        $this->actingAs($this->admin)->get('/anggota')->assertInertia(fn (Assert $p) => $p->has('members.data', 0)); // belum aktif

        // Disetujui: status jadi Aktif lewat tindakan massal.
        $this->actingAs($this->admin)->post('/anggota/massal', ['ids' => [$m->id], 'action' => 'status', 'value' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('active', $ms->fresh()->status);
    }

    public function test_validation_duplicates_consent_and_bot_trap(): void
    {
        $token = $this->open();
        Member::create(['organization_id' => $this->org->id, 'member_no' => '2501001', 'name' => 'Sudah Ada']);

        $this->post("/daftar/$token", $this->form(['consent' => null]))->assertSessionHasErrors(['member_no', 'consent']);
        $other = Organization::create(['name' => 'Lain', 'slug' => 'lain']);
        $otherDiv = Division::create(['organization_id' => $other->id, 'name' => 'Luar']);
        $this->post("/daftar/$token", $this->form(['member_no' => '2501002', 'division_id' => $otherDiv->id]))->assertSessionHasErrors('division_id');

        // Bot mengisi kolom jebakan: dianggap terkirim, tapi tidak disimpan.
        $this->post("/daftar/$token", $this->form(['member_no' => '2501003', 'website' => 'http://spam.test']))->assertRedirect("/daftar/$token/terkirim");
        $this->assertSame(0, Member::where('member_no', '2501003')->count());
    }

    public function test_closed_or_rotated_link_is_not_usable(): void
    {
        $this->get('/daftar/'.str_repeat('a', 24))->assertNotFound()->assertInertia(fn (Assert $p) => $p->component('register/closed'));
        $old = $this->open();
        $this->actingAs($this->admin)->post('/pengaturan/pendaftaran', ['action' => 'rotate']);
        auth()->forgetGuards();
        $this->post("/daftar/$old", $this->form())->assertNotFound();
        $this->actingAs($this->admin)->post('/pengaturan/pendaftaran', ['action' => 'close']);
        $this->assertNull($this->org->fresh()->registration_token);
        $this->actingAs($this->admin)->get('/pengaturan?tab=aplikasi')->assertInertia(fn (Assert $p) => $p->where('registration', null));
    }

    public function test_only_super_admin_manages_registration(): void
    {
        $da = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $this->humas->id]);
        $this->actingAs($da)->post('/pengaturan/pendaftaran', ['action' => 'open'])->assertForbidden();
        $this->assertNull($this->org->fresh()->registration_token);
    }
}
