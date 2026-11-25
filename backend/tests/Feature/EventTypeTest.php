<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventType;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventTypeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
        $this->actingAs($this->admin);
    }

    private function body(array $extra = []): array
    {
        $at = now()->addDay();

        return $extra + ['title' => 'Kegiatan', 'starts_at' => $at->format('Y-m-d\TH:i'), 'ends_at' => $at->copy()->addHour()->format('Y-m-d\TH:i'), 'checkin_opens_at' => $at->copy()->subHour()->format('Y-m-d\TH:i'),
            'late_after' => $at->format('Y-m-d\TH:i'), 'checkin_closes_at' => $at->copy()->addHour()->format('Y-m-d\TH:i')];
    }

    private function id(string $name): int
    {
        return EventType::where('name', $name)->value('id');
    }

    public function test_new_organization_gets_defaults_and_form_offers_them(): void
    {
        $this->get('/kegiatan/create')->assertInertia(fn ($p) => $p->where('types', ['Pelatihan', 'Rapat', 'Seminar', 'Sosial']));
        $this->get('/pengaturan?tab=tipe')->assertOk()->assertInertia(fn ($p) => $p->has('eventTypes', 4));
    }

    public function test_add_rename_and_delete(): void
    {
        $this->post('/pengaturan/tipe', ['name' => ' Olahraga '])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('event_types', ['name' => 'Olahraga', 'organization_id' => $this->org->id]);
        $this->post('/pengaturan/tipe', ['name' => 'OLAHRAGA'])->assertSessionHasErrors('name');
        $this->post('/pengaturan/tipe', ['name' => ''])->assertSessionHasErrors('name');
        $this->post('/kegiatan', $this->body(['type' => 'olahraga']))->assertSessionHasNoErrors();
        $event = Event::sole();
        $this->assertSame('Olahraga', $event->type); // disamakan dengan pilihan

        $this->put('/pengaturan/tipe/'.$this->id('Olahraga'), ['name' => 'Olahraga & Seni'])->assertSessionHasNoErrors();
        $this->assertSame('Olahraga & Seni', $event->fresh()->type);
        $this->put('/pengaturan/tipe/'.$this->id('Rapat'), ['name' => 'rapat'])->assertSessionHasNoErrors(); // ubah huruf saja
        $this->put('/pengaturan/tipe/'.$this->id('rapat'), ['name' => 'Seminar'])->assertSessionHasErrors('name');

        $this->delete('/pengaturan/tipe/'.$this->id('Olahraga & Seni'))->assertStatus(422);
        $this->delete('/pengaturan/tipe/'.$this->id('Sosial'))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('event_types', ['name' => 'Sosial']);
    }

    public function test_last_type_cannot_be_deleted(): void
    {
        EventType::where('name', '!=', 'Rapat')->delete();
        $this->delete('/pengaturan/tipe/'.$this->id('Rapat'))->assertStatus(422);
    }

    public function test_unknown_type_is_rejected_but_existing_value_may_stay(): void
    {
        $this->post('/kegiatan', $this->body(['type' => 'Ngawur']))->assertSessionHasErrors('type');
        $this->post('/kegiatan', $this->body(['type' => 'Rapat']))->assertSessionHasNoErrors();
        $event = Event::sole();
        $event->update(['type' => 'Lama']);
        $this->put("/kegiatan/{$event->id}", $this->body(['type' => 'Lama', 'title' => 'Baru']))->assertSessionHasNoErrors();
        $this->put("/kegiatan/{$event->id}", $this->body(['type' => 'Lain']))->assertSessionHasErrors('type');
        $this->assertSame(['Lama', 'Baru'], [$event->fresh()->type, $event->fresh()->title]);
    }

    public function test_only_super_admin_manages_and_organizations_are_separate(): void
    {
        $other = Organization::create(['name' => 'Lain', 'slug' => 'lain']);
        $foreign = EventType::withoutGlobalScopes()->where('organization_id', $other->id)->where('name', 'Rapat')->firstOrFail();
        $this->put("/pengaturan/tipe/{$foreign->id}", ['name' => 'X'])->assertNotFound();
        $this->delete("/pengaturan/tipe/{$foreign->id}")->assertNotFound();
        $this->put('/pengaturan/tipe/'.$this->id('Rapat'), ['name' => 'Rapat Kerja'])->assertSessionHasNoErrors();
        $this->assertSame('Rapat', $foreign->fresh()->name);

        $da = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'division_admin']);
        $this->actingAs($da)->post('/pengaturan/tipe', ['name' => 'X'])->assertForbidden();
        $this->actingAs($da)->get('/kegiatan/create')->assertInertia(fn ($p) => $p->has('types', 4));
    }

    public function test_migration_keeps_used_types_and_merges_letter_case(): void
    {
        $t = Term::first();
        foreach (['rapat', 'rapat', 'RAPAT', 'Bakti Alam'] as $type) {
            Event::create(['organization_id' => $this->org->id, 'term_id' => $t->id, 'type' => $type] + $this->body());
        }
        $m = require database_path('migrations/2026_11_25_000100_create_event_types.php');
        $m->down();
        $m->up();
        $this->assertEqualsCanonicalizing(['rapat', 'Bakti Alam', 'Seminar', 'Pelatihan', 'Sosial'], EventType::pluck('name')->all());
        $this->assertSame(3, Event::where('type', 'rapat')->count());
    }
}
