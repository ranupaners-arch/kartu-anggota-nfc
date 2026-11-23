<?php

namespace Tests\Feature;

use App\Jobs\SendNotice;
use App\Models\Card;
use App\Models\Division;
use App\Models\Duty;
use App\Models\Event;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Services\DutyService;
use App\Support\CardPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Petugas = anggota yang diberi tugas per periode; dicabut/berakhir → kembali jadi anggota. */
class DutyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private User $admin;

    private Member $sari;

    private User $sariUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
        config(['attendance.card_keys.k1' => 'test-secret', 'attendance.payload_prefix' => 'card']);
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addYear()->toDateString(), 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->sari = Member::create(['organization_id' => $this->org->id, 'member_no' => 'S01', 'name' => 'Sari']);
        Membership::create(['member_id' => $this->sari->id, 'term_id' => $this->term->id, 'status' => 'active']);
        $this->sariUser = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $this->sari->id]);
        $this->sariUser->createToken('hp');
    }

    public function test_super_admin_grants_scanner_duty_from_member_page(): void
    {
        $this->actingAs($this->admin)->post("/anggota/{$this->sari->id}/tugas", ['type' => 'scanner'])->assertRedirect()->assertSessionHas('status');
        $u = $this->sariUser->fresh();
        $this->assertSame('scanner', $u->role);
        $this->assertSame(0, $u->tokens()->count()); // keluar dari HP → masuk lagi dengan menu tugas
        $this->assertSame($this->term->id, Duty::first()->term_id);
        Queue::assertPushed(SendNotice::class, fn ($j) => $j->event === 'dutyGranted');

        $this->actingAs($this->admin)->get("/anggota/{$this->sari->id}")->assertInertia(fn (Assert $p) => $p
            ->where('duty.current.label', 'Petugas Scan')->where('duty.hasAccount', true));
        $this->actingAs($this->admin)->get('/tugas')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('duties/index')->where('active.0.user.name', $this->sariUser->name)->where('active.0.member.member_no', 'S01'));
    }

    public function test_division_admin_needs_division_and_member_needs_account(): void
    {
        $this->actingAs($this->admin)->post("/anggota/{$this->sari->id}/tugas", ['type' => 'division_admin'])->assertSessionHasErrors('division_id');
        $humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->actingAs($this->admin)->post("/anggota/{$this->sari->id}/tugas", ['type' => 'division_admin', 'division_id' => $humas->id])->assertRedirect();
        $this->assertSame(['division_admin', $humas->id], [$this->sariUser->fresh()->role, $this->sariUser->fresh()->division_id]);

        $noAccount = Member::create(['organization_id' => $this->org->id, 'member_no' => 'X1', 'name' => 'Tanpa Akun']);
        $this->actingAs($this->admin)->post("/anggota/{$noAccount->id}/tugas", ['type' => 'scanner'])->assertStatus(422);
    }

    public function test_revoking_duty_returns_account_to_member_with_reason(): void
    {
        $duty = app(DutyService::class)->grant($this->admin, $this->sariUser, 'scanner');
        $this->sariUser->createToken('hp-lagi');

        $this->actingAs($this->admin)->post("/tugas/{$duty->id}/akhiri", [])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post("/tugas/{$duty->id}/akhiri", ['reason' => 'Mengundurkan diri'])->assertRedirect();
        $u = $this->sariUser->fresh();
        $this->assertSame('member', $u->role);
        $this->assertSame(0, $u->tokens()->count());
        $this->assertSame('Mengundurkan diri', $duty->fresh()->end_reason);
        Queue::assertPushed(SendNotice::class, fn ($j) => $j->event === 'dutyEnded');
    }

    public function test_duties_expire_on_end_date_and_when_term_changes_then_can_be_renewed(): void
    {
        $svc = app(DutyService::class);
        $dated = $svc->grant($this->admin, $this->sariUser, 'scanner', null, today()->toDateString());
        $this->travel(2)->days();
        $this->artisan('tugas:kedaluwarsa')->assertSuccessful();
        $this->assertSame('Masa tugas berakhir', $dated->fresh()->end_reason);
        $this->assertSame('member', $this->sariUser->fresh()->role);

        $this->actingAs($this->admin);
        $svc->grant($this->admin, $this->sariUser->fresh(), 'scanner');
        $next = Term::create(['organization_id' => $this->org->id, 'name' => '2027', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addYear()->toDateString(), 'is_current' => false]);
        $this->post("/pengaturan/periode/{$next->id}/berjalan")->assertRedirect();
        $this->assertSame('member', $this->sariUser->fresh()->role);

        $old = Duty::where('end_reason', 'Periode berganti')->firstOrFail();
        $this->get('/tugas')->assertInertia(fn (Assert $p) => $p->where('previous.0.id', $old->id)->where('active', []));
        $this->post('/tugas/perpanjang', ['ids' => [$old->id]])->assertRedirect();
        $this->assertSame('scanner', $this->sariUser->fresh()->role);
        $this->assertSame($next->id, Duty::open()->firstOrFail()->term_id);
    }

    public function test_officer_cannot_scan_own_card_and_still_gets_member_notifications(): void
    {
        app(DutyService::class)->grant($this->admin, $this->sariUser, 'scanner');
        $card = Card::create(['organization_id' => $this->org->id, 'public_id' => (string) Str::ulid(), 'key_id' => 'k1', 'status' => 'active', 'member_id' => $this->sari->id, 'issued_at' => now()]);
        $e = Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => 'Rapat', 'starts_at' => now(), 'ends_at' => now()->addHour(),
            'checkin_opens_at' => now()->subMinutes(10), 'late_after' => now()->addMinutes(10), 'checkin_closes_at' => now()->addMinutes(30)]);

        Sanctum::actingAs($this->sariUser->fresh());
        $res = $this->postJson("/api/events/{$e->id}/scan", ['payload' => CardPayload::make($card), 'source' => 'nfc'])->assertOk()->json();
        $this->assertSame('self_scan', $res['status']);
        $this->assertSame(0, $e->attendances()->count());

        // Fitur anggota tetap jalan untuk anggota yang bertugas.
        $this->getJson('/api/me/card')->assertOk()->assertJsonPath('state', 'active');
    }

    public function test_shared_officer_accounts_without_member_are_not_touched_by_expiry(): void
    {
        $shared = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'scanner', 'member_id' => null]);
        $this->artisan('tugas:kedaluwarsa')->assertSuccessful();
        $this->assertSame('scanner', $shared->fresh()->role);
        $this->actingAs($this->admin)->post('/tugas/perpanjang', ['ids' => []])->assertSessionHasErrors('ids');
    }
}
