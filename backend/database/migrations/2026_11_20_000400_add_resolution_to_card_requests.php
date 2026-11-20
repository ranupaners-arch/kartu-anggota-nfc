<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tindak lanjut permintaan kartu: self (anggota daftarkan sendiri) | admin (pengurus menerbitkan). */
    public function up(): void
    {
        Schema::table('card_requests', function (Blueprint $t) {
            $t->string('resolution', 10)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('card_requests', function (Blueprint $t) {
            $t->dropColumn('resolution');
        });
    }
};
