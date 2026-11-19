<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Token rahasia tautan langganan jadwal (.ics) per akun; null = belum dibuat. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('calendar_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('calendar_token');
        });
    }
};
