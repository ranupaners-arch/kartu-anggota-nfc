<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Peran/divisi akun diubah Super Admin → semua sesi app akun itu dicabut agar hak baru langsung berlaku. */
class RoleChangeSignOutTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $petugas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->admin = User::factory()->create(['organization_id' => $org->id, 'role' => 'super_admin']);
        $this->petugas = User::factory()->create(['organization_id' => $org->id, 'role' => 'scanner', 'name' => 'Sari']);
        $this->petugas->createToken('hp-petugas');
    }

    public function test_changing_role_signs_the_user_out_of_every_device(): void
    {
        $this->actingAs($this->admin)->put("/pengguna/{$this->petugas->id}", ['name' => 'Sari', 'email' => $this->petugas->email, 'role' => 'member'])->assertRedirect();
        $this->assertSame('member', $this->petugas->fresh()->role);
        $this->assertSame(0, $this->petugas->tokens()->count());
    }

    public function test_editing_only_the_name_keeps_sessions(): void
    {
        $this->actingAs($this->admin)->put("/pengguna/{$this->petugas->id}", ['name' => 'Sari Dewi', 'email' => $this->petugas->email, 'role' => 'scanner'])->assertRedirect();
        $this->assertSame('Sari Dewi', $this->petugas->fresh()->name);
        $this->assertSame(1, $this->petugas->tokens()->count());
    }
}
