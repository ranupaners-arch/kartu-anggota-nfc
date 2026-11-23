<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Di belakang Caddy/Nginx: skema & host dari X-Forwarded-* hanya dipercaya dari proxy privat. */
class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        Term::create(['organization_id' => $org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);

        return User::factory()->create(['organization_id' => $org->id, 'role' => 'super_admin']);
    }

    private function forwarded(string $remoteAddr): array
    {
        return [
            'REMOTE_ADDR' => $remoteAddr,
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'absen.contoh.id',
            'HTTP_X_FORWARDED_PORT' => '443',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        ];
    }

    public function test_https_from_private_proxy_is_honoured(): void
    {
        $this->actingAs($this->admin())->withServerVariables($this->forwarded('172.18.0.5'))
            ->get('/pengaturan?tab=aplikasi')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('connect.server', 'https://absen.contoh.id')
                ->where('connect.https', true));
    }

    public function test_forwarded_headers_from_public_address_are_ignored(): void
    {
        $this->actingAs($this->admin())->withServerVariables($this->forwarded('198.51.100.20'))
            ->get('/pengaturan?tab=aplikasi')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('connect.https', false));
    }
}
