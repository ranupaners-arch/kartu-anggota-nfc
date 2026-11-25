<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\Division;
use App\Models\Event;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\CertificateService;
use App\Services\PrivacyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private User $admin;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $at = now()->subDay();
        $this->event = Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => 'Seminar <b>Nasional</b>', 'starts_at' => $at, 'ends_at' => $at->copy()->addHours(2),
            'checkin_opens_at' => $at->copy()->subHour(), 'late_after' => $at->copy()->addMinutes(10), 'checkin_closes_at' => $at->copy()->addHour(), 'closed_at' => now()]);
        foreach (['Ani' => 'hadir', 'Budi' => 'telat', 'Cici' => 'izin', 'Dodi' => 'alpa'] as $n => $st) {
            $this->member($n, $st);
        }
    }

    private function member(string $n, ?string $status): Member
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $n, 'name' => $n]);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'status' => 'active']);
        if ($status) {
            $this->attend($m, $status);
        }

        return $m;
    }

    private function attend(Member $m, string $status, ?int $session = null): Attendance
    {
        return Attendance::create(['event_id' => $this->event->id, 'session_id' => $session ?? $this->event->defaultSession()->id, 'member_id' => $m->id, 'status' => $status, 'source' => 'nfc']);
    }

    private function m(string $n): Member
    {
        return Member::withoutGlobalScopes()->where('member_no', $n)->firstOrFail();
    }

    private function cert(string $n): ?Certificate
    {
        return Certificate::withoutGlobalScopes()->where('member_id', $this->m($n)->id)->first();
    }

    private function issue(array $extra = [], ?User $by = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($by ?? $this->admin, 'web')->put("/kegiatan/{$this->event->id}/sertifikat", $extra + ['title' => 'Sertifikat', 'role' => 'Peserta', 'min_sessions' => 1, 'signer1_name' => 'Sari', 'signer1_title' => 'Ketua']);
    }

    private function memberUser(string $n): User
    {
        return User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $this->m($n)->id]);
    }

    public function test_only_present_members_get_one_after_the_event_is_closed(): void
    {
        $this->event->update(['closed_at' => null]);
        $this->issue()->assertSessionHasErrors('event');
        $this->event->update(['closed_at' => now()]);
        $this->issue()->assertSessionHas('status', 'Sertifikat: 2 diterbitkan, 0 dicabut, 0 dipulihkan.');
        $this->issue()->assertSessionHas('status', 'Sertifikat: 0 diterbitkan, 0 dicabut, 0 dipulihkan.');
        $this->assertNotNull($this->cert('Ani'));
        $this->assertNotNull($this->cert('Budi'));
        $this->assertNull($this->cert('Cici'));
        $this->assertNotSame($this->cert('Ani')->code, $this->cert('Budi')->code);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{16}$/', $this->cert('Ani')->code);
    }

    public function test_reissue_follows_attendance_corrections_and_numbers_are_never_reused(): void
    {
        $this->issue();
        $code = $this->cert('Ani')->code;
        $this->actingAs($this->admin);
        $a = Attendance::where('member_id', $this->m('Ani')->id)->sole();
        app(AttendanceService::class)->correct($this->admin, $a, null, true, 'salah orang');
        $this->issue()->assertSessionHas('status', 'Sertifikat: 0 diterbitkan, 1 dicabut, 0 dipulihkan.');
        $this->assertNotNull($this->cert('Ani')->revoked_at);
        $this->member('Eko', 'hadir');
        $this->issue();
        $this->assertSame(3, $this->cert('Eko')->seq);
        app(AttendanceService::class)->correct($this->admin, $a->fresh(), 'hadir', false, 'ternyata benar');
        $this->issue()->assertSessionHas('status', 'Sertifikat: 0 diterbitkan, 0 dicabut, 1 dipulihkan.');
        $this->assertSame($code, $this->cert('Ani')->fresh()->code);
    }

    public function test_manual_revocation_survives_reissue_and_can_be_restored(): void
    {
        $this->issue();
        $id = $this->cert('Ani')->id;
        $this->actingAs($this->admin, 'web')->post("/kegiatan-sertifikat/$id/cabut", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/kegiatan-sertifikat/$id/cabut", ['reason' => 'Plagiat'])->assertSessionHasNoErrors();
        $this->issue();
        $this->assertNotNull($this->cert('Ani')->revoked_at);
        $this->get("/kegiatan-sertifikat/$id.pdf")->assertStatus(410);
        $this->post("/kegiatan-sertifikat/$id/pulihkan")->assertSessionHasNoErrors();
        $this->assertNull($this->cert('Ani')->revoked_at);
        Attendance::where('member_id', $this->m('Ani')->id)->update(['status' => 'alpa']);
        $this->post("/kegiatan-sertifikat/$id/cabut", ['reason' => 'lagi']);
        $this->post("/kegiatan-sertifikat/$id/pulihkan")->assertStatus(422);
    }

    public function test_minimum_sessions(): void
    {
        $s2 = $this->event->sessions()->create(['title' => 'Sesi 2', 'starts_at' => now()->subHours(3), 'ends_at' => now()->subHours(2)]);
        $this->attend($this->m('Ani'), 'hadir', $s2->id);
        $this->issue(['min_sessions' => 3])->assertSessionHasErrors('min_sessions');
        $this->issue(['min_sessions' => 2])->assertSessionHasNoErrors();
        $this->assertNotNull($this->cert('Ani'));
        $this->assertNull($this->cert('Budi'));
    }

    public function test_public_verification_shows_only_what_is_on_the_paper(): void
    {
        $this->issue();
        $c = $this->cert('Ani');
        auth()->logout();
        $this->get('/sertifikat/'.strtolower($c->displayCode()))->assertOk()->assertHeader('X-Robots-Tag', 'noindex')->assertInertia(fn ($p) => $p
            ->component('certificates/verify')->where('result.valid', true)->where('result.name', 'Ani')->where('result.number', sprintf('001/HMPS/%d/%s', $this->event->id, $this->event->starts_at->format('Y')))
            ->missing('result.member_no')->missing('result.member_id'));
        $c->update(['revoked_at' => now()]);
        $this->get("/sertifikat/{$c->code}")->assertOk()->assertInertia(fn ($p) => $p->where('result.valid', false)->where('result.name', null)->missing('result.reason'));
        $this->get('/sertifikat/AAAAAAAAAAAAAAAA')->assertNotFound()->assertInertia(fn ($p) => $p->where('result', null));
        $this->get('/sertifikat/pendek')->assertNotFound();
    }

    public function test_members_see_and_download_only_their_own(): void
    {
        $this->issue();
        $ani = $this->memberUser('Ani');
        $this->app['auth']->forgetGuards();
        $list = $this->actingAs($ani, 'sanctum')->getJson('/api/me/certificates')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $pdf = $this->get("/api/me/certificates/{$list[0]['id']}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->getJson("/api/me/certificates/{$this->cert('Budi')->id}/pdf")->assertNotFound();
        $this->assertContains('certificate', array_column($this->getJson('/api/me/notifications')->json('data'), 'type'));
        $this->cert('Ani')->update(['revoked_at' => now()]);
        $this->assertSame([], $this->getJson('/api/me/certificates')->json('data'));
        $this->getJson("/api/me/certificates/{$list[0]['id']}/pdf")->assertStatus(410);

        $this->app['auth']->forgetGuards();
        $budi = $this->memberUser('Budi');
        $this->actingAs($budi, 'web')->get('/saya/riwayat')->assertInertia(fn ($p) => $p->has('certificates', 1));
        $this->actingAs($budi, 'web')->get("/saya/sertifikat/{$this->cert('Budi')->id}.pdf")->assertOk();
        $this->actingAs($budi, 'web')->get("/saya/sertifikat/{$this->cert('Ani')->id}.pdf")->assertNotFound();
    }

    public function test_division_and_organization_boundaries(): void
    {
        $this->issue();
        $id = $this->cert('Ani')->id;
        $a = Division::create(['organization_id' => $this->org->id, 'name' => 'A']);
        $b = Division::create(['organization_id' => $this->org->id, 'name' => 'B']);
        $this->event->update(['division_id' => $a->id]);
        $da = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $b->id]);
        $this->issue(by: $da)->assertForbidden();
        $this->post("/kegiatan-sertifikat/$id/cabut", ['reason' => 'coba'])->assertForbidden();
        $this->get("/kegiatan-sertifikat/$id.pdf")->assertForbidden();
        $own = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin', 'division_id' => $a->id]);
        $this->issue(by: $own)->assertSessionHasNoErrors();
        $foreign = User::factory()->create(['organization_id' => Organization::create(['name' => 'L', 'slug' => 'l'])->id, 'role' => 'super_admin']);
        $this->issue(by: $foreign)->assertNotFound();
        $this->get("/kegiatan-sertifikat/$id.pdf")->assertNotFound();
    }

    public function test_anonymized_member_is_removed_from_verification(): void
    {
        $this->issue();
        $c = $this->cert('Ani');
        $this->m('Ani')->memberships()->update(['status' => 'alumni']);
        $this->app['auth']->forgetGuards();
        app(PrivacyService::class)->anonymize($this->m('Ani'), null, 'uji');
        $this->assertNull(app(CertificateService::class)->verify($c->code)['name']);
        $this->assertStringNotContainsString('Ani', $c->fresh()->member_name.$c->fresh()->revoke_reason);
        $this->issue();
        $this->assertNotNull($c->fresh()->revoked_at);
    }

    public function test_name_on_paper_is_kept_and_dashboard_tab_shows_pending_changes(): void
    {
        $this->issue();
        $this->m('Ani')->update(['name' => 'Nama Baru']);
        $this->assertSame('Ani', $this->cert('Ani')->member_name);
        Attendance::where('member_id', $this->m('Cici')->id)->update(['status' => 'hadir']);
        $this->get("/kegiatan/{$this->event->id}?tab=sertifikat")->assertOk()->assertInertia(fn ($p) => $p->where('certificate.issued', true)->where('certificate.plan', ['eligible' => 3, 'add' => 1, 'revoke' => 0, 'restore' => 0])->has('certificate.items.data', 2));
    }

    public function test_second_signer_needs_both_name_and_title(): void
    {
        $this->issue(['signer2_name' => 'Tono'])->assertSessionHasErrors('signer2_title');
        $this->issue(['signer1_name' => ''])->assertSessionHasErrors('signer1_name');
    }
}
