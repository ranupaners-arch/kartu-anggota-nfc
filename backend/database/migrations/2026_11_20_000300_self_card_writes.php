<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Anggota boleh menulis kartu NFC sendiri lewat app: jatah 1 kali; berikutnya lewat permintaan
     * kartu pengganti yang disetujui Super Admin (menambah jatah 1).
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $t) {
            $t->unsignedTinyInteger('self_card_writes_left')->default(1);
        });
        Schema::create('card_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('reason', 20); // hilang | rusak
            $t->string('note', 500)->nullable();
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
        Schema::dropIfExists('card_requests');
        Schema::table('members', function (Blueprint $t) {
            $t->dropColumn('self_card_writes_left');
        });
    }
};
