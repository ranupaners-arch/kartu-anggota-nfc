<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\CardRequest;
use App\Models\Division;
use App\Models\Event;
use App\Models\ExcuseRequest;
use App\Models\Fine;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Kotak notifikasi anggota di app (GET /api/me/notifications). */
class MemberNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private Member $budi;

    private User $user;

    private Division $inti;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->inti = Division::create(['organization_id' => $this->org->id, 'name' => 'Inti']);
        $this->budi = Member::create(['organization_id' => $this->org->id, 'member_no' => '01', 'name' => 'Budi']);
        Membership::create(['member_id' => $this->budi->id, 'term_id' => $this->term->id, 'status' => 'active', 'division_id' => $this->inti->id]);
        $this->user = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $this->budi->id]);
    }

    private function event(array $over = []): Event
    {
        return Event::create($over + ['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => 'Rapat '.Str::random(4),
            'starts_at' => now()->subDays(2), 'ends_at' => now()->subDays(2)->addHours(2), 'checkin_opens_at' => now()->subDays(2)->subMinutes(20),
            'late_after' => now()->subDays(2)->addMinutes(15), 'checkin_closes_at' => now()->subDays(2)->addHour()]);
    }

    public function test_feed_combines_sources_newest_first(): void
    {
        $other = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
        Announcement::create(['organization_id' => $this->org->id, 'title' => 'Sekre tutup', 'body' => "Libur\n nasional.", 'created_at' => now()->subDays(3)]);
        Announcement::create(['organization_id' => $this->org->id, 'title' => 'Khusus Humas', 'body' => 'x', 'division_id' => $other->id]);
        Announcement::create(['organization_id' => $this->org->id, 'title' => 'Lama sekali', 'body' => 'x', 'created_at' => now()->subDays(40)]);

        $e = $this->event(['title' => 'Rapat Pleno']);
        ExcuseRequest::create(['event_id' => $e->id, 'member_id' => $this->budi->id, 'type' => 'sakit', 'reason' => 'Demam', 'status' => 'approved',
            'review_note' => 'Semoga lekas sembuh', 'reviewed_at' => now()->subDay()]);
        $e2 = $this->event(['title' => 'Rakernas']);
        $att = Attendance::create(['event_id' => $e2->id, 'session_id' => $e2->defaultSession()->id, 'member_id' => $this->budi->id, 'status' => 'hadir']);
        Fine::create(['member_id' => $this->budi->id, 'attendance_id' => $att->id, 'term_id' => $this->term->id, 'amount' => 5000]);
        CardRequest::create(['member_id' => $this->budi->id, 'user_id' => $this->user->id, 'reason' => 'hilang', 'status' => 'rejected', 'reviewed_at' => now()->subHours(2)]);
        CardRequest::create(['member_id' => $this->budi->id, 'user_id' => $this->user->id, 'reason' => 'rusak']); // masih menunggu → bukan notifikasi

        Sanctum::actingAs($this->user);
        $data = $this->getJson('/api/me/notifications')->assertOk()->json('data');

        $this->assertSame(['fine', 'card', 'excuse', 'announcement'], array_column($data, 'type'));
        $this->assertSame('Denda absen QR Rp5.000', $data[0]['title']);
        $this->assertStringContainsString('Rakernas', $data[0]['body']);
        $this->assertSame('Sakit disetujui: Rapat Pleno', $data[2]['title']);
        $this->assertSame('Semoga lekas sembuh', $data[2]['body']);
        $this->assertSame('Libur nasional.', $data[3]['body']); // dirapikan
        $this->assertSame(count($data), count(array_unique(array_column($data, 'id'))));
    }

    public function test_reminder_for_upcoming_event_until_attended_or_excused(): void
    {
        $e = $this->event(['title' => 'Musyawarah', 'location' => 'Aula', 'starts_at' => now()->addHours(5), 'ends_at' => now()->addHours(7),
            'checkin_opens_at' => now()->addHours(4), 'late_after' => now()->addHours(5), 'checkin_closes_at' => now()->addHours(6)]);
        $this->event(['title' => 'Minggu depan', 'starts_at' => now()->addDays(7), 'ends_at' => now()->addDays(7)->addHour(),
            'checkin_opens_at' => now()->addDays(7), 'late_after' => now()->addDays(7), 'checkin_closes_at' => now()->addDays(7)->addHour()]);

        Sanctum::actingAs($this->user);
        $data = $this->getJson('/api/me/notifications')->assertOk()->json('data');
        $this->assertSame(['Pengingat: Musyawarah'], array_column($data, 'title'));
        $this->assertStringContainsString('Aula', $data[0]['body']);

        ExcuseRequest::create(['event_id' => $e->id, 'member_id' => $this->budi->id, 'type' => 'izin', 'reason' => 'Ujian']);
        $this->assertSame([], $this->getJson('/api/me/notifications')->json('data'));
    }

    public function test_user_without_member_gets_422(): void
    {
        Sanctum::actingAs(User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member']));
        $this->getJson('/api/me/notifications')->assertStatus(422);
    }
}
