<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Lupa password mandiri lewat email — hanya aktif bila email server sudah diatur. */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
    }

    private function user(string $role = 'member', array $extra = []): User
    {
        return User::factory()->create(['organization_id' => $this->org->id, 'role' => $role, 'email' => "$role@hmps.test"] + $extra);
    }

    public function test_disabled_until_mail_is_configured(): void
    {
        config(['mail.default' => 'log']);
        $this->user();
        $this->get('/masuk')->assertInertia(fn (Assert $p) => $p->where('canResetPassword', false));
        $this->get('/lupa-password')->assertInertia(fn (Assert $p) => $p->component('auth/forgot-password')->where('enabled', false));
        $this->postJson('/api/auth/forgot', ['email' => 'member@hmps.test'])->assertStatus(503)->assertJsonPath('message', fn ($m) => str_contains($m, 'Hubungi pengurus'));
        Notification::assertNothingSent();
    }

    public function test_sends_indonesian_link_without_revealing_whether_email_exists(): void
    {
        config(['mail.default' => 'smtp']);
        $member = $this->user();
        $this->user('scanner', ['disabled_at' => now()]);

        $this->get('/masuk')->assertInertia(fn (Assert $p) => $p->where('canResetPassword', true));
        $this->post('/lupa-password', ['email' => ' Member@HMPS.test '])->assertSessionHas('status');
        $this->post('/lupa-password', ['email' => 'tidakada@hmps.test'])->assertSessionHas('status');
        $this->postJson('/api/auth/forgot', ['email' => 'scanner@hmps.test'])->assertOk(); // nonaktif: tidak dikirim, jawaban sama

        Notification::assertSentToTimes($member, ResetPasswordNotification::class, 1);
        Notification::assertCount(1);
        Notification::assertSentTo($member, ResetPasswordNotification::class, function ($n) use ($member) {
            $mail = $n->toMail($member);

            $html = (string) $mail->render();

            return $mail->subject === 'Atur ulang password Kartu Anggota' && str_contains($mail->actionUrl, '/atur-password/')
                && str_contains($html, 'Bila tombol') && str_contains($html, 'Hak cipta') && ! str_contains($html, 'All rights reserved');
        });
    }

    public function test_member_resets_password_and_is_logged_out_everywhere(): void
    {
        config(['mail.default' => 'smtp']);
        $member = $this->user('member', ['must_change_password' => true]);
        $member->createToken('hp-lama');
        $token = Password::broker()->createToken($member);

        $this->get("/atur-password/$token?email=member@hmps.test")->assertInertia(fn (Assert $p) => $p->component('auth/reset-password')->where('email', 'member@hmps.test'));
        $this->post('/atur-password', ['token' => $token, 'email' => 'member@hmps.test', 'password' => 'pendek', 'password_confirmation' => 'pendek'])
            ->assertSessionHasErrors('password');
        $this->post('/atur-password', ['token' => $token, 'email' => 'member@hmps.test', 'password' => 'RahasiaBaru123', 'password_confirmation' => 'RahasiaBaru123'])
            ->assertOk()->assertInertia(fn (Assert $p) => $p->where('done', true));

        $member->refresh();
        $this->assertTrue(Hash::check('RahasiaBaru123', $member->password));
        $this->assertFalse($member->must_change_password);
        $this->assertSame(0, $member->tokens()->count());

        // Tautan sekali pakai.
        $this->post('/atur-password', ['token' => $token, 'email' => 'member@hmps.test', 'password' => 'RahasiaLagi123', 'password_confirmation' => 'RahasiaLagi123'])
            ->assertSessionHasErrors('email');
    }

    public function test_admin_is_sent_back_to_login_after_reset(): void
    {
        $admin = $this->user('super_admin');
        $token = Password::broker()->createToken($admin);
        $this->post('/atur-password', ['token' => $token, 'email' => 'super_admin@hmps.test', 'password' => 'RahasiaBaru123', 'password_confirmation' => 'RahasiaBaru123'])
            ->assertRedirect('/masuk')->assertSessionHas('status', 'Password diganti. Silakan masuk.');
    }
}
