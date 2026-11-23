<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Card;
use App\Models\CardRequest;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Support\CardPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Menu halaman Kartu Saya di app: detail kartu, riwayat kartu, cek kartu NFC. */
class MemberCardMenuTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private Member $budi;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->budi = $this->member('01', 'Budi');
        $this->user = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $this->budi->id]);
    }

    private function member(string $no, string $name): Member
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $no, 'name' => $name]);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'status' => 'active']);

        return $m;
    }

    private function card(?Member $m, string $status = 'active', ?string $issuedAt = '2026-11-01 10:00:00'): Card
    {
        return Card::create(['organization_id' => $this->org->id, 'public_id' => (string) Str::ulid(), 'key_id' => 'k1', 'status' => $status,
            'member_id' => $m?->id, 'issued_at' => $issuedAt, 'revoked_at' => $status === 'active' ? null : '2026-11-10 08:00:00']);
    }

    private function log(Card $c, string $action): void
    {
        AuditLog::create(['user_id' => $this->user->id, 'action' => $action, 'entity' => 'card', 'entity_id' => $c->id]);
    }

    public function test_card_detail_includes_issue_date_and_who_issued(): void
    {
        $c = $this->card($this->budi);
        $this->log($c, 'card.self_issued');
        Sanctum::actingAs($this->user);

        $card = $this->getJson('/api/me/card')->assertOk()->json('card');
        $this->assertSame('self', $card['issued_by']);
        $this->assertStringStartsWith('2026-11-01', $card['issued_at']);
        $this->assertSame(strtoupper(substr($c->public_id, -4)), $card['last4']);
    }

    public function test_history_lists_cards_and_reports_newest_first(): void
    {
        $old = $this->card($this->budi, 'lost', '2026-09-01 10:00:00');
        $this->log($old, 'card.issued');
        $new = $this->card($this->budi, 'active', '2026-11-12 10:00:00');
        $this->log($new, 'card.self_issued');
        $this->card($this->member('02', 'Sari')); // kartu orang lain tidak ikut
        CardRequest::create(['member_id' => $this->budi->id, 'user_id' => $this->user->id, 'reason' => 'hilang', 'status' => 'approved',
            'resolution' => 'self', 'review_note' => 'Silakan daftar ulang', 'reviewed_at' => now()]);

        Sanctum::actingAs($this->user);
        $h = $this->getJson('/api/me/card/history')->assertOk()->json();

        $this->assertSame([['active', 'self'], ['lost', 'admin']], array_map(fn ($c) => [$c['status'], $c['issued_by']], $h['cards']));
        $this->assertNotNull($h['cards'][1]['revoked_at']);
        $this->assertSame([['hilang', 'approved', 'self', 'Silakan daftar ulang']], array_map(fn ($r) => [$r['reason'], $r['status'], $r['resolution'], $r['review_note']], $h['requests']));
    }

    public function test_check_tells_whether_tapped_card_is_mine_without_leaking_owner(): void
    {
        $mine = $this->card($this->budi);
        $lost = $this->card($this->budi, 'lost');
        $sari = $this->card($this->member('02', 'Sari'));
        $blank = $this->card(null, 'unassigned', null);
        Sanctum::actingAs($this->user);

        $check = fn (string $payload) => $this->postJson('/api/me/card/check', ['payload' => $payload])->assertOk()->json();

        $this->assertSame(['result' => 'mine', 'status' => 'active', 'last4' => strtoupper(substr($mine->public_id, -4))], $check(CardPayload::make($mine)));
        $this->assertSame('mine_inactive', $check(CardPayload::make($lost))['result']);
        $this->assertSame(['result' => 'other'], $check(CardPayload::make($sari))); // tanpa nama/nomor pemilik
        $this->assertSame(['result' => 'unknown'], $check(CardPayload::make($blank)));
        $this->assertSame(['result' => 'invalid'], $check(str_replace(substr(CardPayload::make($mine), -4), 'XXXX', CardPayload::make($mine))));
        $this->assertSame(['result' => 'invalid'], $check('bukan-kartu'));
        $this->postJson('/api/me/card/check', [])->assertStatus(422);
    }
}
