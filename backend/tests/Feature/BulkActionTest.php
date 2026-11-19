<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Division;
use App\Models\Event;
use App\Models\ExcuseRequest;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tindakan massal: setujui/tolak banyak izin, pindah divisi/ubah status/arsipkan banyak anggota. */
class BulkActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Term $term;
    private User $admin;
    private Division $humas;
    private Division $inti;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->inti = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
    }

    private function member(string $no, ?Division $d = null, ?Organization $org = null): Member
    {
        $org ??= $this->org;
        $m = Member::create(['organization_id' => $org->id, 'member_no' => $no, 'name' => "Anggota $no"]);
        if ($org->is($this->org)) {
            Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'division_id' => $d?->id, 'status' => 'active']);
        }

        return $m;
    }

    private function event(?Division $d = null): Event
    {
        return Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => 'Rapat '.($d?->name ?? 'umum'), 'division_id' => $d?->id,
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'checkin_opens_at' => now()->addDay()->subMinutes(30),
            'late_after' => now()->addDay()->addMinutes(10), 'checkin_closes_at' => now()->addDay()->addHour()]);
    }

    private function excuse(Event $e, Member $m, string $status = 'pending'): ExcuseRequest
    {
        return ExcuseRequest::create(['event_id' => $e->id, 'member_id' => $m->id, 'type' => 'izin', 'reason' => 'x', 'status' => $status]);
    }

    public function test_admin_approves_many_pending_excuses_and_skips_reviewed_ones(): void
    {
        $e = $this->event();
        $a = $this->excuse($e, $this->member('A'));
        $b = $this->excuse($e, $this->member('B'));
        $done = $this->excuse($e, $this->member('C'), 'rejected');

        $this->actingAs($this->admin)->post('/izin/massal', ['ids' => [$a->id, $b->id, $done->id], 'status' => 'approved', 'note' => 'OK massal'])
            ->assertRedirect()->assertSessionHas('status', '2 pengajuan disetujui.');

        $this->assertSame(['approved', 'approved', 'rejected'], [$a->fresh()->status, $b->fresh()->status, $done->fresh()->status]);
        $this->assertSame('OK massal', $a->fresh()->review_note);
    }

    public function test_division_admin_bulk_review_only_touches_visible_excuses(): void
    {
        $da = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $this->humas->id]);
        $own = $this->excuse($this->event($this->humas), $this->member('A'));
        $other = $this->excuse($this->event($this->inti), $this->member('B'));

        $this->actingAs($da)->post('/izin/massal', ['ids' => [$own->id, $other->id], 'status' => 'rejected'])
            ->assertSessionHas('status', '1 pengajuan ditolak.');
        $this->assertSame(['rejected', 'pending'], [$own->fresh()->status, $other->fresh()->status]);
    }

    public function test_bulk_member_division_status_and_archive(): void
    {
        $a = $this->member('A', $this->humas);
        $b = $this->member('B');
        $c = $this->member('C', $this->humas);
        Card::create(['organization_id' => $this->org->id, 'member_id' => $c->id, 'public_id' => '01MASSALKARTU000000000000C', 'key_id' => 'k1', 'status' => Card::ACTIVE, 'issued_at' => now()]);
        $foreign = $this->member('X', org: Organization::create(['name' => 'Lain', 'slug' => 'lain']));

        $this->actingAs($this->admin)->post('/anggota/massal', ['ids' => [$a->id, $b->id, $foreign->id], 'action' => 'division', 'value' => (string) $this->inti->id])
            ->assertSessionHas('status', '2 anggota dipindahkan divisinya.');
        $this->assertSame([$this->inti->id, $this->inti->id], [$a->memberships()->value('division_id'), $b->memberships()->value('division_id')]);

        $this->actingAs($this->admin)->post('/anggota/massal', ['ids' => [$a->id], 'action' => 'status', 'value' => 'alumni'])->assertSessionHasNoErrors();
        $this->assertSame('alumni', $a->memberships()->value('status'));
        $this->actingAs($this->admin)->post('/anggota/massal', ['ids' => [$a->id], 'action' => 'status', 'value' => 'raja'])->assertStatus(422);

        $this->actingAs($this->admin)->post('/anggota/massal', ['ids' => [$c->id], 'action' => 'archive'])->assertSessionHas('status');
        $this->assertSoftDeleted($c);
        $this->assertSame(Card::BLOCKED, Card::where('member_id', $c->id)->value('status'));
        $this->assertNotSoftDeleted($foreign);
    }

    public function test_only_super_admin_can_bulk_edit_members(): void
    {
        $da = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $this->humas->id]);
        $a = $this->member('A', $this->humas);
        $this->actingAs($da)->post('/anggota/massal', ['ids' => [$a->id], 'action' => 'archive'])->assertForbidden();
        $this->assertNotSoftDeleted($a);
    }
}
