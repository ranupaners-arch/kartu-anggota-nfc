<?php
// Dijalankan di container app (php artisan tinker --execute) oleh workflow deploy.yml.
// Membuat 4 petugas scan, 400 anggota berkartu, dan satu kegiatan yang sedang buka.
// Keluaran baris terakhir: JSON {event_id, tokens[], payloads[]}.

use App\Models\Card;
use App\Models\Event;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Term;
use App\Models\User;
use App\Support\CardPayload;
use Illuminate\Support\Str;

$admin = User::where('email', 'admin@ci.test')->firstOrFail();
$org = $admin->organization_id;
$term = Term::withoutGlobalScopes()->where('organization_id', $org)->where('is_current', true)->firstOrFail();

$tokens = [];
for ($i = 1; $i <= 4; $i++) {
    $u = User::create(['organization_id' => $org, 'name' => "Petugas Beban $i", 'email' => "beban$i@ci.test", 'password' => Str::random(32), 'role' => 'scanner']);
    $tokens[] = $u->createToken("beban-$i")->plainTextToken;
}

$now = now();
$payloads = [];
for ($i = 1; $i <= 400; $i++) {
    $m = Member::create(['organization_id' => $org, 'member_no' => sprintf('BEBAN%04d', $i), 'name' => "Peserta Beban $i", 'consented_at' => $now]);
    Membership::create(['member_id' => $m->id, 'term_id' => $term->id, 'status' => 'active']);
    $c = Card::create(['organization_id' => $org, 'public_id' => (string) Str::ulid(), 'key_id' => 'k1', 'status' => 'active', 'member_id' => $m->id, 'issued_at' => $now]);
    $payloads[] = CardPayload::make($c);
}

$event = Event::create(['organization_id' => $org, 'term_id' => $term->id, 'title' => 'Uji Beban', 'created_by' => $admin->id,
    'starts_at' => $now, 'ends_at' => $now->copy()->addHours(2), 'checkin_opens_at' => $now->copy()->subHour(),
    'late_after' => $now->copy()->addMinutes(30), 'checkin_closes_at' => $now->copy()->addHour(), 'is_mandatory' => true]);

echo json_encode(['event_id' => $event->id, 'tokens' => $tokens, 'payloads' => $payloads]), "\n";
