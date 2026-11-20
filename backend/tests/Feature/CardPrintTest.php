<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Desain kartu anggota siap cetak (PDF). */
class CardPrintTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Term $term;
    private Division $humas;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
    }

    private function members(int $n): array
    {
        return collect(range(1, $n))->map(function ($i) {
            $m = Member::create(['organization_id' => $this->org->id, 'member_no' => "B$i", 'name' => "Anggota $i"]);
            Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'division_id' => $this->humas->id, 'status' => 'active']);

            return $m->id;
        })->all();
    }

    private function pages(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    public function test_a4_sheet_has_ten_cards_per_page_front_and_back(): void
    {
        $ids = $this->members(12);
        $res = $this->actingAs($this->admin)->get('/anggota/kartu-cetak?'.http_build_query(['ids' => $ids]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('kartu-anggota-12.pdf', $res->headers->get('Content-Disposition'));
        $this->assertSame(4, $this->pages($res->getContent())); // 2 lembar × (depan + belakang)
    }

    public function test_single_card_format_for_pvc_printer(): void
    {
        $ids = $this->members(1);
        $res = $this->actingAs($this->admin)->get('/anggota/kartu-cetak?'.http_build_query(['ids' => $ids, 'format' => 'kartu']))->assertOk();
        $this->assertSame(2, $this->pages($res->getContent()));
        $this->assertStringContainsString('kartu-b1.pdf', $res->headers->get('Content-Disposition'));
        $this->actingAs($this->admin)->get('/anggota/kartu-cetak?'.http_build_query(['ids' => $ids, 'format' => 'poster']))->assertSessionHasErrors('format');
    }

    public function test_only_super_admin_and_own_organization(): void
    {
        $ids = $this->members(1);
        $da = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $this->humas->id]);
        $this->actingAs($da)->get('/anggota/kartu-cetak?'.http_build_query(['ids' => $ids]))->assertForbidden();

        $other = Organization::create(['name' => 'Lain', 'slug' => 'lain']);
        $foreign = Member::create(['organization_id' => $other->id, 'member_no' => 'X1', 'name' => 'Orang Lain']);
        $this->actingAs($this->admin)->get('/anggota/kartu-cetak?'.http_build_query(['ids' => [$foreign->id]]))->assertNotFound();
    }
}
