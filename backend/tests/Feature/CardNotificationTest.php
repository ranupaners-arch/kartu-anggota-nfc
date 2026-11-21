<?php

namespace Tests\Feature;

use App\Mail\NoticeMail;
use App\Models\CardRequest;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\Term;
use App\Models\User;
use App\Services\Push\PushTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecordingPushTransport implements PushTransport
{
    public array $sent = [];

    public function send(iterable $subscriptions, array $message): array
    {
        foreach ($subscriptions as $s) {
            $this->sent[] = ['user_id' => $s->user_id] + $message;
        }

        return [];
    }
}

/** Pemberitahuan alur kartu: pendaftar & laporan baru → Super Admin; pengesahan & keputusan → anggota. */
class CardNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Term $term;
    private User $admin;
    private RecordingPushTransport $push;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        config(['mail.default' => 'smtp', 'services.webpush.public_key' => 'BPub', 'services.webpush.private_key' => 'priv']);
        $this->push = new RecordingPushTransport;
        $this->app->instance(PushTransport::class, $this->push);
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin', 'email' => 'sa@hmps.test']);
        PushSubscription::create(['user_id' => $this->admin->id, 'endpoint' => 'https://push/a', 'endpoint_hash' => hash('sha256', 'https://push/a'), 'p256dh' => 'p', 'auth' => 'a']);
    }

    private function titlesFor(User $u): array
    {
        return collect($this->push->sent)->where('user_id', $u->id)->pluck('title')->all();
    }

    public function test_new_applicant_notifies_admin_and_approval_notifies_member(): void
    {
        $u = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => null, 'email' => 'dina@mhs.test']);
        PushSubscription::create(['user_id' => $u->id, 'endpoint' => 'https://push/d', 'endpoint_hash' => hash('sha256', 'https://push/d'), 'p256dh' => 'p', 'auth' => 'a']);
        $this->actingAs($u, 'web')->post('/saya/e-profil', ['name' => 'Dina', 'member_no' => '2501001', 'consent' => '1'])->assertRedirect('/saya');

        $this->assertSame(['Pendaftar baru: Dina'], $this->titlesFor($this->admin));
        Mail::assertSent(NoticeMail::class, fn ($m) => $m->hasTo('sa@hmps.test') && str_contains($m->url, '/anggota?tab=pendaftar'));

        $memberId = $u->fresh()->member_id;
        $this->actingAs($this->admin, 'web')->post('/anggota/massal', ['ids' => [$memberId], 'action' => 'status', 'value' => 'active']);
        $this->assertSame(['Keanggotaan disahkan'], $this->titlesFor($u));
        Mail::assertSent(NoticeMail::class, fn ($m) => $m->hasTo('dina@mhs.test') && $m->title === 'Keanggotaan disahkan');

        // Ubah status anggota yang sudah aktif (bukan pengesahan) → tidak memberi tahu lagi.
        $this->actingAs($this->admin, 'web')->post('/anggota/massal', ['ids' => [$memberId], 'action' => 'status', 'value' => 'active']);
        $this->assertCount(1, $this->titlesFor($u));
    }

    public function test_card_report_and_decision_with_note(): void
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => 'B01', 'name' => 'Budi', 'email' => 'budi@mhs.test']);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'status' => 'active']);
        $u = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $m->id, 'email' => 'budi@mhs.test']);

        Sanctum::actingAs($u);
        $this->postJson('/api/me/card/replacement', ['reason' => 'tanpa_nfc', 'note' => 'HP tanpa NFC'])->assertCreated();
        $this->assertSame(['Laporan kartu: Budi'], $this->titlesFor($this->admin));

        auth()->forgetGuards();
        $req = CardRequest::sole();
        $this->actingAs($this->admin, 'web')->post("/kartu/permintaan/{$req->id}", ['action' => 'approve', 'resolution' => 'admin', 'note' => 'Ambil Senin']);
        Mail::assertSent(NoticeMail::class, fn ($mail) => $mail->hasTo('budi@mhs.test') && $mail->title === 'Kartu Anda akan diterbitkan pengurus' && str_contains($mail->body, 'Ambil Senin'));
    }

    public function test_nothing_sent_and_no_error_when_push_and_mail_are_off(): void
    {
        config(['mail.default' => 'log', 'services.webpush.public_key' => null]);
        $u = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => null]);
        $this->actingAs($u, 'web')->post('/saya/e-profil', ['name' => 'Eko', 'member_no' => '2501009', 'consent' => '1'])->assertRedirect('/saya');
        $this->assertSame([], $this->push->sent);
        Mail::assertNothingSent();
    }
}
