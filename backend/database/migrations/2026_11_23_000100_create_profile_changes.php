<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pengajuan perubahan data diri anggota (nama, no. HP, foto) — berlaku setelah disetujui Super Admin. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 150)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('photo_path')->nullable();
            $t->string('reason', 300)->nullable();
            $t->string('status', 20)->default('pending'); // pending | approved | rejected
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('review_note', 500)->nullable();
            $t->timestamps();
            $t->index(['member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_changes');
    }
};
