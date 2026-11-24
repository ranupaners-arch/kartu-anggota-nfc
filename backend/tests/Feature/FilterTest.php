<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Event;
use App\Models\ExcuseRequest;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Pencarian & filter di Kegiatan, Pengajuan Izin, dan Log. */
class FilterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Term $term;

    private User $admin;

    private Division $humas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->term = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->humas = Division::create(['organization_id' => $this->org->id, 'name' => 'Humas']);
    }

    private function event(string $title, string $start, array $extra = []): Event
    {
        $s = Carbon::parse($start);

        return Event::create($extra + ['organization_id' => $this->org->id, 'term_id' => $this->term->id, 'title' => $title,
            'starts_at' => $s, 'ends_at' => $s->copy()->addHour(), 'checkin_opens_at' => $s->copy()->subMinutes(30),
            'late_after' => $s->copy()->addMinutes(10), 'checkin_closes_at' => $s->copy()->addHour(), 'closed_at' => now()]);
    }

    private function titles(string $url): array
    {
        return collect($this->actingAs($this->admin)->get($url)->viewData('page')['props']['events']['data'])->pluck('title')->sort()->values()->all();
    }

    public function test_events_search_division_type_and_dates(): void
    {
        $this->event('Rapat Pleno', '2026-03-01 09:00', ['location' => 'Aula', 'type' => 'rapat']);
        $this->event('Bakti Sosial', '2026-04-10 07:00', ['location' => 'Desa Sukamaju', 'type' => 'sosial', 'division_id' => $this->humas->id]);
        $this->event('Rapat Humas', '2026-05-05 13:00', ['type' => 'rapat', 'division_id' => $this->humas->id]);

        $this->assertSame(['Rapat Humas', 'Rapat Pleno'], $this->titles('/kegiatan?tab=selesai&q=rapat'));
        $this->assertSame(['Bakti Sosial'], $this->titles('/kegiatan?tab=selesai&q=sukamaju')); // lokasi
        $this->assertSame(['Rapat Pleno'], $this->titles('/kegiatan?tab=selesai&division=umum'));
        $this->assertSame(['Bakti Sosial', 'Rapat Humas'], $this->titles("/kegiatan?tab=selesai&division={$this->humas->id}"));
        $this->assertSame(['Bakti Sosial'], $this->titles('/kegiatan?tab=selesai&type=sosial'));
        $this->assertSame(['Bakti Sosial', 'Rapat Pleno'], $this->titles('/kegiatan?tab=selesai&from=2026-03-01&to=2026-04-10'));
        $this->actingAs($this->admin)->get('/kegiatan?tab=selesai')->assertInertia(fn (Assert $p) => $p->where('types', ['rapat', 'sosial'])->has('divisions', 1));
    }

    public function test_excuses_search_by_member_or_event_and_type(): void
    {
        $e = $this->event('Rapat Pleno', '2026-03-01 09:00');
        foreach ([['A1', 'Ani', 'izin'], ['B2', 'Budi', 'sakit']] as [$no, $name, $type]) {
            $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $no, 'name' => $name]);
            ExcuseRequest::create(['event_id' => $e->id, 'member_id' => $m->id, 'type' => $type, 'reason' => 'x', 'status' => 'pending']);
        }
        $names = fn (string $url) => collect($this->actingAs($this->admin)->get($url)->viewData('page')['props']['excuses']['data'])->pluck('member.name')->sort()->values()->all();
        $this->assertSame(['Ani'], $names('/izin?q=ani'));
        $this->assertSame(['Budi'], $names('/izin?q=B2'));
        $this->assertSame(['Ani', 'Budi'], $names('/izin?q=pleno'));
        $this->assertSame(['Budi'], $names('/izin?type=sakit'));
    }

    public function test_log_category_filter(): void
    {
        $this->actingAs($this->admin);
        AuditLogger::log('card.blocked', 'card', 1);
        AuditLogger::log('member.created', 'member', 1);
        AuditLogger::log('membership.x', 'member', 1); // awalan mirip tidak ikut
        $actions = fn (string $url) => collect($this->get($url)->viewData('page')['props']['logs']['data'])->pluck('action')->all();
        $this->assertSame(['member.created'], $actions('/log?action=member'));
        $this->assertSame(['card.blocked'], $actions('/log?action=card'));
    }
}
