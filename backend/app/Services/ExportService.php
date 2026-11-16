<?php

namespace App\Services;

use App\Jobs\GenerateExport;
use App\Models\Event;
use App\Models\Export;
use App\Models\Term;
use App\Models\User;

/** Permintaan ekspor (diproses di queue). Validasi akses dilakukan saat request. */
class ExportService
{
    public function __construct(private EventService $events) {}

    /** Admin Divisi selalu dibatasi ke divisinya. */
    public function divisionScope(User $user, ?int $requested): ?int
    {
        if ($user->role === User::DIVISION_ADMIN) {
            return $user->division_id ?? abort(403, 'Akun belum terhubung ke divisi.');
        }

        return $requested ?: null;
    }

    public function request(User $user, string $report, string $format, array $ids): Export
    {
        $params = [];
        if ($report === 'event') {
            $event = Event::findOrFail($ids['event_id'] ?? 0);
            $this->events->authorize($user, $event);
            $params['event_id'] = $event->id;
        } else {
            $params['term_id'] = Term::findOrFail($ids['term_id'] ?? 0)->id;
            $params['division_id'] = $this->divisionScope($user, $ids['division_id'] ?? null);
        }

        $export = Export::create(['user_id' => $user->id, 'report' => $report, 'format' => $format, 'params' => $params]);
        GenerateExport::dispatch($export->id);
        AuditLogger::log('export.requested', 'export', $export->id, ['report' => $report, 'format' => $format]);

        return $export->fresh();
    }

    public function canAccess(User $user, Export $export): bool
    {
        return $user->isSuperAdmin() || $export->user_id === $user->id;
    }
}
