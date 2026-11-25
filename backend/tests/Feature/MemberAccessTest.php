<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Division;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Admin Divisi melihat anggota divisinya (baca-saja); arsip & pulihkan anggota oleh Super Admin. */
class MemberAccessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private User $admin;

    private User $humasAdmin;

    private Member $humasMember;

    private Member $intiMember;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $inti = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->humasAdmin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $humas->id]);
        $this->humasMember = $this->member('H1', $humas);
        $this->intiMember = $this->member('I1', $inti);
    }

    private function member(string $no, Division $d): Member
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $no, 'name' => "Anggota $no"]);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'division_id' => $d->id, 'status' => 'active']);

        return $m;
    }

    public function test_division_admin_sees_only_own_division_read_only(): void
    {
        $this->actingAs($this->humasAdmin)->get('/anggota')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('canManage', false)->has('members.data', 1)->where('members.data.0.member_no', 'H1')->where('divisions', []));
        $this->actingAs($this->humasAdmin)->get('/anggota?tab=arsip')->assertInertia(fn (Assert $p) => $p->where('tab', 'arsip')->has('members.data', 1)->where('members.data.0.member_no', 'H1')); // arsip bukan untuk Admin Divisi: tetap daftar biasa
        $this->actingAs($this->humasAdmin)->get("/anggota/{$this->humasMember->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('canManage', false));
        $this->actingAs($this->humasAdmin)->get("/anggota/{$this->intiMember->id}")->assertForbidden();
        $this->actingAs($this->humasAdmin)->get("/anggota/{$this->intiMember->id}/foto")->assertForbidden();

        // Tetap tidak bisa mengubah apa pun.
        $this->actingAs($this->humasAdmin)->get('/anggota/create')->assertForbidden();
        $this->actingAs($this->humasAdmin)->put("/anggota/{$this->humasMember->id}", ['name' => 'X'])->assertForbidden();
        $this->actingAs($this->humasAdmin)->delete("/anggota/{$this->humasMember->id}")->assertForbidden();
        $this->actingAs($this->humasAdmin)->get('/anggota/impor')->assertForbidden();
    }

    public function test_super_admin_archive_and_restore(): void
    {
        $card = Card::create(['organization_id' => $this->org->id, 'public_id' => '01ARSIPKARTU0000000000000A', 'key_id' => 'k1', 'status' => 'active', 'member_id' => $this->humasMember->id, 'issued_at' => now()]);
        $this->actingAs($this->admin)->delete("/anggota/{$this->humasMember->id}")->assertRedirect();
        $this->actingAs($this->admin)->get('/anggota')->assertInertia(fn (Assert $p) => $p->has('members.data', 1));
        $this->actingAs($this->admin)->get('/anggota?tab=arsip')->assertInertia(fn (Assert $p) => $p
            ->where('canManage', true)->has('members.data', 1)->where('members.data.0.member_no', 'H1'));

        $this->actingAs($this->humasAdmin)->post("/anggota/{$this->humasMember->id}/pulihkan")->assertForbidden();
        $this->actingAs($this->admin)->post("/anggota/{$this->humasMember->id}/pulihkan")->assertRedirect("/anggota/{$this->humasMember->id}");
        $this->assertFalse($this->humasMember->fresh()->trashed());
        $this->assertSame('blocked', $card->fresh()->status); // kartu lama tidak aktif lagi
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.restored', 'entity_id' => $this->humasMember->id]);
    }

    public function test_new_member_cannot_reuse_archived_number(): void
    {
        $this->actingAs($this->admin)->delete("/anggota/{$this->humasMember->id}");
        $this->actingAs($this->admin)->post('/anggota', ['member_no' => 'H1', 'name' => 'Pengganti', 'consent' => '1'])
            ->assertSessionHasErrors(['member_no' => 'Nomor anggota sudah dipakai. Periksa juga tab Arsip.']);
        $this->assertSame(1, Member::where('member_no', 'H1')->withTrashed()->count());
    }
}
