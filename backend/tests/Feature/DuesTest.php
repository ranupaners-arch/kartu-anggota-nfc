<?php

namespace Tests\Feature;

use App\Models\Due;
use App\Models\DueItem;
use App\Models\DueItemChange;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Services\DuesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class DuesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private User $admin;

    private User $treasurer;

    private User $auditor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = $this->newTerm('2026', true);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->treasurer = $this->memberUser('Bendi', 'treasurer');
        $this->auditor = $this->memberUser('Seki', 'auditor');
        $this->memberUser('Ani');
    }

    private function newTerm(string $name, bool $current): Term
    {
        return Term::create(['organization_id' => $this->org->id, 'name' => $name, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => $current]);
    }

    private function memberUser(string $name, ?string $finance = null): User
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $name, 'name' => $name]);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'status' => 'active']);

        return User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $m->id,
            'finance_role' => $finance, 'finance_term_id' => $finance ? $this->term->id : null]);
    }

    private function web(User $u): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u->fresh(), 'web');
    }

    private function bill(string $title = 'Iuran November', int $amount = 20000): Due
    {
        $this->web($this->treasurer)->post('/iuran', ['title' => $title, 'amount' => $amount])->assertSessionHasNoErrors()->assertRedirect();

        return Due::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function item(Due $due, string $no): DueItem
    {
        return DueItem::withoutGlobalScopes()->where('due_id', $due->id)->where('member_id', Member::withoutGlobalScopes()->where('member_no', $no)->value('id'))->firstOrFail();
    }

    private function mark(User $u, Due $due, array $nos, string $status, ?string $note = null)
    {
        return $this->web($u)->post("/iuran/{$due->id}/tandai", ['ids' => array_map(fn ($n) => $this->item($due, $n)->id, $nos), 'status' => $status, 'note' => $note]);
    }

    public function test_bill_covers_active_members_with_same_amount(): void
    {
        $out = Member::create(['organization_id' => $this->org->id, 'member_no' => 'Alum', 'name' => 'Alum']);
        Membership::create(['member_id' => $out->id, 'term_id' => $this->term->id, 'status' => 'alumni']);
        $due = $this->bill();
        $this->assertSame(3, DueItem::withoutGlobalScopes()->count());
        $this->memberUser('Baru');
        $this->web($this->treasurer)->post("/iuran/{$due->id}/sinkron")->assertSessionHas('status', '1 anggota ditambahkan.');
        $this->web($this->treasurer)->post("/iuran/{$due->id}/sinkron")->assertSessionHas('status', '0 anggota ditambahkan.');
        $this->web($this->treasurer)->post('/iuran', ['title' => 'IURAN november', 'amount' => 1])->assertSessionHasErrors('title');
        foreach ([0, -5, 10000001, 'x'] as $bad) {
            $this->web($this->treasurer)->post('/iuran', ['title' => 'Lain', 'amount' => $bad])->assertSessionHasErrors('amount');
        }
    }

    public function test_who_may_view_and_change(): void
    {
        $due = $this->bill();
        $plain = User::where('role', 'member')->whereNull('finance_role')->firstOrFail();
        $division = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin']);
        $scanner = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'scanner']);
        foreach ([$plain, $division, $scanner] as $u) {
            $this->web($u)->get('/iuran')->assertForbidden();
            $this->mark($u, $due, ['Ani'], 'paid')->assertForbidden();
        }
        foreach ([$this->admin, $this->treasurer] as $u) {
            $this->web($u)->get("/iuran/{$due->id}")->assertOk()->assertInertia(fn ($p) => $p->where('canManage', true));
        }
        // Sekretaris: lihat, riwayat, unduh — tanpa mengubah apa pun.
        $this->web($this->auditor)->get("/iuran/{$due->id}?tab=riwayat")->assertOk()->assertInertia(fn ($p) => $p->where('canManage', false));
        $this->web($this->auditor)->get("/iuran/{$due->id}/ekspor")->assertOk();
        $this->mark($this->auditor, $due, ['Ani'], 'paid')->assertForbidden();
        $this->web($this->auditor)->post('/iuran', ['title' => 'X', 'amount' => 1])->assertForbidden();
        $this->web($this->auditor)->post("/iuran/{$due->id}/sinkron")->assertForbidden();
        $this->web($this->auditor)->post("/iuran/{$due->id}/batal", ['reason' => 'coba'])->assertForbidden();
        // Akses keuangan tidak membuka dashboard lain.
        $this->web($this->treasurer)->get('/anggota')->assertRedirect('/saya');
        $this->web($this->treasurer)->get('/denda')->assertRedirect('/saya');
    }

    public function test_access_ends_with_term_disable_and_temporary_password(): void
    {
        $this->bill();
        $this->treasurer->update(['must_change_password' => true]);
        $this->web($this->treasurer)->get('/iuran')->assertRedirect('/saya/akun');
        $this->treasurer->update(['must_change_password' => false, 'disabled_at' => now()]);
        $this->web($this->treasurer)->get('/iuran')->assertForbidden();
        $this->treasurer->update(['disabled_at' => null]);
        $this->web($this->treasurer)->get('/iuran')->assertOk();
        $this->term->update(['is_current' => false]);
        $this->newTerm('2027', true);
        $this->web($this->treasurer)->get('/iuran')->assertForbidden();
        $this->web($this->auditor)->get('/iuran')->assertForbidden();
    }

    public function test_marking_keeps_history_and_requires_reason_to_waive_or_undo(): void
    {
        $due = $this->bill();
        $this->mark($this->treasurer, $due, ['Ani', 'Seki'], 'paid', 'Kuitansi 12')->assertSessionHasNoErrors();
        $this->mark($this->treasurer, $due, ['Ani'], 'paid')->assertSessionHas('status', '0 tagihan ditandai lunas.');
        $this->mark($this->treasurer, $due, ['Ani'], 'unpaid')->assertSessionHasErrors('note');
        $this->mark($this->treasurer, $due, ['Ani'], 'unpaid', 'Salah catat')->assertSessionHasNoErrors();
        $this->mark($this->treasurer, $due, ['Ani'], 'waived', ' ')->assertSessionHasErrors('note');
        $this->mark($this->treasurer, $due, ['Ani'], 'waived', 'Beasiswa')->assertSessionHasNoErrors();

        $this->web($this->auditor)->get("/iuran/{$due->id}?tab=riwayat")->assertInertia(fn ($p) => $p
            ->has('changes.data', 4)->where('changes.data.0.to', 'waived')->where('changes.data.0.note', 'Beasiswa')->where('changes.data.0.by', $this->treasurer->name)
            ->where('summary', ['unpaid' => 1, 'paid' => 1, 'waived' => 1, 'paid_total' => 20000, 'unpaid_total' => 20000]));
        $change = DueItemChange::withoutGlobalScopes()->firstOrFail();
        foreach ([fn () => $change->update(['note' => 'x']), fn () => $change->delete()] as $tamper) {
            try {
                $tamper();
                $this->fail('Riwayat harus terkunci.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_treasurer_cannot_mark_own_bill(): void
    {
        $due = $this->bill();
        $this->mark($this->treasurer, $due, ['Ani', 'Bendi'], 'paid')->assertSessionHasErrors('ids');
        $this->assertSame('unpaid', $this->item($due, 'Ani')->status); // semua atau tidak sama sekali
        $this->mark($this->admin, $due, ['Bendi'], 'paid')->assertSessionHasNoErrors();
        $this->assertSame('paid', $this->item($due, 'Bendi')->status);
    }

    public function test_cancel_only_without_payments_and_title_can_be_reused(): void
    {
        $due = $this->bill();
        $this->mark($this->treasurer, $due, ['Ani'], 'paid');
        $this->web($this->treasurer)->post("/iuran/{$due->id}/batal", ['reason' => 'Nominal salah'])->assertSessionHasErrors('reason');
        $this->mark($this->treasurer, $due, ['Ani'], 'unpaid', 'Dikembalikan');
        $this->web($this->treasurer)->post("/iuran/{$due->id}/batal", ['reason' => 'Nominal salah'])->assertSessionHasNoErrors();
        $this->mark($this->treasurer, $due, ['Ani'], 'paid')->assertStatus(422);
        $again = $this->bill(amount: 25000);
        $this->assertNotSame($due->id, $again->id);
        $this->app['auth']->forgetGuards();
        $ani = User::whereHas('member', fn ($q) => $q->where('member_no', 'Ani'))->firstOrFail();
        $dues = $this->actingAs($ani, 'sanctum')->getJson('/api/me/history')->assertOk()->json('dues');
        $this->assertSame([25000, 1], [$dues['unpaid_total'], count($dues['items'])]);
    }

    public function test_items_of_another_bill_and_other_organizations_are_out_of_reach(): void
    {
        $a = $this->bill();
        $b = $this->bill('Iuran Desember');
        $this->web($this->treasurer)->post("/iuran/{$a->id}/tandai", ['ids' => [$this->item($b, 'Ani')->id], 'status' => 'paid'])->assertSessionHas('status', '0 tagihan ditandai lunas.');
        $org = Organization::create(['name' => 'Lain', 'slug' => 'lain']);
        $foreign = User::factory()->create(['organization_id' => $org->id, 'role' => 'super_admin']);
        $this->web($foreign)->get("/iuran/{$a->id}")->assertNotFound();
        $this->web($foreign)->get("/iuran/{$a->id}/ekspor")->assertNotFound();
        $this->web($foreign)->post("/iuran/{$a->id}/tandai", ['ids' => [1], 'status' => 'paid'])->assertNotFound();
    }

    public function test_member_sees_only_own_dues_and_old_unpaid_ones_stay(): void
    {
        $due = $this->bill();
        $this->term->update(['is_current' => false]);
        $new = $this->newTerm('2027', true);
        $this->app['auth']->forgetGuards();
        $s = app(DuesService::class);
        $ani = Member::where('member_no', 'Ani')->value('id');
        $this->assertSame(20000, $s->forMember($ani, $new->id)['unpaid_total']);
        $this->item($due, 'Ani')->update(['status' => 'paid']);
        $this->assertCount(0, $s->forMember($ani, $new->id)['items']);
    }

    public function test_export_neutralizes_formulas(): void
    {
        $due = $this->bill();
        $this->mark($this->treasurer, $due, ['Ani'], 'paid', '=HYPERLINK("x")');
        $csv = $this->web($this->auditor)->get("/iuran/{$due->id}/ekspor")->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('Lunas', $csv);
        $this->assertSame(4, substr_count(trim($csv), "\n") + 1);
    }

    public function test_super_admin_grants_and_revokes_finance_access(): void
    {
        $ani = User::whereHas('member', fn ($q) => $q->where('member_no', 'Ani'))->firstOrFail();
        $data = ['name' => $ani->name, 'email' => $ani->email, 'role' => 'member', 'member_no' => 'Ani'];
        $token = $ani->createToken('hp');
        $this->web($this->admin)->put("/pengguna/{$ani->id}", $data + ['finance_role' => 'treasurer'])->assertSessionHasNoErrors();
        $this->assertTrue($ani->fresh()->canManageDues());
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]); // sesi app tetap
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.finance_role', 'entity_id' => $ani->id]);
        $this->web($this->admin)->put("/pengguna/{$ani->id}", $data)->assertSessionHasNoErrors(); // tanpa kolom: tetap
        $this->assertTrue($ani->fresh()->canManageDues());
        $this->web($this->admin)->put("/pengguna/{$ani->id}", $data + ['finance_role' => 'bos'])->assertSessionHasErrors('finance_role');
        $this->web($this->admin)->put("/pengguna/{$ani->id}", $data + ['finance_role' => ''])->assertSessionHasNoErrors();
        $this->assertFalse($ani->fresh()->canViewDues());
        $this->web($this->admin)->get('/pengguna')->assertOk()->assertInertia(fn ($p) => $p->has('users.data.0'));
        $this->web($this->treasurer)->put("/pengguna/{$ani->id}", $data + ['finance_role' => 'treasurer'])->assertRedirect('/saya');
        $this->assertFalse($ani->fresh()->canViewDues());
    }

    public function test_term_with_bills_cannot_be_deleted(): void
    {
        $due = $this->bill();
        $this->term->update(['is_current' => false]);
        $this->newTerm('2027', true);
        $this->web($this->admin)->delete("/pengaturan/periode/{$due->term_id}")->assertStatus(422);
    }
}
