<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Akun app anggota dibuat massal; password sementara wajib diganti saat pertama masuk. */
class MemberAccountTest extends TestCase
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
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
    }

    private function member(string $no, ?string $email): Member
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $no, 'name' => "Anggota $no", 'email' => $email]);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'division_id' => $this->humas->id, 'status' => 'active']);

        return $m;
    }

    public function test_bulk_creates_accounts_and_reports_skipped_members_once(): void
    {
        $a = $this->member('A1', ' Ani@Mhs.test ');
        $b = $this->member('B1', null);
        $c = $this->member('C1', 'citra@mhs.test');
        User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $c->id]);
        $d = $this->member('D1', 'dipakai@mhs.test');
        User::factory()->create(['organization_id' => $this->org->id, 'role' => 'scanner', 'email' => 'dipakai@mhs.test']);

        $this->actingAs($this->admin)->post('/anggota/akun-app', ['ids' => [$a->id, $b->id, $c->id, $d->id]])
            ->assertRedirect('/anggota/akun-app/hasil');

        $this->actingAs($this->admin)->get('/anggota/akun-app/hasil')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('members/account-slips')
            ->has('created', 1)->where('created.0.email', 'ani@mhs.test')->where('created.0.password', fn ($pw) => (bool) preg_match('/^[A-HJ-NP-Za-km-z2-9]{4}(-[A-HJ-NP-Za-km-z2-9]{4}){2}$/', $pw))
            ->where('connect.payload', fn ($v) => str_starts_with($v, 'kartuanggota://connect?server='))
            ->has('skipped', 3)
            ->where('skipped', fn ($s) => collect($s)->pluck('reason', 'member_no')->all() === [
                'B1' => 'Belum ada email yang valid', 'C1' => 'Sudah punya akun', 'D1' => 'Email sudah dipakai akun lain',
            ]));
        // Hanya sekali: dimuat ulang → kembali ke daftar anggota.
        $this->actingAs($this->admin)->get('/anggota/akun-app/hasil')->assertRedirect('/anggota');

        $user = User::where('email', 'ani@mhs.test')->firstOrFail();
        $this->assertSame([User::MEMBER, $a->id, true], [$user->role, $user->member_id, $user->must_change_password]);

        $this->actingAs($this->admin)->get('/anggota')->assertInertia(fn (Assert $p) => $p
            ->where('members.data', fn ($rows) => collect($rows)->firstWhere('member_no', 'A1')['has_account'] === true
                && collect($rows)->firstWhere('member_no', 'B1')['has_account'] === false));
        // Filter kesiapan: yang belum punya akun app (A1 & C1 sudah punya).
        $this->actingAs($this->admin)->get('/anggota?siap=tanpa-akun')->assertInertia(fn (Assert $p) => $p
            ->where('filters.siap', 'tanpa-akun')
            ->where('members.data', fn ($rows) => collect($rows)->pluck('member_no')->sort()->values()->all() === ['B1', 'D1']));
        $this->actingAs($this->admin)->get('/anggota?siap=tanpa-kartu')->assertInertia(fn (Assert $p) => $p->has('members.data', 4));
    }

    public function test_temporary_password_must_be_changed_before_using_the_api(): void
    {
        $m = $this->member('A1', 'ani@mhs.test');
        $this->actingAs($this->admin)->post('/anggota/akun-app', ['ids' => [$m->id]]);
        $password = session('accountSlips')['created'][0]['password'];
        auth()->forgetGuards();

        $login = $this->postJson('/api/auth/login', ['email' => 'ani@mhs.test', 'password' => $password, 'device_uuid' => 'hp-1', 'device_name' => 'HP'])
            ->assertOk()->assertJsonPath('user.must_change_password', true);
        $h = ['Authorization' => 'Bearer '.$login->json('token')];

        $this->getJson('/api/me/events', $h)->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->getJson('/api/me', $h)->assertOk();
        $this->putJson('/api/me/password', ['current_password' => 'salah', 'password' => 'RahasiaBaru123', 'password_confirmation' => 'RahasiaBaru123'], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/me/password', ['current_password' => $password, 'password' => 'pendek', 'password_confirmation' => 'pendek'], $h)
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/api/me/password', ['current_password' => $password, 'password' => 'RahasiaBaru123', 'password_confirmation' => 'RahasiaBaru123'], $h)
            ->assertOk()->assertJsonPath('must_change_password', false);

        auth()->forgetGuards();
        $this->getJson('/api/me/events', $h)->assertOk();
    }

    public function test_staff_with_temporary_password_is_sent_to_account_page(): void
    {
        $this->actingAs($this->admin)->post('/pengguna', ['name' => 'Dina', 'email' => 'dina@hmps.test', 'role' => 'division_admin', 'division_id' => $this->humas->id]);
        $dina = User::where('email', 'dina@hmps.test')->firstOrFail();
        $this->assertTrue($dina->must_change_password);

        $this->actingAs($dina)->get('/')->assertRedirect('/akun');
        $this->actingAs($dina)->get('/akun')->assertOk()->assertInertia(fn (Assert $p) => $p->where('auth.mustChangePassword', true));
        $dina->update(['password' => 'SementaraLama1']);
        $this->actingAs($dina)->put('/akun/password', ['current_password' => 'SementaraLama1', 'password' => 'RahasiaBaru123', 'password_confirmation' => 'RahasiaBaru123'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($dina->fresh()->must_change_password);
        $this->actingAs($dina->fresh())->get('/')->assertOk();
    }

    public function test_only_super_admin_creates_accounts(): void
    {
        $m = $this->member('A1', 'ani@mhs.test');
        $da = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $this->humas->id]);
        $this->actingAs($da)->post('/anggota/akun-app', ['ids' => [$m->id]])->assertForbidden();
        $this->assertSame(0, User::where('member_id', $m->id)->count());
    }
}
