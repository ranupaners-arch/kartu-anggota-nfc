<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Division;
use App\Models\Event;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\Term;
use App\Models\User;
use App\Services\Push\PushTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Pengirim palsu: mencatat pesan, bisa menandai langganan kedaluwarsa. */
class FakePushTransport implements PushTransport
{
    public array $sent = [];

    public array $expire = [];

    public function send(iterable $subscriptions, array $message): array
    {
        foreach ($subscriptions as $s) {
            $this->sent[] = ['user_id' => $s->user_id, 'endpoint' => $s->endpoint] + $message;
        }

        return collect($subscriptions)->filter(fn ($s) => in_array($s->endpoint, $this->expire, true))->pluck('endpoint_hash')->values()->all();
    }
}

/** Notifikasi push portal anggota (Web Push). */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private Division $humas;

    private Division $inti;

    private FakePushTransport $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.webpush.public_key' => 'BPublik', 'services.webpush.private_key' => 'privat']);
        $this->fake = new FakePushTransport;
        $this->app->instance(PushTransport::class, $this->fake);
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        $this->inti = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
    }

    private function memberUser(string $no, Division $d): User
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $no, 'name' => "Anggota $no"]);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->term->id, 'division_id' => $d->id, 'status' => 'active']);

        return User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $m->id]);
    }

    private function subscribe(User $u, string $endpoint): void
    {
        $this->actingAs($u)->postJson('/saya/notifikasi', ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'p', 'auth' => 'a']])->assertNoContent();
    }

    public function test_subscription_lifecycle_and_disabled_state(): void
    {
        $u = $this->memberUser('A', $this->humas);
        $this->actingAs($u)->get('/saya/akun')->assertInertia(fn (Assert $p) => $p->where('push.publicKey', 'BPublik'));
        $this->subscribe($u, 'https://push.example/1');
        $this->subscribe($u, 'https://push.example/1'); // sama → diperbarui, bukan dobel
        $this->assertSame(1, PushSubscription::count());
        $this->actingAs($u)->postJson('/saya/notifikasi', ['endpoint' => 'http://tidak-aman/1', 'keys' => ['p256dh' => 'p', 'auth' => 'a']])->assertUnprocessable();

        $this->actingAs($u)->postJson('/saya/notifikasi/uji')->assertJsonPath('devices', 1);
        $this->assertSame('Notifikasi aktif', $this->fake->sent[0]['title']);

        $this->actingAs($u)->deleteJson('/saya/notifikasi', ['endpoint' => 'https://push.example/1'])->assertNoContent();
        $this->assertSame(0, PushSubscription::count());

        config(['services.webpush.public_key' => null]);
        $this->actingAs($u)->get('/saya/akun')->assertInertia(fn (Assert $p) => $p->where('push.publicKey', null));
        $this->actingAs($u)->postJson('/saya/notifikasi', ['endpoint' => 'https://push.example/2', 'keys' => ['p256dh' => 'p', 'auth' => 'a']])->assertStatus(503);
    }

    public function test_new_announcement_reaches_members_of_its_division(): void
    {
        $humas = $this->memberUser('H', $this->humas);
        $inti = $this->memberUser('I', $this->inti);
        $this->subscribe($humas, 'https://push.example/h');
        $this->subscribe($inti, 'https://push.example/i');
        $admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);

        $this->actingAs($admin)->post('/pengumuman', ['title' => 'Rapat Humas', 'body' => 'Bawa laptop.', 'division_id' => $this->humas->id])->assertSessionHas('status');
        $this->assertSame([[$humas->id, 'Pengumuman: Rapat Humas', '/saya/kegiatan']], collect($this->fake->sent)->map(fn ($m) => [$m['user_id'], $m['title'], $m['url']])->all());
    }

    public function test_event_reminder_once_for_members_not_yet_present_and_expired_devices_removed(): void
    {
        $a = $this->memberUser('A', $this->humas);
        $b = $this->memberUser('B', $this->humas);
        $c = $this->memberUser('C', $this->humas);
        $this->subscribe($a, 'https://push.example/a');
        $this->subscribe($b, 'https://push.example/b');
        $this->subscribe($c, 'https://push.example/c-mati');
        $this->fake->expire = ['https://push.example/c-mati'];
        $e = Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => 'Rapat Pleno', 'location' => 'Aula',
            'starts_at' => now()->addMinutes(40), 'ends_at' => now()->addHours(2), 'checkin_opens_at' => now()->addMinutes(10),
            'late_after' => now()->addMinutes(50), 'checkin_closes_at' => now()->addMinutes(90)]);
        Attendance::create(['event_id' => $e->id, 'session_id' => $e->defaultSession()->id, 'member_id' => $b->member_id, 'status' => 'hadir']);
        auth()->forgetGuards();

        $this->artisan('push:pengingat')->assertSuccessful();
        $this->assertEqualsCanonicalizing([$a->id, $c->id], collect($this->fake->sent)->pluck('user_id')->all());
        $this->assertStringStartsWith('Pengingat: Rapat Pleno', $this->fake->sent[0]['title']);
        $this->assertStringContainsString('Aula', $this->fake->sent[0]['body']);
        $this->assertSame(2, PushSubscription::count()); // langganan C kedaluwarsa → dihapus

        $this->artisan('push:pengingat')->assertSuccessful();
        $this->assertCount(2, $this->fake->sent); // tidak diulang
    }
}
