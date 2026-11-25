<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\InventoryItem;
use App\Models\InventoryLoan;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $divAdmin;

    private User $borrower;

    private InventoryItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->divAdmin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => Division::create(['organization_id' => $this->org->id, 'name' => 'Humas'])->id]);
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => 'A1', 'name' => 'Ani']);
        Membership::create(['member_id' => $m->id, 'term_id' => $term->id, 'status' => 'active']);
        $this->borrower = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $m->id]);
        $this->item = InventoryItem::create(['organization_id' => $this->org->id, 'name' => 'Proyektor', 'code' => 'PRJ', 'quantity' => 3]);
    }

    private function as_(User $u, string $guard = 'web'): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u->fresh(), $guard);
    }

    private function lend(int $qty = 1, array $extra = [], ?User $by = null)
    {
        return $this->as_($by ?? $this->admin)->post('/inventaris/pinjam', $extra + ['item_id' => $this->item->id, 'member_no' => 'A1', 'quantity' => $qty, 'due_on' => today()->addDays(3)->toDateString()]);
    }

    private function back_(InventoryLoan $l, string $condition = 'good', ?string $note = null, ?User $by = null)
    {
        return $this->as_($by ?? $this->admin)->post("/inventaris/pinjam/{$l->id}/kembali", ['condition' => $condition, 'note' => $note]);
    }

    private function loan(): InventoryLoan
    {
        return InventoryLoan::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function notices(): array
    {
        return collect($this->as_($this->borrower, 'sanctum')->getJson('/api/me/notifications')->json('data'))->where('type', 'loan')->values()->all();
    }

    public function test_stock_cannot_be_exceeded(): void
    {
        $this->lend(2)->assertSessionHasNoErrors();
        $this->lend(2)->assertSessionHasErrors('quantity');
        $this->lend(1)->assertSessionHasNoErrors();
        $this->lend(1)->assertSessionHasErrors('quantity');
        $this->back_($this->loan())->assertSessionHasNoErrors();
        $this->lend(1)->assertSessionHasNoErrors();
        $this->as_($this->admin)->get('/inventaris')->assertInertia(fn ($p) => $p->where('items.data.0.on_loan', 3));
    }

    public function test_return_once_and_conditions(): void
    {
        $this->lend(2);
        $l = $this->loan();
        $this->back_($l, 'lost')->assertSessionHasErrors('note');
        $this->back_($l, 'lost', 'Hilang di lokasi')->assertSessionHasNoErrors();
        $this->back_($l, 'lost', 'Hilang di lokasi')->assertStatus(422);
        $this->assertSame(1, $this->item->fresh()->quantity);
        $this->lend(1);
        $this->back_($this->loan(), 'damaged', 'Lensa retak')->assertSessionHasNoErrors();
        $this->assertSame([1, 'damaged'], [$this->item->fresh()->quantity, $this->loan()->condition]);
    }

    public function test_lend_rules(): void
    {
        $this->lend(1, ['member_no' => 'XX'])->assertSessionHasErrors('member_no');
        $this->lend(0)->assertSessionHasErrors('quantity');
        $this->lend(1, ['due_on' => today()->subDay()->toDateString()])->assertSessionHasErrors('due_on');
        $this->as_($this->admin)->patch("/inventaris/{$this->item->id}/arsip", ['archived' => true])->assertSessionHasNoErrors();
        $this->lend()->assertSessionHasErrors('item_id');
        $this->assertSame(0, InventoryLoan::withoutGlobalScopes()->count());
    }

    public function test_item_rules(): void
    {
        $put = fn (array $x) => $this->as_($this->admin)->put("/inventaris/{$this->item->id}", $x + ['name' => 'Proyektor', 'quantity' => 3, 'code' => 'PRJ']);
        $this->as_($this->admin)->post('/inventaris', ['name' => 'Lain', 'quantity' => 1, 'code' => ' prj '])->assertSessionHasErrors('code');
        $this->as_($this->admin)->post('/inventaris', ['name' => 'Kabel', 'quantity' => 5, 'code' => ''])->assertSessionHasNoErrors();
        $this->as_($this->admin)->post('/inventaris', ['name' => 'Kursi', 'quantity' => 5, 'code' => ''])->assertSessionHasNoErrors();
        $this->lend(2);
        $put(['quantity' => 1])->assertSessionHasErrors('quantity');
        $put(['quantity' => 2])->assertSessionHasNoErrors();
        $this->as_($this->admin)->patch("/inventaris/{$this->item->id}/arsip", ['archived' => true])->assertStatus(422);
        $this->back_($this->loan());
        $this->as_($this->admin)->delete("/inventaris/{$this->item->id}")->assertStatus(422);
        $this->as_($this->admin)->delete('/inventaris/'.InventoryItem::where('name', 'Kabel')->value('id'))->assertSessionHasNoErrors();
    }

    public function test_access(): void
    {
        $this->as_($this->divAdmin)->post('/inventaris', ['name' => 'X', 'quantity' => 1])->assertForbidden();
        $this->as_($this->divAdmin)->put("/inventaris/{$this->item->id}", ['name' => 'X', 'quantity' => 1])->assertForbidden();
        $this->as_($this->divAdmin)->delete("/inventaris/{$this->item->id}")->assertForbidden();
        $this->lend(1, [], $this->divAdmin)->assertSessionHasNoErrors();
        $this->back_($this->loan(), 'good', null, $this->divAdmin)->assertSessionHasNoErrors();
        $this->as_($this->divAdmin)->get('/inventaris?tab=pinjaman')->assertInertia(fn ($p) => $p->where('canManage', false)->has('loans.data', 1));
        $this->lend(1, [], $this->borrower)->assertRedirect();
        $foreign = User::factory()->create(['organization_id' => Organization::create(['name' => 'L', 'slug' => 'l'])->id, 'role' => 'super_admin']);
        $this->as_($foreign)->get('/inventaris')->assertInertia(fn ($p) => $p->has('items.data', 0));
        $this->lend(1, [], $foreign)->assertNotFound();
        $this->back_($this->loan(), 'good', null, $foreign)->assertNotFound();
        $this->assertSame(1, InventoryLoan::withoutGlobalScopes()->count());
    }

    public function test_member_sees_own_loans_and_is_reminded(): void
    {
        $this->lend(2);
        $data = fn () => $this->as_($this->borrower, 'sanctum')->getJson('/api/me/loans')->assertOk()->json('data');
        $this->assertSame([['Proyektor', 2, false], []], [[$data()[0]['item'], $data()[0]['quantity'], $data()[0]['overdue']], $this->notices()]);
        $this->travel(2)->days();
        $this->assertStringStartsWith('Segera', $this->notices()[0]['title']);
        $first = $this->notices()[0]['id'];
        $this->travel(2)->days();
        $this->assertStringStartsWith('Terlambat', $this->notices()[0]['title']);
        $this->assertNotSame($first, $this->notices()[0]['id']);
        $this->as_($this->admin)->get('/inventaris?tab=pinjaman&status=terlambat')->assertInertia(fn ($p) => $p->where('overdue', 1)->has('loans.data', 1));
        $this->as_($this->borrower)->get('/saya/riwayat')->assertInertia(fn ($p) => $p->has('loans', 1));
        $this->back_($this->loan());
        $this->assertSame([[], []], [$data(), $this->notices()]);
    }

    public function test_export(): void
    {
        $this->lend(1, ['note' => '=1+1']);
        $csv = $this->as_($this->admin)->get('/inventaris/ekspor')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString('Proyektor', $csv);
    }
}
