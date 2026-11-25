<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Notulen (satu per kegiatan, draf lalu terbit) dan dokumen kegiatan. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_minutes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            $t->text('content');
            $t->unsignedInteger('version')->default(1); // mencegah dua pengurus saling menimpa
            $t->timestamp('published_at')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('event_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->string('title', 150);
            $t->string('path');
            $t->string('extension', 10);
            $t->unsignedInteger('size');
            $t->boolean('for_members')->default(false); // false = hanya pengurus
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_documents');
        Schema::dropIfExists('event_minutes');
    }
};
