<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Card;
use App\Models\Division;
use App\Models\Event;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Support\CardPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Anggaran query untuk scan (PRD §13: scan < 1 detik, batch 200 < 5 detik).
 * Jumlah query dipakai sebagai patokan (stabil di CI); waktu nyata diuji di uji beban stack Docker
 * (.github/scripts/uji-beban-scan.py). PERF_DUMP=<berkas> menulis daftar query untuk analisis.
 */
class ScanPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $scanner;

    private Event $event;

    /** @var array<int, string> */
    private array $payloads = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['attendance.card_keys.k1' => 'test-secret', 'attendance.payload_prefix' => 'card']);
        $org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $term = Term::create(['organization_id' => $org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $div = Division::create(['organization_id' => $org->id, 'name' => 'Humas']);
        $this->scanner = User::factory()->create(['organization_id' => $org->id, 'role' => 'scanner']);
        $now = now();
        for ($i = 1; $i <= 250; $i++) {
            $m = Member::create(['organization_id' => $org->id, 'member_no' => sprintf('P%04d', $i), 'name' => "Peserta $i"]);
            Membership::create(['member_id' => $m->id, 'term_id' => $term->id, 'division_id' => $div->id, 'status' => 'active']);
            $card = Card::create(['organization_id' => $org->id, 'public_id' => (string) Str::ulid(), 'key_id' => 'k1', 'status' => 'active', 'member_id' => $m->id, 'issued_at' => $now]);
            $this->payloads[] = CardPayload::make($card);
        }
        $this->event = Event::create(['organization_id' => $org->id, 'term_id' => $term->id, 'title' => 'Besar',
            'starts_at' => $now, 'ends_at' => $now->copy()->addHours(2), 'checkin_opens_at' => $now->copy()->subHour(),
            'late_after' => $now->copy()->addMinutes(10), 'checkin_closes_at' => $now->copy()->addHour()]);
        $this->event->targets()->create(['division_id' => $div->id]); // target per divisi: jalur terberat
    }

    private function countQueries(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $n = count(DB::getQueryLog());
        if (getenv('PERF_DUMP')) {
            foreach (collect(DB::getQueryLog())->countBy(fn ($q) => preg_replace('/\s+/', ' ', $q['query']))->sortDesc()->take(25) as $q => $c) {
                file_put_contents(getenv('PERF_DUMP'), "{$c}x ".substr($q, 0, 260)."\n", FILE_APPEND);
            }
        }
        DB::disableQueryLog();

        return $n;
    }

    public function test_single_scan_query_budget(): void
    {
        $this->actingAs($this->scanner, 'sanctum');
        $this->postJson("/api/events/{$this->event->id}/scan", ['payload' => $this->payloads[0]])->assertJson(['status' => 'ok']); // pemanasan
        $n = $this->countQueries(fn () => $this->postJson("/api/events/{$this->event->id}/scan", ['payload' => $this->payloads[1], 'client_uuid' => (string) Str::uuid()])->assertJson(['status' => 'ok']));
        fwrite(STDERR, "\n[perf] query per scan: $n\n");
        $this->assertLessThanOrEqual(12, $n);
    }

    public function test_batch_200_query_budget_and_time(): void
    {
        $this->actingAs($this->scanner, 'sanctum');
        $scans = collect(array_slice($this->payloads, 0, 200))->map(fn ($p) => [
            'payload' => $p, 'client_uuid' => (string) Str::uuid(), 'scanned_at' => now()->toIso8601String(),
        ])->all();
        $t = hrtime(true);
        $n = $this->countQueries(fn () => $this->postJson("/api/events/{$this->event->id}/scan/batch", ['scans' => $scans])
            ->assertOk()->assertJsonPath('summary.ok', 200));
        $ms = (hrtime(true) - $t) / 1e6;
        fwrite(STDERR, sprintf("[perf] batch 200: %d query, %.0f ms (%.1f query/scan)\n", $n, $ms, $n / 200));
        $this->assertLessThanOrEqual(420, $n); // ±2 query per scan (insert + cek anomali)
        $this->assertLessThan(5000, $ms);
    }

    public function test_batch_prefetch_keeps_rules_for_repeats_inside_one_batch(): void
    {
        $this->actingAs($this->scanner, 'sanctum');
        $uuid = (string) Str::uuid();
        $at = now()->toIso8601String();
        $scans = [
            ['payload' => $this->payloads[0], 'client_uuid' => $uuid, 'scanned_at' => $at],
            ['payload' => $this->payloads[0], 'client_uuid' => (string) Str::uuid(), 'scanned_at' => $at], // anggota sama, scan lain
            ['payload' => $this->payloads[1], 'client_uuid' => $uuid, 'scanned_at' => $at],                  // uuid sama: dianggap kirim ulang
            ['payload' => 'card:v1:k1:01ZZZZZZZZZZZZZZZZZZZZZZZZ:000000000000000000000000', 'client_uuid' => (string) Str::uuid(), 'scanned_at' => $at],
        ];
        $r = $this->postJson("/api/events/{$this->event->id}/scan/batch", ['scans' => $scans])->assertOk()->json('results');
        $this->assertSame(['ok', 'duplicate', 'duplicate', 'invalid'], array_column($r, 'status'));
        $this->assertSame(1, Attendance::count());

        // Kirim ulang batch yang sama (mis. koneksi putus sebelum respons): tidak ada data ganda.
        $again = $this->postJson("/api/events/{$this->event->id}/scan/batch", ['scans' => $scans])->json('results');
        $this->assertSame(['duplicate', 'duplicate', 'duplicate', 'invalid'], array_column($again, 'status'));
        $this->assertSame(1, Attendance::count());
    }
}
