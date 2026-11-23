<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Poin & lencana keaktifan anggota (GET /api/me/achievements). */
class AchievementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private Member $budi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => now()->subYear()->toDateString(), 'ends_on' => now()->addYear()->toDateString(), 'is_current' => true]);
        $this->budi = Member::create(['organization_id' => $this->org->id, 'member_no' => '01', 'name' => 'Budi']);
        Membership::create(['member_id' => $this->budi->id, 'term_id' => $this->term->id, 'status' => 'active']);
        Sanctum::actingAs(User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $this->budi->id]));
    }

    /** @param list<array{0: string, 1?: string}> $rows status & sumber per kegiatan, urut waktu */
    private function attend(array $rows): void
    {
        foreach ($rows as $i => $row) {
            $at = now()->subDays(count($rows) - $i);
            $e = Event::create(['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => 'K'.Str::random(3),
                'starts_at' => $at, 'ends_at' => $at->copy()->addHour(), 'checkin_opens_at' => $at->copy()->subMinutes(10),
                'late_after' => $at->copy()->addMinutes(10), 'checkin_closes_at' => $at->copy()->addMinutes(30)]);
            Attendance::create(['event_id' => $e->id, 'session_id' => $e->defaultSession()->id, 'member_id' => $this->budi->id,
                'status' => $row[0], 'source' => in_array($row[0], ['hadir', 'telat'], true) ? ($row[1] ?? 'nfc') : 'manual']);
        }
    }

    private function badges(): array
    {
        return collect($this->getJson('/api/me/achievements')->assertOk()->json('badges'))->keyBy('key')->all();
    }

    public function test_new_member_has_no_points_and_locked_badges(): void
    {
        $r = $this->getJson('/api/me/achievements')->assertOk()->json();
        $this->assertSame([0, 0], [$r['points'], $r['earned']]);
        $this->assertCount(6, $r['badges']);
        $this->assertFalse(collect($r['badges'])->contains('earned', true));
    }

    public function test_points_and_badges_from_attendance(): void
    {
        // 5× hadir tepat waktu (NFC), 1 telat, 1 izin → 7 kegiatan, 6 hadir/telat, tanpa alpa.
        $this->attend([['hadir'], ['hadir'], ['hadir'], ['hadir'], ['hadir'], ['telat'], ['izin']]);
        $r = $this->getJson('/api/me/achievements')->json();
        $this->assertSame(5 * 10 + 6 + 2, $r['points']);
        $b = collect($r['badges'])->keyBy('key');

        $this->assertTrue($b['first']['earned']);
        $this->assertTrue($b['on_time_5']['earned']);
        $this->assertSame(0, $b['on_time_5']['progress']); // runtun berjalan putus oleh telat/izin
        $this->assertFalse($b['streak_10']['earned']);
        $this->assertSame(0, $b['streak_10']['progress']); // izin memutus runtun hadir
        $this->assertFalse($b['diligent']['earned']); // 6/7 = 85%
        $this->assertSame([85, 'percent'], [$b['diligent']['progress'], $b['diligent']['unit']]);
        $this->assertTrue($b['no_alpa']['earned']);
        $this->assertTrue($b['card_loyal']['earned']);
        $this->assertSame(4, $r['earned']);
    }

    public function test_qr_attendance_and_alpa_block_badges_with_hint(): void
    {
        $this->attend([['hadir'], ['hadir', 'qr'], ['alpa'], ['hadir'], ['hadir'], ['hadir']]);
        $b = $this->badges();
        $this->assertFalse($b['card_loyal']['earned']);
        $this->assertSame('Pernah absen dengan QR periode ini', $b['card_loyal']['hint']);
        $this->assertFalse($b['no_alpa']['earned']);
        $this->assertSame('Tercatat Alpa 1× periode ini', $b['no_alpa']['hint']);
        $this->assertSame(3, $b['streak_10']['progress']); // runtun berjalan setelah alpa
    }

    public function test_diligent_waits_for_minimum_events(): void
    {
        $this->attend([['hadir'], ['hadir']]);
        $b = $this->badges();
        $this->assertFalse($b['diligent']['earned']);
        $this->assertSame('Dinilai setelah 5 kegiatan', $b['diligent']['hint']);
        $this->assertSame(2, $b['no_alpa']['progress']);
    }
}
