<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sertifikat kegiatan: pengaturan per kegiatan & satu sertifikat per anggota yang memenuhi syarat. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('title', 60)->default('Sertifikat');
            $t->string('role', 60)->default('Peserta');
            $t->unsignedSmallInteger('min_sessions')->default(1); // minimal sesi berstatus hadir/telat
            $t->string('signer1_name', 100);
            $t->string('signer1_title', 100);
            $t->string('signer2_name', 100)->nullable();
            $t->string('signer2_title', 100)->nullable();
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('certificates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('seq'); // urut per kegiatan, tidak dipakai ulang
            $t->string('code', 20)->unique(); // acak: untuk verifikasi publik
            $t->string('member_name', 150); // nama saat terbit
            $t->timestamp('issued_at');
            $t->timestamp('revoked_at')->nullable();
            $t->string('revoke_reason', 300)->nullable();
            $t->boolean('auto_revoked')->default(false); // dicabut karena syarat tidak terpenuhi lagi: boleh pulih sendiri
            $t->timestamps();
            $t->unique(['event_id', 'member_id']);
            $t->unique(['event_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('certificate_batches');
    }
};
