<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetupCommandTest extends TestCase
{
    use RefreshDatabase;

    private function opts(array $over = []): array
    {
        return $over + [
            '--org-name' => 'HMPS Informatika', '--org-slug' => 'hmps-if', '--term-name' => '2026/2027',
            '--term-start' => '2026-08-01', '--term-end' => '2027-07-31',
            '--admin-name' => 'Ketua', '--admin-email' => 'ketua@hmps.test',
        ];
    }

    public function test_setup_creates_org_term_and_super_admin_that_can_log_in(): void
    {
        $this->artisan('app:setup', $this->opts() + ['--no-interaction' => true])
            ->expectsOutputToContain('Password ini hanya ditampilkan sekali')
            ->assertSuccessful();

        $org = Organization::where('slug', 'hmps-if')->firstOrFail();
        $this->assertTrue($org->terms()->where('is_current', true)->exists());
        $admin = User::where('email', 'ketua@hmps.test')->firstOrFail();
        $this->assertSame(['super_admin', $org->id], [$admin->role, $admin->organization_id]);
        $this->assertNotSame('', $admin->password);
        $this->assertFalse(Hash::check('password', $admin->password)); // bukan password bawaan
    }

    public function test_setup_rejects_duplicates_and_invalid_input(): void
    {
        $this->artisan('app:setup', $this->opts() + ['--no-interaction' => true])->assertSuccessful();

        $this->artisan('app:setup', $this->opts(['--admin-email' => 'lain@hmps.test']) + ['--no-interaction' => true])->assertFailed(); // slug dipakai
        $this->artisan('app:setup', $this->opts(['--org-slug' => 'baru']) + ['--no-interaction' => true])->assertFailed(); // email dipakai
        $this->artisan('app:setup', $this->opts(['--org-slug' => 'x2', '--admin-email' => 'y@z.test', '--term-end' => '2026-01-01']) + ['--no-interaction' => true])->assertFailed();
        $this->assertSame(1, Organization::count());
    }
}
