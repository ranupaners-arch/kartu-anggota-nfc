<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Daftar akun di app → kartu kosong → e-profil → menunggu → disahkan Super Admin → kartu aktif. */
class EProfileTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Term $term;
    private Division $humas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
    }

    private function signUp(string $email = 'dina@mhs.test'): array
    {
        return $this->postJson('/api/auth/register', ['name' => 'Dina', 'email' => $email, 'password' => 'RahasiaBaru123',
            'password_confirmation' => 'RahasiaBaru123', 'device_uuid' => 'hp-'.$email, 'device_name' => 'HP Dina'])->assertCreated()->json();
    }

    public function test_app_sign_up_only_while_registration_open(): void
    {
        $this->getJson('/api/instance')->assertJsonPath('registration_open', false);
        $this->postJson('/api/auth/register', ['name' => 'X', 'email' => 'x@mhs.test', 'password' => 'RahasiaBaru123', 'password_confirmation' => 'RahasiaBaru123', 'device_uuid' => 'a', 'device_name' => 'b'])
            ->assertForbidden();

        app(RegistrationService::class)->open($this->org);
        $this->getJson('/api/instance')->assertJsonPath('registration_open', true);
        $res = $this->signUp();
        $this->assertSame(['member', null, false], [$res['user']['role'], $res['user']['member_id'], $res['user']['must_change_password']]);
        $this->postJson('/api/auth/register', ['name' => 'Dina', 'email' => 'DINA@mhs.test', 'password' => 'RahasiaBaru123', 'password_confirmation' => 'RahasiaBaru123', 'device_uuid' => 'c', 'device_name' => 'd'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_e_profile_flow_until_card_is_active(): void
    {
        app(RegistrationService::class)->open($this->org);
        $h = ['Authorization' => 'Bearer '.$this->signUp()['token']];

        $this->getJson('/api/me/card', $h)->assertJsonPath('state', 'none');
        $this->getJson('/api/me/profile-form', $h)->assertJsonPath('organization', 'HMPS')->assertJsonPath('divisions.0.name', 'Humas')->assertJsonPath('submitted', false);

        $this->post('/api/me/profile', ['name' => 'Dina Ayu', 'member_no' => '2501001', 'division_id' => $this->humas->id, 'consent' => '1',
            'photo' => UploadedFile::fake()->image('wajah.jpg', 300, 400)], $h + ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('state', 'pending')->assertJsonPath('member.name', 'Dina Ayu');
        $user = User::where('email', 'dina@mhs.test')->sole();
        $m = Member::findOrFail($user->member_id);
        $this->assertSame(['2501001', 'dina@mhs.test', 'Dina Ayu'], [$m->member_no, $m->email, $user->name]);
        $this->assertSame('applicant', Membership::where('member_id', $m->id)->value('status'));
        $this->postJson('/api/me/profile', ['name' => 'Lagi', 'member_no' => '2501002', 'consent' => '1'], $h)->assertUnprocessable(); // sekali saja

        // Disahkan Super Admin (tab Pendaftar → Ubah status → Aktif).
        $admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        auth()->forgetGuards();
        // Permintaan API sebelumnya memindah guard bawaan ke sanctum; dashboard memakai guard web.
        $this->actingAs($admin, 'web')->get('/anggota?tab=pendaftar')->assertInertia(fn (Assert $p) => $p->where('members.data.0.name', 'Dina Ayu'));
        $this->actingAs($admin, 'web')->post('/anggota/massal', ['ids' => [$m->id], 'action' => 'status', 'value' => 'active']);
        auth()->forgetGuards();
        $this->getJson('/api/me/card', $h)->assertJsonPath('state', 'active')->assertJsonPath('member.division', 'Humas');
    }

    public function test_email_already_used_by_existing_member_is_flagged(): void
    {
        app(RegistrationService::class)->open($this->org);
        Member::create(['organization_id' => $this->org->id, 'member_no' => 'LAMA', 'name' => 'Dina Lama', 'email' => 'dina@mhs.test']);
        $h = ['Authorization' => 'Bearer '.$this->signUp()['token']];
        $this->postJson('/api/me/profile', ['name' => 'Dina', 'member_no' => '2501001', 'consent' => '1'], $h)->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_web_portal_for_account_without_e_profile(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => null, 'email' => 'eko@mhs.test', 'password' => 'RahasiaLama123']);
        $this->post('/masuk', ['email' => 'eko@mhs.test', 'password' => 'RahasiaLama123'])->assertRedirect('/saya');
        $this->get('/saya')->assertOk()->assertInertia(fn (Assert $p) => $p->where('card.state', 'none')->where('qr', null));
        $this->get('/saya/kegiatan')->assertRedirect('/saya');
        $this->get('/saya/e-profil')->assertOk()->assertInertia(fn (Assert $p) => $p->component('portal/profile')->where('email', 'eko@mhs.test'));
        $this->post('/saya/e-profil', ['name' => 'Eko', 'member_no' => '2501009', 'consent' => '1'])->assertRedirect('/saya');
        $this->get('/saya')->assertInertia(fn (Assert $p) => $p->where('card.state', 'pending')->where('qr', null));
        $this->assertNotNull($user->fresh()->member_id);
    }
}
