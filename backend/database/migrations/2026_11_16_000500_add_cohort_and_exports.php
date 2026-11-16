<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $t) {
            $t->string('cohort', 20)->nullable()->after('phone'); // angkatan / tahun masuk (opsional)
        });

        Schema::create('exports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('report');          // event|term|division
            $t->jsonb('params');
            $t->string('format');          // xlsx|pdf
            $t->string('status')->default('pending'); // pending|ready|failed
            $t->string('path')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
        Schema::table('members', fn (Blueprint $t) => $t->dropColumn('cohort'));
    }
};
