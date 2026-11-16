<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->timestamps();
        });

        Schema::create('terms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->boolean('is_current')->default(false);
            $t->timestamps();
        });

        Schema::create('divisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->timestamps();
            $t->unique(['organization_id', 'name']);
        });

        Schema::create('members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('member_no'); // nomor anggota (NIM, NIK, no. induk, dll.)
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('photo_path')->nullable();
            $t->timestamp('consented_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'member_no']);
        });

        Schema::create('memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->foreignId('term_id')->constrained()->cascadeOnDelete();
            $t->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $t->string('role')->nullable();
            $t->string('status')->default('active'); // applicant|active|inactive|alumni
            $t->timestamps();
            $t->unique(['member_id', 'term_id']);
        });
    }

    public function down(): void
    {
        foreach (['memberships', 'members', 'divisions', 'terms', 'organizations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
