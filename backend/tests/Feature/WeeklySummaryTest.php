<?php

namespace Tests\Feature;

use App\Mail\WeeklySummaryMail;
use App\Models\Attendance;
use App\Models\Division;
use App\Models\Event;
use App\Models\ExcuseRequest;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Ringkasan mingguan email untuk pengurus. */
class WeeklySummaryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private Division $humas;

    private Division $inti;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->inti = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
    }

    private function event(string $title, $start, ?Division $d = null, bool $closed = false): Event
    {
        return Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => $title, 'division_id' => $d?->id,
            'starts_at' => $start, 'ends_at' => $start->copy()->addHour(), 'checkin_opens_at' => $start->copy()->subMinutes(30),
            'late_after' => $start->copy()->addMinutes(10), 'checkin_closes_at' => $start->copy()->addHour(), 'closed_at' => $closed ? $start->copy()->addHours(2) : null]);
    }

    private function admin(string $email, string $role = 'super_admin', array $extra = []): User
    {
        return User::factory()->create(['organization_id' => $this->org->id, 'role' => $role, 'email' => $email] + $extra);
    }

    public function test_not_sent_until_mail_is_configured(): void
    {
        config(['mail.default' => 'log']);
        $this->admin('sa@hmps.test');
        $this->event('Rapat', now()->addDay());
        $this->artisan('ringkasan:mingguan')->expectsOutputToContain('belum diatur')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_sends_scoped_summary_to_opted_in_admins(): void
    {
        config(['mail.default' => 'smtp']);
        $sa = $this->admin('sa@hmps.test');
        $da = $this->admin('humas@hmps.test', 'division_admin', ['division_id' => $this->humas->id]);
        $this->admin('off@hmps.test', 'super_admin', ['weekly_summary' => false]);
        $this->admin('nonaktif@hmps.test', 'super_admin', ['disabled_at' => now()]);
        $otherOrg = Organization::create(['name' => 'Sepi', 'slug' => 'sepi']);
        User::factory()->create(['organization_id' => $otherOrg->id, 'role' => 'super_admin', 'email' => 'sepi@x.test']); // tidak ada isi

        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => 'B01', 'name' => 'Budi']);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'division_id' => $this->humas->id, 'status' => 'active']);
        $past = $this->event('Rapat Inti', now()->subDays(2), $this->inti, closed: true);
        Attendance::create(['event_id' => $past->id, 'session_id' => $past->defaultSession()->id, 'member_id' => $m->id, 'status' => 'hadir']);
        $this->event('Rapat Humas', now()->addDays(2), $this->humas);
        $this->event('Lama sekali', now()->subDays(20), closed: true);
        ExcuseRequest::create(['event_id' => $past->id, 'member_id' => $m->id, 'type' => 'izin', 'reason' => 'x', 'status' => 'pending']);

        $this->artisan('ringkasan:mingguan')->expectsOutputToContain('terkirim: 2')->assertSuccessful();

        Mail::assertSent(WeeklySummaryMail::class, 2);
        Mail::assertSent(WeeklySummaryMail::class, fn ($mail) => $mail->hasTo('sa@hmps.test')
            && collect($mail->data['closed'])->pluck('title')->all() === ['Rapat Inti']
            && $mail->data['closed'][0]['present'] === 1 && $mail->data['pendingExcuses'] === 1
            && collect($mail->data['upcoming'])->pluck('title')->all() === ['Rapat Humas']);
        // Admin divisi Humas: kegiatan Inti tidak termasuk.
        Mail::assertSent(WeeklySummaryMail::class, fn ($mail) => $mail->hasTo('humas@hmps.test')
            && $mail->data['closed'] === [] && $mail->data['pendingExcuses'] === 0
            && collect($mail->data['upcoming'])->pluck('title')->all() === ['Rapat Humas']);
        $this->assertNull(auth()->user()); // konteks login tidak bocor setelah perintah
        $html = (new WeeklySummaryMail(Mail::sent(WeeklySummaryMail::class, fn ($mail) => $mail->hasTo('sa@hmps.test'))->first()->data))->render();
        $this->assertStringContainsString('Ringkasan mingguan', $html);
        $this->assertStringContainsString('Rapat Inti', $html);
    }

    public function test_admin_toggles_summary_and_previews_it(): void
    {
        $sa = $this->admin('sa@hmps.test');
        $this->event('Rapat Pleno', now()->addDays(3));
        $this->actingAs($sa)->get('/akun')->assertInertia(fn (Assert $p) => $p->where('weeklySummary.enabled', true));
        $this->actingAs($sa)->get('/akun/ringkasan/contoh')->assertOk()->assertSee('Rapat Pleno');
        $this->actingAs($sa)->put('/akun/ringkasan', ['enabled' => false])->assertSessionHas('status');
        $this->assertFalse($sa->fresh()->weekly_summary);
    }

    public function test_settings_shows_mail_status_and_test_mail_needs_config(): void
    {
        $sa = $this->admin('sa@hmps.test');
        config(['mail.default' => 'log']);
        $this->actingAs($sa)->get('/pengaturan?tab=sistem')->assertInertia(fn (Assert $p) => $p->where('health.mail.enabled', false));
        $this->actingAs($sa)->post('/pengaturan/email-uji')->assertStatus(422);
        config(['mail.default' => 'smtp']);
        $this->actingAs($sa)->post('/pengaturan/email-uji')->assertSessionHas('status', 'Email uji dikirim ke sa@hmps.test.');
    }
}
