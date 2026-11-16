<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_instance_identity_is_public_and_minimal(): void
    {
        config(['attendance.instance_name' => 'HMPS']);
        Organization::create(['name' => 'Rahasia', 'slug' => 'rahasia']);

        $this->getJson('/api/instance')->assertOk()
            ->assertExactJson(['app' => 'kartu-anggota', 'name' => 'HMPS', 'api_version' => 1]);
    }

    public function test_settings_shows_connect_qr_payload(): void
    {
        config(['attendance.instance_name' => 'HMPS']);
        $org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        Term::create(['organization_id' => $org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $admin = User::factory()->create(['organization_id' => $org->id, 'role' => 'super_admin']);

        $server = rtrim(url('/'), '/'); // alamat server yang sedang diakses pengurus
        $this->actingAs($admin)->get('/pengaturan?tab=aplikasi')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('connect.server', $server)
            ->where('connect.payload', 'kartuanggota://connect?'.http_build_query(['server' => $server, 'name' => 'HMPS']))
            ->where('connect.https', false));
    }
}
