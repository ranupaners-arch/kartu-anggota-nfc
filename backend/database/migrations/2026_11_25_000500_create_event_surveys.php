<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Survei kepuasan anonim. Siapa yang sudah mengisi dan apa isinya disimpan terpisah: tanpa kolom penghubung,
 * tanpa waktu, dan id jawaban acak, supaya urutannya tidak bisa dicocokkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_surveys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            $t->json('questions'); // pertanyaan tambahan, dinilai 1–5
            $t->timestamp('closes_at');
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('event_survey_respondents', function (Blueprint $t) {
            $t->foreignId('survey_id')->constrained('event_surveys')->cascadeOnDelete();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->primary(['survey_id', 'member_id']);
        });

        Schema::create('event_survey_responses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('survey_id')->index()->constrained('event_surveys')->cascadeOnDelete();
            $t->unsignedTinyInteger('rating');
            $t->json('answers');
            $t->string('comment', 1000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_survey_responses');
        Schema::dropIfExists('event_survey_respondents');
        Schema::dropIfExists('event_surveys');
    }
};
