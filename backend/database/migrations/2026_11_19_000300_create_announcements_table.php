<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pengumuman pengurus untuk anggota (tampil di app anggota). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('division_id')->nullable()->constrained()->cascadeOnDelete(); // null = semua anggota
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('title', 150);
            $t->text('body');
            $t->boolean('pinned')->default(false);
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
