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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Data tampilan kartu anggota untuk app (GET /api/me/card). */
class MemberCardApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private Division $humas;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026/2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-07-31', 'is_current' => true]);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
    }

    private function user(?Member $m = null): User
    {
        return User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $m?->id]);
    }

    private function member(string $status): Member
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => '2401001', 'name' => 'Budi Santoso', 'cohort' => '2024']);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'division_id' => $this->humas->id, 'status' => $status]);

        return $m;
    }

    public function test_states_none_pending_active_inactive(): void
    {
        Sanctum::actingAs($this->user());
        $this->getJson('/api/me/card')->assertOk()->assertJson(['state' => 'none', 'member' => null, 'organization' => 'HMPS', 'valid_until' => '2027-07-31']);

        $m = $this->member('applicant');
        Sanctum::actingAs($this->user($m));
        $this->getJson('/api/me/card')->assertJson(['state' => 'pending', 'member' => ['name' => 'Budi Santoso', 'division' => 'Humas']]);

        Membership::where('member_id', $m->id)->update(['status' => 'active']);
        Card::create(['organization_id' => $this->org->id, 'public_id' => '01JCARDPUBLICID0000000ABCD', 'key_id' => 'k1', 'status' => 'active', 'member_id' => $m->id, 'issued_at' => now()]);
        $this->getJson('/api/me/card')->assertJson(['state' => 'active', 'member' => ['member_no' => '2401001', 'cohort' => '2024', 'photo_url' => null], 'card' => ['last4' => 'ABCD']]);

        Membership::where('member_id', $m->id)->update(['status' => 'alumni']);
        $this->getJson('/api/me/card')->assertJsonPath('state', 'inactive');
    }

    public function test_own_photo_only(): void
    {
        $m = $this->member('active');
        $m->update(['photo_path' => UploadedFile::fake()->image('a.jpg')->store('members', 'local')]);
        Sanctum::actingAs($this->user($m));
        $url = $this->getJson('/api/me/card')->json('member.photo_url');
        $this->assertStringContainsString('/api/me/photo', $url);
        $this->get('/api/me/photo')->assertOk();
        Sanctum::actingAs($this->user());
        $this->get('/api/me/photo')->assertNotFound();
    }
}
