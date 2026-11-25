<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Inventaris: barang organisasi dan peminjaman oleh anggota. Riwayat peminjaman tidak dihapus. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('code', 30)->nullable();
            $t->unsignedInteger('quantity');
            $t->string('location', 100)->nullable();
            $t->string('note', 300)->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });
        DB::statement('create unique index inventory_items_code on inventory_items (organization_id, lower(code)) where code is not null');

        Schema::create('inventory_loans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $t->foreignId('member_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->date('due_on');
            $t->string('note', 300)->nullable();
            $t->foreignId('lent_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('returned_at')->nullable();
            $t->string('condition', 10)->nullable(); // good | damaged | lost
            $t->string('return_note', 300)->nullable();
            $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['item_id', 'returned_at']);
            $t->index(['member_id', 'returned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_loans');
        Schema::dropIfExists('inventory_items');
    }
};
