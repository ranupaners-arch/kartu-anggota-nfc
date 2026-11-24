<?php

namespace Tests\Feature;

use App\Jobs\QueueHeartbeat;
use App\Logging\AddUserContext;
use App\Models\Organization;
use App\Models\User;
use App\Services\HealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Monolog\Handler\TestHandler;
use Sentry\SentrySdk;
use Tests\TestCase;

class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    private function beats(int $agoSeconds): void
    {
        Cache::put(HealthService::SCHEDULER_BEAT, now()->subSeconds($agoSeconds)->toIso8601String());
        Cache::put(HealthService::QUEUE_BEAT, now()->subSeconds($agoSeconds)->toIso8601String());
    }

    public function test_health_ok_when_all_checks_pass_and_details_need_token(): void
    {
        $this->beats(30);
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.status', 'ok')->assertJsonMissingPath('checks.database.message');

        config(['attendance.health_token' => 'rahasia-pantau']);
        $this->getJson('/api/health', ['X-Health-Token' => 'salah'])->assertJsonMissingPath('checks.queue.message');
        $this->getJson('/api/health', ['X-Health-Token' => 'rahasia-pantau'])
            ->assertJsonPath('checks.queue.message', fn ($m) => str_contains($m, 'Queue worker aktif'))->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_stale_worker_fails_with_503_but_fresh_start_only_warns(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'warn'); // baru mulai, belum ada detak
        $this->travel(10)->minutes();
        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('checks.queue.status', 'fail');

        $this->beats(30);
        $this->travel(6)->minutes(); // worker berhenti
        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('checks.scheduler.status', 'fail');
        $this->artisan('app:health')->expectsOutputToContain('FAIL queue')->assertFailed();
    }

    public function test_heartbeat_job_and_command(): void
    {
        (new QueueHeartbeat)->handle();
        Cache::put(HealthService::SCHEDULER_BEAT, now()->toIso8601String());
        $this->artisan('app:health')->expectsOutputToContain('Status: OK')->assertSuccessful();
        $this->artisan('app:health --json')->expectsOutputToContain('"status":"ok"')->assertSuccessful();
    }

    public function test_request_id_is_returned_reused_and_logged_with_user_ids_only(): void
    {
        $id = $this->getJson('/api/instance')->assertOk()->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
        $this->getJson('/api/instance', ['X-Request-Id' => 'proxy-abc-12345'])->assertHeader('X-Request-Id', 'proxy-abc-12345');
        $this->assertNotSame('jelek<script>', $this->getJson('/api/instance', ['X-Request-Id' => 'jelek<script>'])->headers->get('X-Request-Id'));

        // Baris log membawa request_id (Context) + id user/organisasi, tanpa email.
        config(['logging.channels.uji' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'tap' => [AddUserContext::class]], 'logging.default' => 'uji']);
        Route::middleware('auth:sanctum')->get('/api/_uji_log', fn () => Log::info('uji') ?? ['ok' => true]);
        $org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => 'scanner', 'email' => 'rahasia@x.test']);
        $this->actingAs($user, 'sanctum')->getJson('/api/_uji_log', ['X-Request-Id' => 'uji-log-123456'])->assertOk();

        $record = collect(Log::channel('uji')->getLogger()->getHandlers()[0]->getRecords())->last();
        $this->assertSame('uji-log-123456', $record->extra['request_id'] ?? $record->context['request_id'] ?? null);
        $this->assertSame([$user->id, $org->id], [$record->extra['user_id'], $record->extra['organization_id']]);
        $this->assertStringNotContainsString('rahasia@x.test', json_encode($record->toArray()));
    }

    public function test_settings_system_tab_and_sentry_off_without_dsn(): void
    {
        $this->withoutVite();
        $this->beats(10);
        $org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $admin = User::factory()->create(['organization_id' => $org->id, 'role' => 'super_admin']);
        $this->actingAs($admin)->get('/pengaturan?tab=sistem')->assertInertia(fn (Assert $p) => $p
            ->where('health.status', 'ok')->where('health.sentry', false)->has('health.checks.scheduler'));
        $this->assertNull(SentrySdk::getCurrentHub()->getClient()?->getOptions()->getDsn());
    }
}
