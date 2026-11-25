<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Peserta tamu (bukan anggota). Terpisah dari absensi anggota: tidak masuk rekap, poin, denda, atau sertifikat. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_guests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('institution', 100)->nullable();
            $t->string('token', 40)->unique();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('purged_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('event_guest_checkins', function (Blueprint $t) {
            $t->id();
            $t->foreignId('guest_id')->constrained('event_guests')->restrictOnDelete();
            $t->foreignId('session_id')->constrained('event_sessions')->restrictOnDelete();
            $t->string('status', 10); // hadir | telat
            $t->timestamp('scanned_at');
            $t->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $t->unique(['guest_id', 'session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_guest_checkins');
        Schema::dropIfExists('event_guests');
    }
};
