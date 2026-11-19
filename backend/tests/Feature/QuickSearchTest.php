<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Event;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickSearchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Term $term;
    private Division $humas;
    private Division $inti;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->inti = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
    }

    private function member(string $no, string $name, Division $d, ?Organization $org = null): Member
    {
        $m = Member::create(['organization_id' => ($org ?? $this->org)->id, 'member_no' => $no, 'name' => $name]);
        if (! $org) {
            Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'division_id' => $d->id, 'status' => 'active']);
        }

        return $m;
    }

    private function event(string $title, ?Division $d = null): Event
    {
        return Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => $title, 'division_id' => $d?->id,
            'starts_at' => now(), 'ends_at' => now()->addHour(), 'checkin_opens_at' => now()->subMinutes(30),
            'late_after' => now()->addMinutes(10), 'checkin_closes_at' => now()->addHour()]);
    }

    public function test_finds_members_by_name_or_number_and_events_by_title(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->member('B01', 'Budi Santoso', $this->humas);
        $this->member('X01', 'Rahmat', $this->inti);
        $this->member('Z01', 'Budi Lain', $this->humas, Organization::create(['name' => 'Lain', 'slug' => 'lain']));
        $this->event('Rapat Budi');

        $this->actingAs($admin)->getJson('/cari?q=budi')->assertOk()
            ->assertJsonCount(1, 'members')->assertJsonPath('members.0.name', 'Budi Santoso')
            ->assertJsonCount(1, 'events')->assertJsonPath('events.0.title', 'Rapat Budi');
        $this->actingAs($admin)->getJson('/cari?q=x01')->assertJsonPath('members.0.name', 'Rahmat');
        $this->actingAs($admin)->getJson('/cari?q=b')->assertExactJson(['members' => [], 'events' => []]);
        $this->actingAs($admin)->getJson('/cari?q=%25')->assertJsonCount(0, 'members'); // % bukan wildcard
    }

    public function test_division_admin_only_finds_own_division(): void
    {
        $da = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $this->humas->id]);
        $this->member('H1', 'Ani Humas', $this->humas);
        $this->member('I1', 'Ani Inti', $this->inti);
        $this->event('Rapat Ani Inti', $this->inti);
        $this->event('Rapat Ani Umum');

        $this->actingAs($da)->getJson('/cari?q=ani')->assertOk()
            ->assertJsonCount(1, 'members')->assertJsonPath('members.0.name', 'Ani Humas')
            ->assertJsonCount(1, 'events')->assertJsonPath('events.0.title', 'Rapat Ani Umum');
    }

    public function test_requires_login(): void
    {
        $this->getJson('/cari?q=budi')->assertUnauthorized();
    }
}
