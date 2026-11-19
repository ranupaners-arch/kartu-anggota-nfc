<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\Event;
use App\Models\EventTarget;
use App\Models\Membership;
use App\Models\User;
use App\Models\Term;
use App\Services\AuditLogger;
use App\Services\PrivacyService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function index(Request $r)
    {
        return Inertia::render('settings/index', [
            'tab' => $r->query('tab', 'organisasi'),
            'org' => $r->user()->organization->only('name', 'slug'),
            'terms' => Term::orderByDesc('starts_on')->get(),
            'divisions' => Division::withCount('memberships')->orderBy('name')->get(),
            'connect' => self::connectInfo(),
            'privacy' => fn () => $r->query('tab') === 'privasi' ? [
                'excuseDays' => $r->user()->organization->retention_excuse_days,
                'memberMonths' => $r->user()->organization->retention_member_months,
                'exportDays' => (int) config('attendance.export_retention_days'),
                'pending' => app(PrivacyService::class)->retention($r->user()->organization, dryRun: true),
            ] : null,
            'health' => fn () => $r->query('tab') === 'sistem' ? app(\App\Services\HealthService::class)->check() + [
                'sentry' => (bool) config('sentry.dsn'),
                'healthTokenSet' => (bool) config('attendance.health_token'),
                'healthUrl' => url('/api/health'),
            ] : null,
        ]);
    }

    /**
     * Data QR "Hubungkan app": alamat server ini + nama instans.
     * Format: kartuanggota://connect?server=<url>&name=<nama>
     */
    public static function connectInfo(): array
    {
        $server = rtrim(url('/'), '/');
        $name = config('attendance.instance_name');

        return [
            'server' => $server,
            'name' => $name,
            'payload' => 'kartuanggota://connect?'.http_build_query(['server' => $server, 'name' => $name]),
            'https' => str_starts_with($server, 'https://'),
        ];
    }

    public function updateOrganization(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:150']);
        $r->user()->organization->update($d);
        AuditLogger::log('organization.updated', 'organization', $r->user()->organization_id);

        return back()->with('status', 'Profil organisasi disimpan.');
    }

    public function updatePrivacy(Request $r)
    {
        $d = $r->validate([
            'retention_excuse_days' => 'required|integer|min:30|max:3650',
            'retention_member_months' => 'nullable|integer|min:6|max:120',
        ]);
        $r->user()->organization->update($d);
        AuditLogger::log('organization.retention_updated', 'organization', $r->user()->organization_id, $d);

        return back()->with('status', 'Pengaturan retensi disimpan.');
    }

    public function storeTerm(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:100', 'starts_on' => 'required|date', 'ends_on' => 'required|date|after:starts_on']);
        $term = Term::create($d + ['is_current' => false]);
        AuditLogger::log('term.created', 'term', $term->id);

        return back()->with('status', 'Periode ditambahkan.');
    }

    public function updateTerm(Request $r, Term $term)
    {
        $term->update($r->validate(['name' => 'required|string|max:100', 'starts_on' => 'required|date', 'ends_on' => 'required|date|after:starts_on']));
        AuditLogger::log('term.updated', 'term', $term->id);

        return back()->with('status', 'Periode diperbarui.');
    }

    /** Hapus periode yang belum dipakai (keanggotaan & kegiatan ikut terhapus bila dipaksa, jadi ditolak). */
    public function destroyTerm(Term $term)
    {
        abort_if($term->is_current, 422, 'Periode berjalan tidak bisa dihapus.');
        $used = ['keanggotaan' => Membership::where('term_id', $term->id)->count(), 'kegiatan' => Event::where('term_id', $term->id)->count()];
        abort_if(array_sum($used) > 0, 422, 'Periode masih dipakai ('.self::usage($used).'); tidak bisa dihapus.');
        $term->delete();
        AuditLogger::log('term.deleted', 'term', $term->id, ['name' => $term->name]);

        return back()->with('status', "Periode {$term->name} dihapus.");
    }

    /** Jadikan periode berjalan. Data periode lama tidak diubah. */
    public function setCurrentTerm(Term $term)
    {
        DB::transaction(function () use ($term) {
            Term::where('is_current', true)->update(['is_current' => false]);
            $term->update(['is_current' => true]);
        });
        AuditLogger::log('term.set_current', 'term', $term->id);

        return back()->with('status', "Periode berjalan sekarang: {$term->name}.");
    }

    public function storeDivision(Request $r)
    {
        $division = Division::create($this->divisionInput($r));
        AuditLogger::log('division.created', 'division', $division->id);

        return back()->with('status', 'Divisi ditambahkan.');
    }

    public function updateDivision(Request $r, Division $division)
    {
        $division->update($this->divisionInput($r, $division));
        AuditLogger::log('division.updated', 'division', $division->id);

        return back()->with('status', 'Divisi diganti nama.');
    }

    /**
     * Hapus divisi yang belum dipakai. Ditolak bila masih dipakai: menghapusnya akan membuang target
     * kegiatan (kegiatan berubah jadi untuk semua anggota) dan melepas admin divisi.
     */
    public function destroyDivision(Division $division)
    {
        $used = [
            'keanggotaan' => Membership::where('division_id', $division->id)->count(),
            'kegiatan' => Event::where('division_id', $division->id)->count(),
            'target kegiatan' => EventTarget::where('division_id', $division->id)->count(),
            'akun admin' => User::where('division_id', $division->id)->count(),
        ];
        abort_if(array_sum($used) > 0, 422, "Divisi {$division->name} masih dipakai (".self::usage($used).'); pindahkan dulu, lalu hapus.');
        $division->delete();
        AuditLogger::log('division.deleted', 'division', $division->id, ['name' => $division->name]);

        return back()->with('status', "Divisi {$division->name} dihapus.");
    }

    private static function usage(array $used): string
    {
        return collect($used)->filter()->map(fn ($n, $what) => "$n $what")->implode(', ');
    }

    private function divisionInput(Request $r, ?Division $division = null): array
    {
        return $r->validate(['name' => ['required', 'string', 'max:100',
            Rule::unique('divisions')->where('organization_id', $r->user()->organization_id)->ignore($division?->id)]]);
    }
}
