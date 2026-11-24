<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->ulid('public_id')->unique();
            $t->string('key_id', 8)->default('k1');
            $t->string('hw_uid_hash')->nullable();
            $t->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status')->default('unassigned'); // unassigned|active|blocked|lost
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['member_id', 'status']);
        });

        // Maks. satu kartu aktif per anggota (aturan PRD 7.2)
        DB::statement(
            "CREATE UNIQUE INDEX cards_one_active_per_member ON cards (member_id) WHERE status = 'active'"
        );

        Schema::create('member_qr', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('token')->unique();
            $t->timestamp('rotated_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_qr');
        Schema::dropIfExists('cards');
    }
};
