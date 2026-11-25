<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** HP yang dibangunkan lewat Firebase. Terikat ke token masuk: keluar, perangkat dicabut, atau ganti peran ikut menghapusnya. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('access_token_id')->unique()->constrained('personal_access_tokens')->cascadeOnDelete();
            $t->text('token');
            $t->string('token_hash', 64)->unique();
            $t->timestamps();
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
