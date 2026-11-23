<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tugas pengurus per periode (Petugas Scan / Admin Divisi) yang ditempelkan ke akun anggota.
 * Akun tetap satu; peran akun mengikuti tugas aktif dan kembali ke "anggota" saat tugas berakhir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duties', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('term_id')->constrained()->cascadeOnDelete();
            $t->string('type', 20); // scanner | division_admin
            $t->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $t->date('ends_on')->nullable(); // null = sampai akhir periode
            $t->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('ended_at')->nullable();
            $t->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('end_reason', 300)->nullable();
            $t->timestamps();
            $t->index(['user_id', 'ended_at']);
            $t->index(['term_id', 'ended_at']);
        });

        // Akun petugas/admin divisi lama yang tertaut ke anggota → tugas di periode berjalan, agar tidak
        // turun jadi anggota saat pemeriksaan masa tugas pertama berjalan.
        $terms = DB::table('terms')->where('is_current', true)->pluck('id', 'organization_id');
        foreach (DB::table('users')->whereIn('role', ['scanner', 'division_admin'])->whereNotNull('member_id')->whereNull('disabled_at')->get() as $u) {
            if (! isset($terms[$u->organization_id])) {
                continue;
            }
            DB::table('duties')->insert([
                'organization_id' => $u->organization_id, 'user_id' => $u->id, 'term_id' => $terms[$u->organization_id],
                'type' => $u->role, 'division_id' => $u->role === 'division_admin' ? $u->division_id : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('duties');
    }
};
