<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Division;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Division $humas;
    private Division $inti;
    private User $admin;
    private User $humasAdmin;
    private User $memberUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->inti = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->humasAdmin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $this->humas->id]);
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => '1', 'name' => 'A']);
        Membership::create(['member_id' => $m->id, 'term_id' => $term->id, 'division_id' => $this->humas->id, 'status' => 'active']);
        $this->memberUser = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $m->id]);
    }

    public function test_admin_creates_and_member_sees_relevant_active_announcements(): void
    {
        $this->actingAs($this->admin)->post('/pengumuman', ['title' => 'Libur', 'body' => 'Sekre tutup', 'pinned' => true])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->admin)->post('/pengumuman', ['title' => 'Rapat Inti', 'body' => 'x', 'division_id' => $this->inti->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->admin)->post('/pengumuman', ['title' => 'Humas kumpul', 'body' => 'y', 'division_id' => $this->humas->id])->assertSessionHasNoErrors()->assertRedirect();
        Announcement::create(['organization_id' => $this->org->id, 'title' => 'Lama', 'body' => 'z', 'expires_at' => now()->subDay()]);

        $titles = collect($this->actingAs($this->memberUser, 'sanctum')->getJson('/api/me/announcements')->assertOk()->json('data'))->pluck('title')->all();
        $this->assertSame(['Libur', 'Humas kumpul'], $titles); // disematkan dulu; Inti & kedaluwarsa tidak tampil
        $this->actingAs($this->admin)->post('/pengumuman', ['title' => 'x', 'body' => 'y', 'expires_at' => now()->subHour()->toDateTimeString()])->assertSessionHasErrors('expires_at');
    }

    public function test_division_admin_limited_to_own_division(): void
    {
        $this->actingAs($this->humasAdmin)->post('/pengumuman', ['title' => 'Coba semua', 'body' => 'x', 'division_id' => $this->inti->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($this->humas->id, Announcement::sole()->division_id); // dipaksa ke divisinya
        $global = Announcement::create(['organization_id' => $this->org->id, 'title' => 'Umum', 'body' => 'x']);
        $inti = Announcement::create(['organization_id' => $this->org->id, 'title' => 'Inti', 'body' => 'x', 'division_id' => $this->inti->id]);

        $this->actingAs($this->humasAdmin)->get('/pengumuman')->assertInertia(fn (Assert $p) => $p
            ->has('items.data', 2)->where('canTargetAll', false)
            ->where('items.data', fn ($d) => collect($d)->firstWhere('title', 'Umum')['canEdit'] === false));
        $this->actingAs($this->humasAdmin)->delete("/pengumuman/{$global->id}")->assertForbidden();
        $this->actingAs($this->humasAdmin)->put("/pengumuman/{$inti->id}", ['title' => 'x', 'body' => 'y'])->assertForbidden();
        $this->actingAs($this->admin)->delete("/pengumuman/{$global->id}")->assertRedirect();
        $this->assertModelMissing($global);
    }
}
