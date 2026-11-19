<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Card;
use App\Models\Event;
use App\Models\ExcuseRequest;
use App\Models\Export;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Term $old;
    private Term $current;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['attendance.card_keys.k1' => 'test-secret', 'attendance.payload_prefix' => 'card']);
        $this->travelTo(now()->setDate(2026, 11, 19)->setTime(10, 0));
        $this->org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $this->old = Term::create(['organization_id' => $this->org->id, 'name' => '2024', 'starts_on' => '2024-01-01', 'ends_on' => '2024-12-31']);
        $this->current = Term::create(['organization_id' => $this->org->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
        $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'super_admin']);
    }

    private function member(string $no, Term $term, string $status = 'active'): Member
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => $no, 'name' => "Nama $no", 'email' => "$no@x.test", 'phone' => '0812', 'consented_at' => now()]);
        Membership::create(['member_id' => $m->id, 'term_id' => $term->id, 'status' => $status]);

        return $m;
    }

    /** Kegiatan lama + absensi + izin berlampiran untuk anggota. */
    private function history(Member $m, Term $term): ExcuseRequest
    {
        $start = now()->setDate((int) substr($term->starts_on, 0, 4), 6, 1);
        $e = Event::create(['organization_id' => $this->org->id, 'term_id' => $term->id, 'title' => 'Rapat '.$term->name,
            'starts_at' => $start, 'ends_at' => $start->copy()->addHour(), 'checkin_opens_at' => $start->copy()->subMinutes(30),
            'late_after' => $start->copy()->addMinutes(10), 'checkin_closes_at' => $start->copy()->addHour()]);
        Attendance::create(['event_id' => $e->id, 'session_id' => $e->defaultSession()->id, 'member_id' => $m->id, 'status' => 'izin']);
        $path = UploadedFile::fake()->image('surat.jpg')->store('excuses/1', 'local');

        return ExcuseRequest::create(['event_id' => $e->id, 'member_id' => $m->id, 'type' => 'sakit', 'reason' => 'Demam berdarah', 'status' => 'approved',
            'attachment_path' => $path, 'review_note' => 'Sudah dicek']);
    }

    public function test_anonymize_removes_personal_data_but_keeps_attendance(): void
    {
        $m = $this->member('NIM1', $this->old, 'alumni');
        $m->update(['photo_path' => UploadedFile::fake()->image('wajah.jpg')->store('members/1', 'local')]);
        $photo = $m->photo_path;
        $x = $this->history($m, $this->old);
        $attachment = $x->attachment_path;
        $card = Card::create(['public_id' => '01TESTCARD', 'key_id' => 'k1', 'status' => 'active', 'member_id' => $m->id, 'issued_at' => now(), 'organization_id' => $this->org->id]);
        $account = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'member', 'member_id' => $m->id, 'email' => 'nim1@x.test']);
        $account->createToken('device:1');

        $this->actingAs($this->admin)->post("/anggota/{$m->id}/hapus-data", ['confirm' => 'SALAH', 'reason' => 'Permintaan anggota'])->assertSessionHasErrors('confirm');
        $this->actingAs($this->admin)->post("/anggota/{$m->id}/hapus-data", ['confirm' => 'NIM1', 'reason' => 'Permintaan anggota via email'])->assertRedirect();

        $m->refresh();
        $this->assertSame(['Anggota terhapus', "HAPUS-{$m->id}", null, null, null], [$m->name, $m->member_no, $m->email, $m->phone, $m->photo_path]);
        $this->assertNotNull($m->anonymized_at);
        Storage::disk('local')->assertMissing($photo);
        Storage::disk('local')->assertMissing($attachment);
        $this->assertSame(['[dihapus]', null, null], [$x->fresh()->reason, $x->fresh()->attachment_path, $x->fresh()->review_note]);
        $this->assertSame('blocked', $card->fresh()->status);
        $account->refresh();
        $this->assertNotNull($account->disabled_at);
        $this->assertNull($account->member_id);
        $this->assertStringNotContainsString('nim1', $account->email);
        $this->assertSame(0, $account->tokens()->count());
        $this->assertSame(1, Attendance::where('member_id', $m->id)->count()); // rekap tetap utuh
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.anonymized', 'entity_id' => $m->id]);
        $this->assertStringNotContainsString('NIM1', json_encode(\DB::table('audit_logs')->where('action', 'member.anonymized')->first()));

        // Setelah dihapus: tidak bisa diubah, diberi kartu, atau dihapus lagi.
        $this->actingAs($this->admin)->put("/anggota/{$m->id}", ['name' => 'Kembali'])->assertStatus(422);
        $this->actingAs($this->admin)->post("/anggota/{$m->id}/hapus-data", ['confirm' => "HAPUS-{$m->id}", 'reason' => 'lagi lagi'])->assertStatus(422);
        // Lewat dashboard (Inertia), penolakan tampil sebagai pesan di halaman.
        $this->actingAs($this->admin)->from("/anggota/{$m->id}")->withHeader('X-Inertia', 'true')
            ->put("/anggota/{$m->id}", ['name' => 'Kembali'])->assertSessionHasErrors(['error' => 'Data pribadi anggota ini sudah dihapus dan tidak bisa diubah.']);
        $init = $this->actingAs($this->admin, 'sanctum')->postJson('/api/cards/init')->json();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/cards/{$init['id']}/assign", ['member_id' => $m->id])->assertStatus(422);
    }

    public function test_active_member_cannot_be_anonymized(): void
    {
        $m = $this->member('AKTIF', $this->current);
        $this->actingAs($this->admin)->post("/anggota/{$m->id}/hapus-data", ['confirm' => 'AKTIF', 'reason' => 'coba hapus'])->assertStatus(422);
        $this->assertNull($m->fresh()->anonymized_at);
        $this->actingAs($this->admin)->get("/anggota/{$m->id}")->assertInertia(fn (Assert $p) => $p->where('privacy.blocker', fn ($b) => str_contains($b, 'masih aktif')));
    }

    public function test_retention_purges_old_excuses_old_members_and_old_exports(): void
    {
        $oldMember = $this->member('LAMA', $this->old, 'alumni');
        $oldExcuse = $this->history($oldMember, $this->old);
        $now = $this->member('KINI', $this->current);
        $newExcuse = $this->history($now, $this->current);
        Storage::disk('local')->put('exports/1/lama.xlsx', 'x');
        $oldExport = Export::create(['organization_id' => $this->org->id, 'report' => 'term', 'params' => [], 'format' => 'xlsx', 'status' => 'ready', 'path' => 'exports/1/lama.xlsx']);
        $oldExport->forceFill(['created_at' => now()->subDays(10)])->save();

        // Bawaan: izin dihapus 180 hari setelah periode berakhir; anggota tidak dihapus otomatis.
        $this->artisan('app:retention --dry-run')->expectsOutputToContain('1 izin, 0 anggota, 1 ekspor (simulasi)')->assertSuccessful();
        $this->assertNotNull($oldExcuse->fresh()->attachment_path); // simulasi tidak menghapus

        $this->artisan('app:retention')->assertSuccessful();
        $this->assertSame('[dihapus]', $oldExcuse->fresh()->reason);
        $this->assertNotNull($oldExcuse->fresh()->purged_at);
        $this->assertSame('Demam berdarah', $newExcuse->fresh()->reason); // periode berjalan aman
        $this->assertSame(['expired', null], [$oldExport->fresh()->status, $oldExport->fresh()->path]);
        Storage::disk('local')->assertMissing('exports/1/lama.xlsx');
        $this->assertNull($oldMember->fresh()->anonymized_at);

        // Retensi anggota diaktifkan: 12 bulan setelah periode terakhir berakhir.
        $this->actingAs($this->admin)->put('/pengaturan/privasi', ['retention_excuse_days' => 90, 'retention_member_months' => 12])->assertRedirect();
        $this->actingAs($this->admin)->get('/pengaturan?tab=privasi')->assertInertia(fn (Assert $p) => $p->where('privacy.memberMonths', 12)->where('privacy.pending.members', 1));
        auth()->logout();
        $this->artisan('app:retention')->assertSuccessful();
        $this->assertNotNull($oldMember->fresh()->anonymized_at);
        $this->assertNull($now->fresh()->anonymized_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.anonymized', 'entity_id' => $oldMember->id, 'user_id' => null]);
    }

    public function test_consent_is_recorded_with_who_and_when(): void
    {
        $m = Member::create(['organization_id' => $this->org->id, 'member_no' => 'LAMA2', 'name' => 'Belum Setuju']);
        Membership::create(['member_id' => $m->id, 'term_id' => $this->current->id, 'status' => 'active']);
        $this->actingAs($this->admin)->get("/anggota/{$m->id}")->assertInertia(fn (Assert $p) => $p->where('privacy.consentedAt', null));
        $this->actingAs($this->admin)->post("/anggota/{$m->id}/persetujuan")->assertRedirect();
        $this->actingAs($this->admin)->get("/anggota/{$m->id}")->assertInertia(fn (Assert $p) => $p->where('privacy.consentedBy', $this->admin->name)->whereNot('privacy.consentedAt', null));

        $this->actingAs($this->admin)->put('/pengaturan/privasi', ['retention_excuse_days' => 5])->assertSessionHasErrors('retention_excuse_days');
    }
}
