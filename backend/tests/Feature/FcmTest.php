<?php

namespace Tests\Feature;

use App\Jobs\WakePhones;
use App\Models\Announcement;
use App\Models\Device;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\PushDevice;
use App\Models\Term;
use App\Models\User;
use App\Services\DeviceService;
use App\Services\Push\FcmService;
use App\Services\Push\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as Outgoing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FcmTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $key = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        if (! self::$key) {
            openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), self::$key);
        }
        config(['services.fcm.credentials' => base64_encode(json_encode(['project_id' => 'proyek', 'client_email' => 'a@b.c', 'private_key' => self::$key]))]);
    }

    private function org(string $slug = 'hmps'): Organization
    {
        $o = Organization::create(['name' => strtoupper($slug), 'slug' => $slug]);
        Term::create(['organization_id' => $o->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);

        return $o;
    }

    private function member(Organization $o, string $no): User
    {
        $m = Member::create(['organization_id' => $o->id, 'member_no' => $no, 'name' => $no]);
        Membership::create(['member_id' => $m->id, 'term_id' => Term::withoutGlobalScopes()->where('organization_id', $o->id)->value('id'), 'status' => 'active']);

        return User::factory()->create(['organization_id' => $o->id, 'role' => 'member', 'member_id' => $m->id]);
    }

    /** Masuk lewat API lalu daftarkan token. @return string token masuk */
    private function phone(User $u, string $fcm, string $uuid, string $project = 'proyek', bool $active = true): string
    {
        $this->app['auth']->forgetGuards();
        $t = $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'password', 'device_uuid' => $uuid, 'device_name' => 'HP'])->assertOk()->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($t)->putJson('/api/me/push-token', ['token' => $fcm, 'project_id' => $project])->assertOk()->assertJson(['active' => $active]);

        return $t;
    }

    private function fake(int $status = 200, ?string $code = null): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'x']),
            'fcm.googleapis.com/*' => Http::response($code ? ['error' => ['status' => 'E', 'details' => [['errorCode' => $code]]]] : [], $status)]);
    }

    public function test_signal_carries_no_content_and_stays_in_its_organization(): void
    {
        $this->fake();
        $a = $this->org();
        $b = $this->org('lain');
        $this->phone($this->member($a, 'A1'), 'tok-a', 'u1');
        $this->phone($this->member($b, 'B1'), 'tok-b', 'u2');
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web'); // pengirim berjalan di antrean, tanpa login
        $this->assertNull(auth()->user());
        app(PushService::class)->announcement(Announcement::create(['organization_id' => $a->id, 'title' => 'Rahasia rapat', 'body' => 'Isi penting', 'pinned' => false]));
        $sent = Http::recorded(fn (Outgoing $r) => str_contains($r->url(), 'fcm.googleapis.com'))->map(fn ($x) => $x[0])->values();
        $this->assertCount(1, $sent);
        $this->assertSame(['token' => 'tok-a', 'data' => ['t' => 'sync'], 'android' => ['priority' => 'HIGH', 'ttl' => '86400s', 'collapse_key' => 'sync']], $sent[0]['message']);
        $this->assertStringNotContainsString('Rahasia', $sent[0]->body());
    }

    public function test_signing_out_revoking_and_role_changes_remove_the_phone(): void
    {
        $o = $this->org();
        $u = $this->member($o, 'A1');
        $t = $this->phone($u, 'tok', 'u1');
        $this->app['auth']->forgetGuards();
        $this->withToken($t)->postJson('/api/auth/logout')->assertNoContent();
        $this->assertSame(0, PushDevice::count());
        $this->phone($u, 'tok', 'u1');
        app(DeviceService::class)->revoke(Device::withoutGlobalScopes()->where('uuid', 'u1')->first());
        $this->assertSame(0, PushDevice::count());
        $this->phone($u, 'tok', 'u2');
        $u->tokens()->delete();
        $this->assertSame(0, PushDevice::count());
    }

    public function test_same_phone_moves_between_accounts_and_token_rotation_replaces(): void
    {
        $o = $this->org();
        [$x, $y] = [$this->member($o, 'A1'), $this->member($o, 'A2')];
        $this->phone($x, 'tok', 'u1');
        $t = $this->phone($y, 'tok', 'u2');
        $this->assertSame([$y->id], PushDevice::pluck('user_id')->all());
        $this->app['auth']->forgetGuards();
        $this->withToken($t)->putJson('/api/me/push-token', ['token' => 'tok-baru', 'project_id' => 'proyek']);
        $this->assertSame(['tok-baru'], PushDevice::pluck('token')->all());
    }

    public function test_other_firebase_project_or_server_without_firebase_is_not_registered(): void
    {
        $o = $this->org();
        $this->phone($this->member($o, 'A1'), 'tok', 'u1', 'proyek-lain', false);
        foreach ([null, 'bukan-base64!', base64_encode('{}')] as $i => $bad) {
            config(['services.fcm.credentials' => $bad]);
            $this->phone($this->member($o, "B$i"), "tok$i", "v$i", 'proyek', false);
        }
        $this->assertSame(0, PushDevice::count());
        Queue::fake();
        FcmService::wakeLater(collect([1]));
        Queue::assertNothingPushed();
    }

    public function test_dead_tokens_are_removed(): void
    {
        $this->fake(404, 'UNREGISTERED');
        $u = $this->member($this->org(), 'A1');
        $this->phone($u, 'tok', 'u1');
        $this->assertSame([], app(FcmService::class)->wake([$u->id]));
        $this->assertSame(0, PushDevice::count());
    }

    public function test_temporary_failures_retry_only_those_phones_then_stop(): void
    {
        $this->fake(503);
        $u = $this->member($this->org(), 'A1');
        $this->phone($u, 'tok', 'u1');
        Queue::fake();
        (new WakePhones([$u->id]))->handle(app(FcmService::class));
        Queue::assertPushed(WakePhones::class, fn ($j) => $j->deviceIds === [PushDevice::value('id')] && $j->round === 2);
        (new WakePhones([], [PushDevice::value('id')], 3))->handle(app(FcmService::class));
        Queue::assertPushed(WakePhones::class, 1);
        $this->assertSame(1, PushDevice::count());
    }

    public function test_disabled_accounts_are_not_woken(): void
    {
        $this->fake();
        $u = $this->member($this->org(), 'A1');
        $this->phone($u, 'tok', 'u1');
        $u->update(['disabled_at' => now()]);
        app(FcmService::class)->wake([$u->id]);
        Http::assertNothingSent();
    }

    public function test_google_access_token_is_signed_and_reused(): void
    {
        $this->fake();
        $u = $this->member($this->org(), 'A1');
        $this->phone($u, 'tok', 'u1');
        app(FcmService::class)->wake([$u->id]);
        app(FcmService::class)->wake([$u->id]);
        $auth = Http::recorded(fn (Outgoing $r) => str_contains($r->url(), 'oauth2'));
        $this->assertCount(1, $auth);
        [$h, $p, $s] = explode('.', $auth->first()[0]['assertion']);
        $pub = openssl_pkey_get_details(openssl_pkey_get_private(self::$key))['key'];
        $this->assertSame(1, openssl_verify("$h.$p", base64_decode(strtr($s, '-_', '+/')), $pub, OPENSSL_ALGO_SHA256));
    }

    public function test_requires_login(): void
    {
        $this->putJson('/api/me/push-token', ['token' => 'x', 'project_id' => 'proyek'])->assertUnauthorized();
    }
}
