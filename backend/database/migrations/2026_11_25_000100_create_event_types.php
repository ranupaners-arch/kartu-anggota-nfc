<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Tipe kegiatan: pilihan per organisasi, dikelola Super Admin. events.type tetap menyimpan namanya. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name', 50);
            $t->timestamps();
        });
        DB::statement('create unique index event_types_org_name_unique on event_types (organization_id, lower(name))');

        foreach (DB::table('organizations')->pluck('id') as $org) {
            // Tipe yang sudah dipakai dipertahankan; yang hanya beda huruf besar/kecil disatukan.
            $names = [];
            foreach (DB::table('events')->where('organization_id', $org)->whereNotNull('type')->select('type')->groupBy('type')->orderByRaw('count(*) desc')->orderBy('type')->pluck('type') as $type) {
                $name = mb_substr(trim($type), 0, 50);
                if ($name === '') {
                    continue;
                }
                $name = $names[mb_strtolower($name)] ??= $name;
                DB::table('events')->where('organization_id', $org)->where('type', $type)->update(['type' => $name]);
            }
            foreach (['Rapat', 'Seminar', 'Pelatihan', 'Sosial'] as $default) {
                $names[mb_strtolower($default)] ??= $default;
            }
            DB::table('event_types')->insert(array_map(fn ($n) => ['organization_id' => $org, 'name' => $n, 'created_at' => now(), 'updated_at' => now()], array_values($names)));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_types');
    }
};
