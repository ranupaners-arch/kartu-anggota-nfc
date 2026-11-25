<?php

namespace Tests\Feature;

use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\Due;
use App\Models\DueItem;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Services\CashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class CashTest extends TestCase
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
        Storage::fake('local');
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->treasurer = $this->user('treasurer');
        $this->auditor = $this->user('auditor');
        $this->web($this->admin);
    }

    private function user(?string $finance): User
    {
        return User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'finance_role' => $finance, 'finance_term_id' => $finance ? $this->term->id : null,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()]);
    }

    private function web(User $u): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u->fresh(), 'web');
    }

    private function cat(string $kind, string $name): int
    {
        return CashCategory::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('kind', $kind)->where('name', $name)->value('id');
    }

    private function post_(User $u, string $kind, int|string $amount, array $extra = [])
    {
        return $this->web($u)->post('/kas', $extra + ['kind' => $kind, 'category_id' => $this->cat($kind, 'Lainnya'), 'amount' => $amount, 'occurred_on' => now()->toDateString(), 'description' => 'Uji transaksi']);
    }

    private function balance(): int
    {
        $this->web($this->admin);

        return app(CashService::class)->balance();
    }

    private function bill(): array
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => 'Ani', 'name' => 'Ani']);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'status' => 'active']);
        $this->web($this->treasurer)->post('/iuran', ['title' => 'Iuran Nov', 'amount' => 20000]);
        $due = Due::withoutGlobalScopes()->sole();

        return [$due, DueItem::withoutGlobalScopes()->sole()->id];
    }

    private function mark(Due $due, int $id, string $status, ?string $note = null)
    {
        return $this->web($this->treasurer)->post("/iuran/{$due->id}/tandai", ['ids' => [$id], 'status' => $status, 'note' => $note]);
    }

    public function test_balance_and_spending_limit(): void
    {
        $this->post_($this->treasurer, 'out', 1)->assertSessionHasErrors('amount');
        $this->post_($this->treasurer, 'in', 100000)->assertSessionHasNoErrors();
        $this->post_($this->treasurer, 'out', 60000)->assertSessionHasNoErrors();
        $this->post_($this->treasurer, 'out', 40001)->assertSessionHasErrors('amount');
        $this->post_($this->treasurer, 'out', 40000)->assertSessionHasNoErrors();
        $this->assertSame(0, $this->balance());
    }

    public function test_input_rules(): void
    {
        foreach ([0, -1, 10000000001, '1.5', 'x'] as $bad) {
            $this->post_($this->treasurer, 'in', $bad)->assertSessionHasErrors('amount');
        }
        $this->post_($this->treasurer, 'in', 1, ['occurred_on' => now()->addDay()->toDateString()])->assertSessionHasErrors('occurred_on');
        $this->post_($this->treasurer, 'in', 1, ['category_id' => $this->cat('out', 'Konsumsi')])->assertSessionHasErrors('category_id');
        $this->post_($this->treasurer, 'in', 1, ['category_id' => $this->cat('in', 'Iuran')])->assertSessionHasErrors('category_id');
        $this->post_($this->treasurer, 'in', 1, ['description' => 'x'])->assertSessionHasErrors('description');
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_entries_are_immutable_and_void_needs_reason(): void
    {
        $this->post_($this->treasurer, 'in', 50000);
        $e = CashEntry::sole();
        foreach ([fn () => $e->update(['amount' => 1]), fn () => $e->fresh()->update(['description' => 'ubah']), fn () => $e->fresh()->delete()] as $tamper) {
            try {
                $tamper();
                $this->fail('Transaksi harus terkunci.');
            } catch (LogicException) {
                $this->assertSame(50000, $e->fresh()->amount);
            }
        }
        $this->web($this->treasurer)->post("/kas/{$e->id}/batal", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->web($this->treasurer)->post("/kas/{$e->id}/batal", ['reason' => 'Salah nominal'])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->balance());
        $this->web($this->treasurer)->post("/kas/{$e->id}/batal", ['reason' => 'lagi'])->assertStatus(422);
        $this->assertSame($this->treasurer->id, $e->fresh()->voided_by);
        $this->expectException(LogicException::class);
        $e->fresh()->update(['voided_at' => null]);
    }

    public function test_spent_income_cannot_be_voided_until_the_expense_is(): void
    {
        $this->post_($this->treasurer, 'in', 50000);
        $this->post_($this->treasurer, 'out', 30000);
        [$in, $out] = CashEntry::orderBy('id')->get()->all();
        $this->web($this->treasurer)->post("/kas/{$in->id}/batal", ['reason' => 'Salah'])->assertSessionHasErrors('reason');
        $this->web($this->treasurer)->post("/kas/{$out->id}/batal", ['reason' => 'Salah'])->assertSessionHasNoErrors();
        $this->web($this->treasurer)->post("/kas/{$in->id}/batal", ['reason' => 'Salah'])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->balance());
    }

    public function test_paid_dues_enter_and_leave_the_cash_book_exactly_once(): void
    {
        [$due, $id] = $this->bill();
        $this->mark($due, $id, 'paid')->assertSessionHasNoErrors();
        $this->mark($due, $id, 'paid');
        $this->assertSame(20000, $this->balance());
        $e = CashEntry::sole();
        $this->assertSame([$id, 'in', 'Iuran Nov'], [$e->due_item_id, $e->kind, $e->description]);
        $this->web($this->treasurer)->post("/kas/{$e->id}/batal", ['reason' => 'coba'])->assertSessionHasErrors('reason');
        $this->mark($due, $id, 'unpaid', 'Salah catat')->assertSessionHasNoErrors();
        $this->assertSame(0, $this->balance());
        $this->assertSame('Salah catat', $e->fresh()->void_reason);
        $this->mark($due, $id, 'paid');
        $this->assertSame([20000, 2], [$this->balance(), CashEntry::count()]);
    }

    public function test_spent_dues_cannot_be_undone_and_status_stays(): void
    {
        [$due, $id] = $this->bill();
        $this->mark($due, $id, 'paid');
        $this->post_($this->treasurer, 'out', 15000);
        $this->mark($due, $id, 'unpaid', 'Salah catat')->assertSessionHasErrors('note');
        $this->assertSame('paid', DueItem::withoutGlobalScopes()->find($id)->status);
        $this->assertSame(5000, $this->balance());
    }

    public function test_waiving_never_touches_cash(): void
    {
        [$due, $id] = $this->bill();
        $this->mark($due, $id, 'waived', 'Beasiswa')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_who_may_view_and_record(): void
    {
        $this->post_($this->admin, 'in', 1000)->assertSessionHasNoErrors();
        $e = CashEntry::sole();
        $this->web($this->auditor)->get('/kas')->assertOk()->assertInertia(fn ($p) => $p->where('canManage', false)->where('balance', 1000));
        $this->web($this->auditor)->get('/kas/ekspor')->assertOk();
        $this->post_($this->auditor, 'in', 1)->assertForbidden();
        $this->web($this->auditor)->post("/kas/{$e->id}/batal", ['reason' => 'coba'])->assertForbidden();
        $this->web($this->auditor)->post('/kas/kategori', ['kind' => 'in', 'name' => 'X'])->assertForbidden();
        $plain = $this->user(null);
        $this->web($plain)->get('/kas')->assertForbidden();
        $this->web($plain)->get("/kas/{$e->id}/bukti")->assertForbidden();
        $this->treasurer->forceFill(['two_factor_confirmed_at' => null])->save();
        $this->web($this->treasurer)->get('/kas')->assertRedirect('/iuran/keamanan');
        $foreign = User::factory()->create(['organization_id' => Organization::create(['name' => 'L', 'slug' => 'l'])->id, 'role' => 'super_admin']);
        $this->web($foreign)->get('/kas')->assertInertia(fn ($p) => $p->where('balance', 0)->has('entries.data', 0));
        $this->web($foreign)->post("/kas/{$e->id}/batal", ['reason' => 'coba'])->assertNotFound();
    }

    public function test_receipts_are_private_and_not_left_behind(): void
    {
        $this->post_($this->treasurer, 'out', 5000, ['receipt' => UploadedFile::fake()->image('nota.jpg')])->assertSessionHasErrors('amount');
        $this->assertSame([], Storage::disk('local')->allFiles('cash'));
        $this->post_($this->treasurer, 'in', 5000, ['receipt' => UploadedFile::fake()->create('x.exe', 10)])->assertSessionHasErrors('receipt');
        $this->post_($this->treasurer, 'in', 5000, ['receipt' => UploadedFile::fake()->image('nota.jpg')])->assertSessionHasNoErrors();
        $e = CashEntry::sole();
        $this->web($this->auditor)->get("/kas/{$e->id}/bukti")->assertOk();
        $this->web($this->user(null))->get("/kas/{$e->id}/bukti")->assertForbidden();
    }

    public function test_report_ranges_and_categories(): void
    {
        $this->post_($this->admin, 'in', 100000, ['occurred_on' => now()->subDays(10)->toDateString(), 'category_id' => $this->cat('in', 'Saldo awal')]);
        $this->post_($this->admin, 'out', 30000, ['category_id' => $this->cat('out', 'Konsumsi')]);
        $this->post_($this->admin, 'out', 20000, ['category_id' => $this->cat('out', 'Konsumsi')]);
        $this->web($this->admin)->post('/kas/'.CashEntry::latest('id')->first()->id.'/batal', ['reason' => 'Salah']);
        $this->web($this->admin)->get('/kas?tab=laporan&from='.now()->subDay()->toDateString())->assertInertia(fn ($p) => $p
            ->where('report.opening', 100000)->where('report.in', 0)->where('report.out', 30000)->where('report.closing', 70000)->where('report.categories.0.name', 'Konsumsi')->where('report.categories.0.count', 1));
        $this->web($this->admin)->get('/kas?from=bukan-tanggal')->assertSessionHasErrors('from');
    }

    public function test_categories(): void
    {
        $this->web($this->treasurer)->post('/kas/kategori', ['kind' => 'out', 'name' => ' Hadiah '])->assertSessionHasNoErrors();
        $this->web($this->treasurer)->post('/kas/kategori', ['kind' => 'out', 'name' => 'HADIAH'])->assertSessionHasErrors('name');
        $this->web($this->treasurer)->post('/kas/kategori', ['kind' => 'in', 'name' => 'Hadiah'])->assertSessionHasNoErrors();
        $this->web($this->treasurer)->put('/kas/kategori/'.$this->cat('out', 'Hadiah'), ['name' => 'Doorprize'])->assertSessionHasNoErrors();
        $this->web($this->treasurer)->delete('/kas/kategori/'.$this->cat('in', 'Iuran'))->assertStatus(422);
        $this->post_($this->treasurer, 'in', 10);
        $this->web($this->treasurer)->delete('/kas/kategori/'.$this->cat('in', 'Lainnya'))->assertStatus(422);
        $this->web($this->treasurer)->delete('/kas/kategori/'.$this->cat('out', 'Doorprize'))->assertSessionHasNoErrors();
    }

    public function test_export_neutralizes_formulas_and_includes_voided(): void
    {
        $this->post_($this->treasurer, 'in', 10, ['description' => '=1+1 rumus']);
        $this->web($this->treasurer)->post('/kas/'.CashEntry::sole()->id.'/batal', ['reason' => 'Salah']);
        $csv = $this->web($this->auditor)->get('/kas/ekspor')->streamedContent();
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString('Dibatalkan', $csv);
    }

    public function test_migration_backfills_paid_dues_once(): void
    {
        [$due, $id] = $this->bill();
        $this->mark($due, $id, 'paid');
        $m = require database_path('migrations/2026_11_25_000300_create_cash_entries.php');
        $m->down();
        $m->up();
        $this->assertSame(20000, $this->balance());
        $this->assertSame([$id, $this->treasurer->id], [CashEntry::sole()->due_item_id, CashEntry::sole()->created_by]);
        $this->mark($due, $id, 'unpaid', 'Salah catat')->assertSessionHasNoErrors();
        $this->assertSame(0, $this->balance());
    }

    public function test_term_with_cash_entries_cannot_be_deleted(): void
    {
        $this->post_($this->admin, 'in', 10);
        $this->term->update(['is_current' => false]);
        Term::create(['organization_id' => $this->org->id, 'name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'is_current' => true]);
        $this->web($this->admin)->delete("/pengaturan/periode/{$this->term->id}")->assertStatus(422);
    }
}
