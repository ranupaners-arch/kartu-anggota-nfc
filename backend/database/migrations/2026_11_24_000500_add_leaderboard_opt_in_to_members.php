<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Papan peringkat: nama hanya tampil bila anggota sendiri memilihnya (null = tidak tampil). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', fn (Blueprint $t) => $t->timestamp('leaderboard_opt_in_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('members', fn (Blueprint $t) => $t->dropColumn('leaderboard_opt_in_at'));
    }
};
