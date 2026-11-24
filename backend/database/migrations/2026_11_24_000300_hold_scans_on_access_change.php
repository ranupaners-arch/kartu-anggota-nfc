<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Scan tertahan juga berlaku saat peran diubah / akun dinonaktifkan, bukan hanya perangkat dicabut. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', fn (Blueprint $t) => $t->timestamp('held_at')->nullable());
        DB::table('devices')->whereNotNull('held_token_hash')->update(['held_at' => DB::raw('revoked_at')]);

        // Petugas pemilik scan & waktu aksesnya berakhir dicatat per scan: perangkat bisa dipakai akun lain kemudian.
        Schema::table('held_scans', function (Blueprint $t) {
            $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $t->timestamp('access_ended_at')->nullable();
        });
        DB::statement('update held_scans set user_id = d.user_id, access_ended_at = d.revoked_at from devices d where d.id = held_scans.device_id');
    }

    public function down(): void
    {
        Schema::table('held_scans', function (Blueprint $t) {
            $t->dropConstrainedForeignId('user_id');
            $t->dropColumn('access_ended_at');
        });
        Schema::table('devices', fn (Blueprint $t) => $t->dropColumn('held_at'));
    }
};
