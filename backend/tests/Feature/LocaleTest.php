<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_messages_are_indonesian(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'bukan-email', 'password' => ''])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Email harus berupa alamat email yang valid.')
            ->assertJsonPath('errors.password.0', 'Password wajib diisi.')
            ->assertJsonPath('errors.device_uuid.0', 'ID perangkat wajib diisi.');
    }

    public function test_every_english_rule_has_indonesian_message(): void
    {
        $en = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $id = require lang_path('id/validation.php');
        foreach ($en as $rule => $msg) {
            if ($rule === 'custom') {
                continue;
            }
            $this->assertArrayHasKey($rule, $id, $rule);
            foreach (is_array($msg) ? array_keys($msg) : [] as $sub) {
                $this->assertArrayHasKey($sub, $id[$rule], "$rule.$sub");
            }
        }
    }

    public function test_default_http_errors_are_indonesian(): void
    {
        $this->getJson('/api/me')->assertStatus(401)->assertJsonPath('message', 'Sesi berakhir. Silakan masuk lagi.');

        $org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $member = User::factory()->create(['organization_id' => $org->id, 'role' => 'member']);
        $this->actingAs($member, 'sanctum')->getJson('/api/devices')->assertStatus(403)
            ->assertJsonPath('message', fn ($m) => ! str_contains($m, 'unauthorized') && $m !== '');
        $scanner = User::factory()->create(['organization_id' => $org->id, 'role' => 'scanner']);
        $this->actingAs($scanner, 'sanctum')->getJson('/api/events/999999/roster')->assertStatus(404)->assertJsonPath('message', 'Data tidak ditemukan.');
    }
}
