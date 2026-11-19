<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Privasi (PRD §13): persetujuan tercatat, hapus data pribadi, retensi per organisasi. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $t) {
            $t->foreignId('consented_by')->nullable()->after('consented_at')->constrained('users')->nullOnDelete();
            $t->timestamp('anonymized_at')->nullable()->after('consented_by');
        });
        Schema::table('excuse_requests', function (Blueprint $t) {
            $t->timestamp('purged_at')->nullable(); // alasan & lampiran dihapus (retensi / hapus data)
        });
        Schema::table('organizations', function (Blueprint $t) {
            // Hari setelah periode berakhir sebelum alasan & lampiran izin dihapus.
            $t->unsignedSmallInteger('retention_excuse_days')->default(180);
            // Bulan setelah periode terakhir anggota berakhir sebelum data pribadinya dihapus otomatis (null = mati).
            $t->unsignedSmallInteger('retention_member_months')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['retention_excuse_days', 'retention_member_months']));
        Schema::table('excuse_requests', fn (Blueprint $t) => $t->dropColumn('purged_at'));
        Schema::table('members', function (Blueprint $t) {
            $t->dropConstrainedForeignId('consented_by');
            $t->dropColumn('anonymized_at');
        });
    }
};
