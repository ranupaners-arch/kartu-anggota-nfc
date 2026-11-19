<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Pengurus menerima ringkasan mingguan lewat email (bisa dimatikan di halaman Akun). */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('weekly_summary')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('weekly_summary');
        });
    }
};
