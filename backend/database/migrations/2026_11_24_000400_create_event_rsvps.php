<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** RSVP kegiatan (opsional per kegiatan) dengan kuota & daftar tunggu. Tidak memengaruhi absensi. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $t) {
            $t->boolean('rsvp_enabled')->default(false);
            $t->timestamp('rsvp_deadline')->nullable(); // null = sampai kegiatan mulai
            $t->unsignedInteger('quota')->nullable(); // null = tanpa batas
        });

        Schema::create('event_rsvps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->string('status', 20); // going | waitlist | declined
            $t->timestamp('responded_at');
            $t->timestamps();
            $t->unique(['event_id', 'member_id']);
            $t->index(['event_id', 'status', 'responded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_rsvps');
        Schema::table('events', fn (Blueprint $t) => $t->dropColumn(['rsvp_enabled', 'rsvp_deadline', 'quota']));
    }
};
