<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin', 'email' => 'admin@x.test', 'password' => 'rahasia123']);
    }

    private function code(User $u, int $offsetSteps = 0): string
    {
        $g = new Google2FA;

        return $g->oathTotp($u->fresh()->two_factor_secret, $g->getTimestamp() + $offsetSteps);
    }

    /** Aktifkan 2FA lewat Akun Saya; kembalikan kode cadangan. */
    private function enable(User $u): array
    {
        $this->actingAs($u)->post('/akun/2fa')->assertRedirect();
        $this->actingAs($u)->post('/akun/2fa/konfirmasi', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->actingAs($u)->post('/akun/2fa/konfirmasi', ['code' => $this->code($u)])->assertSessionHasNoErrors();
        $codes = session('2fa.recovery_codes');
        auth()->logout();
        $this->flushSession();

        return $codes;
    }

    public function test_enable_stores_secret_encrypted_and_shows_recovery_codes_once(): void
    {
        $codes = $this->enable($this->admin);
        $this->assertCount(8, $codes);
        $u = $this->admin->fresh();
        $this->assertNotNull($u->two_factor_confirmed_at);
        $raw = DB::table('users')->where('id', $u->id)->first();
        $this->assertNotSame($u->two_factor_secret, $raw->two_factor_secret); // terenkripsi di DB
        $this->assertStringNotContainsString(str_replace('-', '', $codes[0]), $raw->two_factor_recovery_codes);
        $this->assertArrayNotHasKey('two_factor_secret', $u->toArray());
    }

    public function test_dashboard_login_requires_code_and_rejects_reuse(): void
    {
        $this->enable($this->admin);

        $this->post('/masuk', ['email' => 'admin@x.test', 'password' => 'rahasia123'])->assertRedirect('/masuk/2fa');
        $this->assertGuest();
        $this->get('/')->assertRedirect('/masuk'); // belum login penuh
        $this->get('/masuk/2fa')->assertOk();

        $this->post('/masuk/2fa', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $code = $this->code($this->admin, 1); // langkah berikutnya (langkah kini sudah dipakai saat konfirmasi)
        $this->post('/masuk/2fa', ['code' => $code])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->admin);

        // Kode yang sama tidak bisa dipakai lagi.
        $this->post('/keluar');
        $this->post('/masuk', ['email' => 'admin@x.test', 'password' => 'rahasia123']);
        $this->post('/masuk/2fa', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_recovery_code_works_once(): void
    {
        $codes = $this->enable($this->admin);
        $this->post('/masuk', ['email' => 'admin@x.test', 'password' => 'rahasia123']);
        $this->post('/masuk/2fa', ['code' => strtolower($codes[0])])->assertRedirect('/');
        $this->assertSame(7, count($this->admin->fresh()->two_factor_recovery_codes));

        $this->post('/keluar');
        $this->post('/masuk', ['email' => 'admin@x.test', 'password' => 'rahasia123']);
        $this->post('/masuk/2fa', ['code' => $codes[0]])->assertSessionHasErrors('code');
    }

    public function test_pending_login_expires(): void
    {
        $this->enable($this->admin);
        $this->post('/masuk', ['email' => 'admin@x.test', 'password' => 'rahasia123']);
        $this->travel(6)->minutes();
        $this->post('/masuk/2fa', ['code' => $this->code($this->admin, 1)])->assertRedirect('/masuk');
        $this->assertGuest();
    }

    public function test_api_login_requires_otp(): void
    {
        $this->enable($this->admin);
        $body = ['email' => 'admin@x.test', 'password' => 'rahasia123', 'device_uuid' => 'dev-1', 'device_name' => 'HP'];
        $this->postJson('/api/auth/login', $body)->assertStatus(422)->assertJsonPath('otp_required', true);
        $this->postJson('/api/auth/login', $body + ['otp' => '000000'])->assertStatus(422)->assertJsonPath('otp_required', true);
        $this->postJson('/api/auth/login', $body + ['otp' => $this->code($this->admin, 1)])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_disable_needs_password_and_super_admin_can_reset_others(): void
    {
        $this->enable($this->admin);
        $this->actingAs($this->admin)->delete('/akun/2fa', ['password' => 'salah'])->assertSessionHasErrors('password');
        $this->assertNotNull($this->admin->fresh()->two_factor_confirmed_at);

        $other = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin']);
        $this->enable($other);
        $this->actingAs($other)->post("/pengguna/{$this->admin->id}/reset-2fa")->assertForbidden(); // bukan super admin
        $this->actingAs($this->admin)->post("/pengguna/{$this->admin->id}/reset-2fa")->assertStatus(422); // akun sendiri lewat Akun Saya
        $this->actingAs($this->admin)->post("/pengguna/{$other->id}/reset-2fa")->assertRedirect();
        $this->assertNull($other->fresh()->two_factor_confirmed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.2fa_reset', 'entity_id' => $other->id]);

        $this->actingAs($this->admin)->delete('/akun/2fa', ['password' => 'rahasia123'])->assertRedirect();
        $this->assertNull($this->admin->fresh()->two_factor_secret);
    }

    public function test_emergency_command(): void
    {
        $this->enable($this->admin);
        $this->artisan('app:2fa-reset', ['email' => 'admin@x.test'])->assertSuccessful();
        $this->assertNull($this->admin->fresh()->two_factor_confirmed_at);
        $this->artisan('app:2fa-reset', ['email' => 'tidak@ada.test'])->assertFailed();
    }

    public function test_account_page_shows_setup_qr_while_enrolling(): void
    {
        $this->actingAs($this->admin)->post('/akun/2fa');
        $this->actingAs($this->admin)->get('/akun')->assertOk()
            ->assertInertia(fn ($p) => $p->component('account/edit')->where('twoFactor.enabled', false)
                ->where('twoFactor.setup.otpauthUrl', fn ($u) => str_starts_with($u, 'otpauth://totp/')));
    }
}
