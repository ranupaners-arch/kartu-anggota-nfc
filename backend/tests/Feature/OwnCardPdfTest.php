<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Anggota mengunduh PDF kartunya sendiri untuk dicetak (hanya bila sudah disahkan). */
class OwnCardPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_own_card_pdf_only_when_active(): void
    {
        $org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $term = Term::create(['organization_id' => $org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $m = Member::create(['organization_id' => $org->id, 'member_no' => 'B01', 'name' => 'Budi']);
        $ms = Membership::create(['member_id' => $m->id, 'term_id' => $term->id, 'status' => 'applicant']);
        $u = User::factory()->create(['organization_id' => $org->id, 'role' => 'member', 'member_id' => $m->id, 'password' => 'RahasiaLama123']);

        Sanctum::actingAs($u);
        $this->get('/api/me/card.pdf')->assertForbidden(); // belum disahkan

        $ms->update(['status' => 'active']);
        $res = $this->get('/api/me/card.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('kartu-b01.pdf', $res->headers->get('Content-Disposition'));
        $this->assertSame(2, preg_match_all('#/Type\s*/Page[^s]#', $res->getContent())); // depan + belakang

        auth()->forgetGuards();
        $this->actingAs($u, 'web')->get('/saya/kartu.pdf?format=a4')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
