<?php

namespace App\Services;

use App\Models\AuditLog;

class AuditLogger
{
    public static function log(string $action, ?string $entity = null, ?int $entityId = null, array $payload = [], ?int $userId = null): void
    {
        AuditLog::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'payload' => $payload ?: null,
            'ip' => request()?->ip(),
        ]);
    }
}
