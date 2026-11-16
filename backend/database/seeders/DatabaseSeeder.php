<?php

namespace Database\Seeders;

use App\Models\Card;
use App\Models\Division;
use App\Models\Event;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Support\CardPayload;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Data contoh untuk development lokal (organisasi contoh: HMPS).
 * Sistem ini generik untuk organisasi apa pun; HMPS hanya tenant pertama.
 * Jangan dijalankan di production.
 * Password semua akun: env SEED_PASSWORD (default "password").
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('Seeder contoh tidak boleh dijalankan di production.');

            return;
        }

        $password = env('SEED_PASSWORD', 'password');

        $org = Organization::create(['name' => 'HMPS', 'slug' => 'hmps']);
        $term = Term::create([
            'organization_id' => $org->id, 'name' => '2026/2027',
            'starts_on' => '2026-08-01', 'ends_on' => '2027-07-31', 'is_current' => true,
        ]);

        $divisions = collect(['Inti', 'Humas', 'Pendidikan', 'Minat Bakat'])
            ->mapWithKeys(fn ($name) => [$name => Division::create(['organization_id' => $org->id, 'name' => $name])]);

        $names = ['Ahmad Fauzi', 'Siti Rahma', 'Budi Santoso', 'Dewi Lestari', 'Rizky Pratama',
            'Nur Aisyah', 'Fajar Nugroho', 'Intan Permata', 'Galih Saputra', 'Putri Ayu',
            'Hendra Wijaya', 'Laras Sekar', 'Yoga Aditya', 'Maya Sari', 'Dimas Ardi',
            'Citra Kirana', 'Bayu Seto', 'Anisa Fitri', 'Rafi Hidayat', 'Wulan Dari'];

        $members = collect($names)->values()->map(function ($name, $i) use ($org, $term, $divisions) {
            $member = Member::create([
                'organization_id' => $org->id, 'member_no' => sprintf('2401%03d', $i + 1), 'name' => $name,
                'email' => Str::slug($name, '.').'@mhs.test', 'consented_at' => now(),
            ]);
            Membership::create([
                'member_id' => $member->id, 'term_id' => $term->id,
                'division_id' => $divisions->values()[$i % 4]->id,
                'status' => $i >= 18 ? 'alumni' : 'active', // 2 alumni: bukan target default
            ]);

            return $member;
        });

        $users = [
            ['Super Admin', 'superadmin@hmps.test', User::SUPER_ADMIN, $members[0]->id, null],
            ['Admin Humas', 'humas@hmps.test', User::DIVISION_ADMIN, $members[1]->id, $divisions['Humas']->id],
            ['Petugas Scan', 'petugas@hmps.test', User::SCANNER, null, null],
            ['Anggota Contoh', 'anggota@hmps.test', User::MEMBER, $members[4]->id, null],
        ];
        foreach ($users as [$name, $email, $role, $memberId, $divisionId]) {
            User::create([
                'organization_id' => $org->id, 'member_id' => $memberId, 'division_id' => $divisionId,
                'name' => $name, 'email' => $email, 'password' => $password, 'role' => $role,
                'email_verified_at' => now(),
            ]);
        }

        // Kartu aktif untuk 10 anggota pertama
        $members->take(10)->each(fn (Member $m) => Card::create([
            'organization_id' => $org->id, 'public_id' => (string) Str::ulid(), 'key_id' => config('attendance.card_active_key'),
            'member_id' => $m->id, 'status' => Card::ACTIVE, 'issued_at' => now(),
        ]));

        $base = ['organization_id' => $org->id, 'term_id' => $term->id, 'type' => 'rapat', 'is_mandatory' => true];

        $ongoing = Event::create($base + [
            'title' => 'Rapat Pleno (berlangsung)', 'location' => 'Ruang Sidang',
            'starts_at' => now()->subMinutes(15), 'ends_at' => now()->addHours(2),
            'checkin_opens_at' => now()->subMinutes(30), 'late_after' => now()->addMinutes(15),
            'checkin_closes_at' => now()->addHours(2),
        ]);
        $upcoming = Event::create($base + [
            'title' => 'Seminar Humas (akan datang)', 'type' => 'seminar', 'division_id' => $divisions['Humas']->id,
            'location' => 'Aula', 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHours(3),
            'checkin_opens_at' => now()->addDays(3)->subMinutes(30), 'late_after' => now()->addDays(3)->addMinutes(10),
            'checkin_closes_at' => now()->addDays(3)->addHour(),
        ]);
        $upcoming->targets()->create(['division_id' => $divisions['Humas']->id]);

        foreach ([$ongoing, $upcoming] as $e) {
            $e->defaultSession();
        }

        $this->command->table(['Email', 'Peran', 'Password'], collect($users)->map(fn ($u) => [$u[1], $u[2], $password]));
        $this->command->info("Kegiatan berlangsung: ID {$ongoing->id}. Contoh payload kartu (anggota {$members[2]->member_no}):");
        $this->command->line(CardPayload::make($members[2]->activeCard));
    }
}
