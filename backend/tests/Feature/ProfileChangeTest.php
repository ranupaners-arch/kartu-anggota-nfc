<?php

namespace Tests\Feature;

use App\Jobs\SendNotice;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\ProfileChange;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Anggota mengajukan perubahan nama/no. HP/foto; berlaku setelah disetujui Super Admin. */
class ProfileChangeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Member $budi;

    private User $user;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->budi = Member::create(['organization_id' => $this->org->id, 'member_no' => '01', 'name' => 'Budi Santoso', 'phone' => '0811111']);
        Membership::create(['member_id' => $this->budi->id, 'term_id' => $term->id, 'status' => 'active']);
        $this->user = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $this->budi->id]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('wajah.jpg', 600, 800);
    }

    public function test_member_requests_change_and_new_request_replaces_pending(): void
    {
        Sanctum::actingAs($this->user);
        $this->getJson('/api/me/profile-change')->assertOk()
            ->assertJsonPath('current.phone', '0811111')->assertJsonPath('pending', null);

        $this->post('/api/me/profile-change', ['phone' => '0822 2222', 'photo' => $this->photo()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('pending.phone', '0822 2222')->assertJsonPath('pending.has_photo', true);
        $firstPhoto = ProfileChange::first()->photo_path;
        Storage::disk('local')->assertExists($firstPhoto);
        Queue::assertPushed(SendNotice::class, fn ($j) => $j->event === 'newProfileChange');

        $this->postJson('/api/me/profile-change', ['name' => 'Budi Santosa', 'reason' => 'Salah ketik'])
            ->assertCreated()->assertJsonPath('pending.name', 'Budi Santosa')->assertJsonPath('pending.phone', null);
        $this->assertSame(1, ProfileChange::count());
        Storage::disk('local')->assertMissing($firstPhoto); // foto pengajuan lama dibuang
        $this->assertSame('Budi Santoso', $this->budi->fresh()->name); // belum berlaku sebelum disetujui
    }

    public function test_no_change_or_bad_phone_is_rejected(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson('/api/me/profile-change', ['name' => 'Budi Santoso', 'phone' => '0811111'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/me/profile-change', ['phone' => 'telp saya'])->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->assertSame(0, ProfileChange::count());
    }

    public function test_super_admin_approves_and_change_is_applied(): void
    {
        $oldPhoto = 'members/1/lama.jpg';
        Storage::disk('local')->put($oldPhoto, 'x');
        $this->budi->update(['photo_path' => $oldPhoto]);
        Sanctum::actingAs($this->user);
        $this->post('/api/me/profile-change', ['name' => 'Budi Santosa', 'phone' => '08222222', 'photo' => $this->photo()], ['Accept' => 'application/json'])->assertCreated();
        $change = ProfileChange::first();
        $newPhoto = $change->photo_path;

        $this->actingAs($this->admin, 'web');
        auth()->forgetGuards();
        $this->actingAs($this->admin, 'web')->get('/perubahan-data')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('profile-changes/index')->where('items.data.0.name', 'Budi Santosa')->where('items.data.0.member.phone', '0811111')
            ->where('navBadges.profileChanges', 1));
        $this->actingAs($this->admin, 'web')->get("/perubahan-data/{$change->id}/foto")->assertOk();

        $this->actingAs($this->admin, 'web')->post("/perubahan-data/{$change->id}", ['action' => 'approve', 'note' => 'Oke'])->assertRedirect();
        $b = $this->budi->fresh();
        $this->assertSame(['Budi Santosa', '08222222', $newPhoto], [$b->name, $b->phone, $b->photo_path]);
        Storage::disk('local')->assertMissing($oldPhoto);
        Storage::disk('local')->assertExists($newPhoto);
        $this->assertSame('approved', $change->fresh()->status);
        Queue::assertPushed(SendNotice::class, fn ($j) => $j->event === 'profileChangeDecided');

        // Keputusan tampil di lonceng anggota; tidak bisa diputuskan dua kali.
        auth()->forgetGuards();
        Sanctum::actingAs($this->user);
        $this->getJson('/api/me/notifications')->assertJsonPath('data.0.type', 'profile')->assertJsonPath('data.0.title', 'Perubahan data disetujui');
        $this->actingAs($this->admin, 'web')->post("/perubahan-data/{$change->id}", ['action' => 'reject', 'note' => 'Terlambat'])->assertStatus(422);
    }

    public function test_reject_keeps_old_data_and_deletes_submitted_photo(): void
    {
        Sanctum::actingAs($this->user);
        $this->post('/api/me/profile-change', ['phone' => '08222222', 'photo' => $this->photo()], ['Accept' => 'application/json'])->assertCreated();
        $change = ProfileChange::first();
        $path = $change->photo_path;

        auth()->forgetGuards();
        $this->actingAs($this->admin, 'web')->post("/perubahan-data/{$change->id}", ['action' => 'reject'])->assertSessionHasErrors('note'); // alasan wajib
        $this->assertSame('pending', $change->fresh()->status);
        $this->actingAs($this->admin, 'web')->post("/perubahan-data/{$change->id}", ['action' => 'reject', 'note' => 'Foto buram'])->assertRedirect();
        $this->assertSame('0811111', $this->budi->fresh()->phone);
        Storage::disk('local')->assertMissing($path);
        auth()->forgetGuards();
        Sanctum::actingAs($this->user);
        $this->getJson('/api/me/profile-change')->assertJsonPath('pending', null)
            ->assertJsonPath('last_decision.status', 'rejected')->assertJsonPath('last_decision.review_note', 'Foto buram');
    }

    public function test_only_super_admin_of_same_org_can_review(): void
    {
        $change = ProfileChange::create(['member_id' => $this->budi->id, 'phone' => '08222222']);
        $this->actingAs($this->user, 'web')->get('/perubahan-data')->assertRedirect();
        $otherOrg = Organization::create(['name' => 'Lain', 'slug' => 'lain']);
        $otherAdmin = User::factory()->create(['organization_id' => $otherOrg->id, 'role' => 'super_admin']);
        auth()->forgetGuards();
        $this->actingAs($otherAdmin, 'web')->post("/perubahan-data/{$change->id}", ['action' => 'approve'])->assertNotFound();
        $this->assertSame('pending', $change->fresh()->status);
    }
}
