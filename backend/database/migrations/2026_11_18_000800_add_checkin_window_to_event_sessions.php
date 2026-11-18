<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jendela check-in per sesi. Sesi utama (id terkecil) = jendela kegiatan; sesi lain bergeser
 * mengikuti jam mulainya dengan selisih yang sama seperti kegiatan (lihat Event::windowFor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_sessions', function (Blueprint $t) {
            $t->timestamp('checkin_opens_at')->nullable()->after('ends_at');
            $t->timestamp('late_after')->nullable()->after('checkin_opens_at');
            $t->timestamp('checkin_closes_at')->nullable()->after('late_after');
            $t->index(['event_id', 'checkin_opens_at', 'checkin_closes_at']);
        });

        // Isi untuk data lama (tanpa model: global scope organisasi tidak berlaku di migrasi).
        foreach (DB::table('events')->get() as $e) {
            $sessions = DB::table('event_sessions')->where('event_id', $e->id)->orderBy('id')->get();
            if ($sessions->isEmpty()) {
                DB::table('event_sessions')->insert(['event_id' => $e->id, 'title' => $e->title, 'starts_at' => $e->starts_at,
                    'ends_at' => $e->ends_at, 'created_at' => now(), 'updated_at' => now()]);
                $sessions = DB::table('event_sessions')->where('event_id', $e->id)->orderBy('id')->get();
            }
            $starts = Carbon::parse($e->starts_at);
            foreach ($sessions as $i => $s) {
                $base = $i === 0 || ! $s->starts_at ? $starts : Carbon::parse($s->starts_at);
                $shift = fn ($t) => $base->copy()->addSeconds((int) round($starts->diffInSeconds(Carbon::parse($t), false)));
                DB::table('event_sessions')->where('id', $s->id)->update([
                    'checkin_opens_at' => $shift($e->checkin_opens_at), 'late_after' => $shift($e->late_after), 'checkin_closes_at' => $shift($e->checkin_closes_at),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('event_sessions', function (Blueprint $t) {
            $t->dropIndex(['event_id', 'checkin_opens_at', 'checkin_closes_at']);
            $t->dropColumn(['checkin_opens_at', 'late_after', 'checkin_closes_at']);
        });
    }
};
