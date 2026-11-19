<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Division;
use App\Models\Event;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Services\ReportCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportCardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Term $term;
    private Member $member;
    private Division $humas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->member = Member::create(['organization_id' => $this->org->id, 'member_no' => 'B01', 'name' => 'Budi']);
        Membership::create(['member_id' => $this->member->id, 'term_id' => $this->term->id, 'division_id' => $this->humas->id, 'status' => 'active']);
        foreach (['hadir', 'telat', 'alpa', 'izin'] as $i => $st) {
            $s = now()->subDays(10 - $i);
            $e = Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => "Rapat $i", 'starts_at' => $s, 'ends_at' => $s->copy()->addHour(),
                'checkin_opens_at' => $s->copy()->subMinutes(30), 'late_after' => $s->copy()->addMinutes(10), 'checkin_closes_at' => $s->copy()->addHour()]);
            Attendance::create(['event_id' => $e->id, 'session_id' => $e->defaultSession()->id, 'member_id' => $this->member->id, 'status' => $st, 'scanned_at' => $st === 'alpa' || $st === 'izin' ? null : $s]);
        }
    }

    public function test_report_card_data_and_pdf(): void
    {
        $d = app(ReportCardService::class)->data($this->member, $this->term);
        $this->assertSame([1, 1, 1, 0, 1], array_values($d['counts']));
        $this->assertSame(50.0, (float) $d['rate']);
        $this->assertSame(['Rapat 0', 'Rapat 1', 'Rapat 2', 'Rapat 3'], array_column($d['rows'], 'event'));
        $this->assertSame('Humas', $d['division']);

        [$pdf, $name] = app(ReportCardService::class)->pdf($this->member, $this->term);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame('rapor-b01-2026.pdf', $name);
    }

    public function test_dashboard_and_member_app_download_with_scope(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->actingAs($admin)->get("/anggota/{$this->member->id}/rapor")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $inti = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
        $other = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $inti->id]);
        $this->actingAs($other)->get("/anggota/{$this->member->id}/rapor")->assertForbidden();

        $u = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $this->member->id]);
        $res = $this->actingAs($u, 'sanctum')->get('/api/me/report-card')->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertStringContainsString('rapor-b01-2026.pdf', $res->headers->get('Content-Disposition'));
    }
}
