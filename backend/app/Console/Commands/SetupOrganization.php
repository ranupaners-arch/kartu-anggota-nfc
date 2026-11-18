<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Term;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Setup awal di server produksi (tanpa seeder): organisasi + periode berjalan + Super Admin pertama.
 * Password sementara ditampilkan sekali; minta pemiliknya menggantinya di dashboard → Akun Saya.
 */
class SetupOrganization extends Command
{
    protected $signature = 'app:setup
        {--org-name= : Nama organisasi, mis. "HMPS Informatika"}
        {--org-slug= : Kode unik organisasi (huruf kecil & tanda hubung)}
        {--term-name= : Nama periode berjalan, mis. 2026/2027}
        {--term-start= : Tanggal mulai periode (YYYY-MM-DD)}
        {--term-end= : Tanggal selesai periode (YYYY-MM-DD)}
        {--admin-name= : Nama Super Admin}
        {--admin-email= : Email Super Admin}';

    protected $description = 'Buat organisasi, periode berjalan, dan akun Super Admin pertama';

    public function handle(): int
    {
        $orgName = $this->value('org-name', 'Nama organisasi');
        $d = [
            'org_name' => $orgName,
            'org_slug' => $this->value('org-slug', 'Kode organisasi', Str::slug((string) $orgName)),
            'term_name' => $this->value('term-name', 'Nama periode berjalan', now()->year.'/'.(now()->year + 1)),
            'term_start' => $this->value('term-start', 'Mulai periode (YYYY-MM-DD)', now()->startOfYear()->toDateString()),
            'term_end' => $this->value('term-end', 'Selesai periode (YYYY-MM-DD)', now()->endOfYear()->toDateString()),
            'admin_name' => $this->value('admin-name', 'Nama Super Admin'),
            'admin_email' => $this->value('admin-email', 'Email Super Admin'),
        ];

        $v = Validator::make($d, [
            'org_name' => 'required|string|max:150',
            'org_slug' => 'required|alpha_dash:ascii|max:60|unique:organizations,slug',
            'term_name' => 'required|string|max:100',
            'term_start' => 'required|date_format:Y-m-d',
            'term_end' => 'required|date_format:Y-m-d|after:term_start',
            'admin_name' => 'required|string|max:150',
            'admin_email' => 'required|email|unique:users,email',
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        $password = Str::password(14, symbols: false);
        $admin = DB::transaction(function () use ($d, $password) {
            $org = Organization::create(['name' => $d['org_name'], 'slug' => $d['org_slug']]);
            Term::create(['organization_id' => $org->id, 'name' => $d['term_name'],
                'starts_on' => $d['term_start'], 'ends_on' => $d['term_end'], 'is_current' => true]);

            return User::create(['organization_id' => $org->id, 'name' => $d['admin_name'], 'email' => $d['admin_email'],
                'password' => $password, 'role' => User::SUPER_ADMIN, 'email_verified_at' => now()]);
        });
        AuditLogger::log('setup.organization_created', 'organization', $admin->organization_id, ['admin_email' => $admin->email], $admin->id);

        $this->info("Organisasi \"{$d['org_name']}\" siap.");
        $this->table(['Login dashboard', 'Password sementara'], [[$admin->email, $password]]);
        $this->warn('Password ini hanya ditampilkan sekali. Ganti setelah login: Akun Saya → Ganti password.');

        return self::SUCCESS;
    }

    private function value(string $option, string $question, ?string $default = null): ?string
    {
        $value = $this->option($option);
        if ($value !== null && $value !== '') {
            return trim($value);
        }

        return $this->input->isInteractive() ? trim((string) $this->ask($question, $default)) : $default;
    }
}
