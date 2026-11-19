<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indeks untuk jalur scan & rekap (PostgreSQL tidak membuat indeks otomatis untuk foreign key):
 * deteksi anomali (kartu + waktu), riwayat per anggota, target kegiatan, keanggotaan per periode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $t) {
            $t->index(['card_id', 'scanned_at']);
            $t->index(['member_id', 'event_id']);
        });
        Schema::table('event_targets', fn (Blueprint $t) => $t->index('event_id'));
        Schema::table('memberships', fn (Blueprint $t) => $t->index(['term_id', 'status', 'division_id']));
    }

    public function down(): void
    {
        Schema::table('memberships', fn (Blueprint $t) => $t->dropIndex(['term_id', 'status', 'division_id']));
        Schema::table('event_targets', fn (Blueprint $t) => $t->dropIndex(['event_id']));
        Schema::table('attendances', function (Blueprint $t) {
            $t->dropIndex(['member_id', 'event_id']);
            $t->dropIndex(['card_id', 'scanned_at']);
        });
    }
};
