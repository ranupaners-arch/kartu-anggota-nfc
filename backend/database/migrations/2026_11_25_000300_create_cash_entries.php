<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Kas organisasi: uang masuk & keluar. Catatan tidak diubah/dihapus, hanya dibatalkan dengan alasan. */
return new class extends Migration
{
    private const DEFAULTS = ['in' => ['Iuran', 'Saldo awal', 'Donasi', 'Sponsor', 'Lainnya'], 'out' => ['Konsumsi', 'Perlengkapan', 'Transportasi', 'Sewa', 'Lainnya']];

    public function up(): void
    {
        Schema::create('cash_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 3); // in | out
            $t->string('name', 50);
            $t->boolean('is_dues')->default(false); // dipakai otomatis oleh iuran; tidak bisa dihapus
            $t->timestamps();
        });
        DB::statement('create unique index cash_categories_unique on cash_categories (organization_id, kind, lower(name))');
        DB::statement('create unique index cash_categories_dues on cash_categories (organization_id) where is_dues');

        Schema::create('cash_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('term_id')->constrained()->restrictOnDelete();
            $t->foreignId('category_id')->constrained('cash_categories')->restrictOnDelete();
            $t->string('kind', 3);
            $t->unsignedBigInteger('amount');
            $t->date('occurred_on');
            $t->string('description', 200);
            $t->string('receipt_path')->nullable();
            $t->foreignId('due_item_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('void_reason', 300)->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'occurred_on']);
        });
        // Satu tagihan lunas = tepat satu catatan kas yang berlaku.
        DB::statement('create unique index cash_entries_due_active on cash_entries (due_item_id) where voided_at is null and due_item_id is not null');

        foreach (DB::table('organizations')->pluck('id') as $org) {
            foreach (self::DEFAULTS as $kind => $names) {
                foreach ($names as $n) {
                    DB::table('cash_categories')->insert(['organization_id' => $org, 'kind' => $kind, 'name' => $n, 'is_dues' => $kind === 'in' && $n === 'Iuran', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            // Iuran yang sudah lunas sebelum fitur ini ikut masuk kas, dengan tanggal pelunasannya.
            $category = DB::table('cash_categories')->where('organization_id', $org)->where('is_dues', true)->value('id');
            $paid = DB::table('due_items')->join('dues', 'dues.id', '=', 'due_items.due_id')->where('dues.organization_id', $org)->where('due_items.status', 'paid')
                ->orderBy('due_items.id')->get(['due_items.id', 'dues.term_id', 'dues.amount', 'dues.title']);
            foreach ($paid as $i) {
                $c = DB::table('due_item_changes')->where('due_item_id', $i->id)->where('to_status', 'paid')->orderByDesc('id')->first();
                DB::table('cash_entries')->insert(['organization_id' => $org, 'term_id' => $i->term_id, 'category_id' => $category, 'kind' => 'in', 'amount' => $i->amount,
                    'occurred_on' => substr((string) ($c->created_at ?? now()), 0, 10), 'description' => $i->title, 'due_item_id' => $i->id, 'created_by' => $c->changed_by ?? null, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_entries');
        Schema::dropIfExists('cash_categories');
    }
};
