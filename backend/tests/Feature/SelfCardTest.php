<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\CardRequest;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Anggota mendaftarkan kartu NFC sendiri (1×); pengganti lewat persetujuan Super Admin. */
class SelfCardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Term $term;
    private Member $budi;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['attendance.card_keys.k1' => 'test-secret', 'attendance.payload_prefix' => 'card']);
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->budi = Member::create(['organization_id' => $this->org->id, 'member_no' => 'B01', 'name' => 'Budi']);
        Membership::create(['member_id' => $this->budi->id, 'term_id' => $this->term->id, 'status' => 'active']);
        $this->user = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $this->budi->id]);
    }

    private function write(): array
    {
        $init = $this->postJson('/api/me/card/init')->assertCreated()->json();
        $this->assertArrayHasKey('pwd', $init['ntag']);

        return $this->postJson("/api/me/card/{$init['id']}/assign", ['hw_uid' => '04AABBCCDD'])->assertOk()->json();
    }

    public function test_member_registers_own_card_once(): void
    {
        Sanctum::actingAs($this->user);
        $this->getJson('/api/me/card')->assertJsonPath('self_card.can_write', true)->assertJsonPath('self_card.can_request', false);

        $card = $this->write();
        $this->assertSame([true, false, true], [$card['card']['active'], $card['self_card']['can_write'], $card['self_card']['can_request']]);
        $this->assertSame(1, Card::where('member_id', $this->budi->id)->where('status', 'active')->count());

        $this->postJson('/api/me/card/init')->assertForbidden(); // jatah habis
    }

    public function test_cannot_assign_someone_elses_reserved_card_or_before_approval(): void
    {
        Sanctum::actingAs($this->user);
        $init = $this->postJson('/api/me/card/init')->json();

        $other = Member::create(['organization_id' => $this->org->id, 'member_no' => 'C01', 'name' => 'Citra']);
        Membership::create(['member_id' => $other->id, 'term_id' => $this->term->id, 'status' => 'active']);
        Sanctum::actingAs(User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $other->id]));
        $this->postJson("/api/me/card/{$init['id']}/assign")->assertForbidden();

        Membership::where('member_id', $other->id)->update(['status' => 'applicant']);
        $this->postJson('/api/me/card/init')->assertForbidden(); // belum disahkan
    }

    public function test_replacement_request_lost_blocks_card_and_approval_grants_one_more_write(): void
    {
        Sanctum::actingAs($this->user);
        $this->write();
        $this->postJson('/api/me/card/replacement', ['reason' => 'hilang', 'note' => 'Jatuh di bus'])->assertCreated()
            ->assertJsonPath('self_card.pending_request.reason', 'hilang')->assertJsonPath('card', null);
        $this->assertSame('lost', Card::where('member_id', $this->budi->id)->value('status'));
        $this->postJson('/api/me/card/replacement', ['reason' => 'rusak'])->assertUnprocessable(); // masih ada yang menunggu

        $admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        auth()->forgetGuards();
        $this->actingAs($admin, 'web')->get('/kartu?tab=pengganti')->assertInertia(fn (Assert $p) => $p
            ->has('items.data', 1)->where('items.data.0.member.name', 'Budi')->where('navBadges.cardRequests', 1));
        $req = CardRequest::sole();
        $this->actingAs($admin, 'web')->post("/kartu/permintaan/{$req->id}", ['action' => 'approve'])->assertSessionHas('status');
        $this->assertSame([1, 'approved'], [$this->budi->fresh()->self_card_writes_left, $req->fresh()->status]);

        auth()->forgetGuards();
        Sanctum::actingAs($this->user);
        $this->write();
        $this->assertSame(1, Card::where('member_id', $this->budi->id)->where('status', 'active')->count());
    }

    public function test_only_super_admin_reviews_requests(): void
    {
        $req = CardRequest::create(['member_id' => $this->budi->id, 'user_id' => $this->user->id, 'reason' => 'rusak']);
        $da = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin']);
        $this->actingAs($da, 'web')->post("/kartu/permintaan/{$req->id}", ['action' => 'approve'])->assertForbidden();
        $this->assertSame('pending', $req->fresh()->status);
    }
}
