<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Iuran anggota (nominal sama untuk semua) & akses keuangan: bendahara (mencatat), sekretaris (audit, baca saja). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('finance_role', 20)->nullable(); // treasurer | auditor
            $t->foreignId('finance_term_id')->nullable()->constrained('terms')->nullOnDelete(); // berlaku hanya selama periode ini berjalan
        });

        Schema::create('dues', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('term_id')->constrained()->restrictOnDelete();
            $t->string('title', 100);
            $t->unsignedInteger('amount');
            $t->date('due_on')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason', 300)->nullable();
            $t->timestamps();
        });
        // Judul unik per periode, kecuali tagihan yang dibatalkan (boleh dibuat ulang).
        DB::statement('create unique index dues_term_title_unique on dues (term_id, lower(title)) where cancelled_at is null');

        Schema::create('due_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('due_id')->constrained()->restrictOnDelete();
            $t->foreignId('member_id')->constrained()->restrictOnDelete();
            $t->string('status', 20)->default('unpaid'); // unpaid | paid | waived
            $t->timestamps();
            $t->unique(['due_id', 'member_id']);
            $t->index(['member_id', 'status']);
        });

        // Riwayat untuk audit: hanya ditambah, tidak pernah diubah.
        Schema::create('due_item_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('due_item_id')->constrained()->restrictOnDelete();
            $t->string('from_status', 20);
            $t->string('to_status', 20);
            $t->string('note', 300)->nullable();
            $t->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('due_item_changes');
        Schema::dropIfExists('due_items');
        Schema::dropIfExists('dues');
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('finance_term_id');
            $t->dropColumn('finance_role');
        });
    }
};
