<?php

namespace Tests\Feature;

use App\Jobs\SendNotice;
use App\Models\Due;
use App\Models\DueItem;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Services\DuesService;
use App\Services\Notifier;
use App\Services\Push\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class DueNoticeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $ani;

    private User $budi;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        [$this->ani, $this->budi] = array_map(function ($n) use ($term) {
            $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $n, 'name' => $n]);
            Membership::create(['member_id' => $m->id, 'term_id' => $term->id, 'status' => 'active']);

            return User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $m->id]);
        }, ['Ani', 'Budi']);
    }

    private function bill(): Due
    {
        $this->actingAs($this->admin);
        $due = app(DuesService::class)->create($this->admin, ['title' => 'Iuran November', 'amount' => 20000, 'due_on' => '2026-11-30']);
        $this->app['auth']->forgetGuards();

        return $due;
    }

    private function notices(User $u): array
    {
        $this->app['auth']->forgetGuards();

        return collect($this->actingAs($u, 'sanctum')->getJson('/api/me/notifications')->assertOk()->json('data'))->where('type', 'due')->values()->all();
    }

    public function test_new_bill_queues_one_notice_and_pushes_to_unpaid_members_without_email(): void
    {
        $due = $this->bill();
        Queue::assertPushed(SendNotice::class, fn ($j) => $j->event === 'dueCreated' && $j->id === $due->id);
        DueItem::where('member_id', $this->budi->member_id)->update(['status' => 'paid']);
        $this->mock(PushService::class, fn (MockInterface $m) => $m->shouldReceive('toUsers')->once()
            ->withArgs(fn ($ids, $title, $body, $url) => $ids->all() === [$this->ani->id] && str_contains($body, 'Rp20.000') && str_contains($body, '30 November 2026') && $url === '/saya/riwayat')->andReturn(1));
        app(Notifier::class)->dueCreated($due->id);
        Mail::assertNothingSent();
    }

    public function test_cancelled_bill_is_not_announced(): void
    {
        $due = $this->bill();
        $due->update(['cancelled_at' => now()]);
        $this->mock(PushService::class, fn (MockInterface $m) => $m->shouldNotReceive('toUsers'));
        app(Notifier::class)->dueCreated($due->id);
        $this->assertSame([], $this->notices($this->ani));
    }

    public function test_bell_shows_own_unpaid_bill_until_settled(): void
    {
        $this->bill();
        $n = $this->notices($this->ani);
        $this->assertCount(1, $n);
        $this->assertSame('Tagihan Iuran November: Rp20.000', $n[0]['title']);
        $this->assertStringContainsString('jatuh tempo 30 November 2026', $n[0]['body']);
        DueItem::withoutGlobalScopes()->where('member_id', $this->ani->member_id)->update(['status' => 'waived']);
        $this->assertSame([], $this->notices($this->ani));
        $this->assertCount(1, $this->notices($this->budi));
    }
}
