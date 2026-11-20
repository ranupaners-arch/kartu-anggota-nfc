<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Pendaftaran anggota mandiri: token di tautan publik; null = pendaftaran ditutup. */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->string('registration_token', 48)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->dropColumn('registration_token');
        });
    }
};
