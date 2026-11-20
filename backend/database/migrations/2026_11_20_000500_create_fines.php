<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Denda absen pakai QR padahal punya kartu aktif (kartu tertinggal), sesuai kebijakan organisasi.
     * status: unpaid · paid · waived (dibebaskan) · free (jatah gratis periode, nominal 0).
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->boolean('fine_enabled')->default(false);
            $t->unsignedInteger('fine_amount')->default(5000);
            $t->unsignedSmallInteger('fine_free_per_term')->default(0);
        });
        Schema::create('fines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->foreignId('attendance_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('term_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('amount');
            $t->string('status', 10)->default('unpaid');
            $t->string('note', 300)->nullable();
            $t->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('handled_at')->nullable();
            $t->timestamps();
            $t->index(['member_id', 'term_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fines');
        Schema::table('organizations', function (Blueprint $t) {
            $t->dropColumn(['fine_enabled', 'fine_amount', 'fine_free_per_term']);
        });
    }
};
