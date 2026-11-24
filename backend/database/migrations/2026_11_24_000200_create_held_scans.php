<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Scan offline dari perangkat yang dicabut: ditahan sampai ditinjau pengurus (PRD US-10). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $t) {
            // Hash token terakhir: hanya untuk mengunggah scan tertahan setelah dicabut, bukan untuk masuk.
            $t->string('held_token_hash', 64)->nullable();
        });

        Schema::create('held_scans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->constrained()->cascadeOnDelete();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('session_id')->nullable();
            $t->uuid('client_uuid');
            $t->string('payload', 300)->nullable(); // dikosongkan setelah ditinjau
            $t->timestamp('scanned_at');
            $t->string('status', 20)->default('pending'); // pending | accepted | rejected
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('review_reason', 500)->nullable();
            $t->timestamps();
            $t->unique(['device_id', 'client_uuid']);
            $t->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_scans');
        Schema::table('devices', fn (Blueprint $t) => $t->dropColumn('held_token_hash'));
    }
};
