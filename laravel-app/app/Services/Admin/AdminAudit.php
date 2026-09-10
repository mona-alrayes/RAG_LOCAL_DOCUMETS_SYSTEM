<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;

class AdminAudit
{
    public static function record(?int $actorId, string $action, ?string $subjectType = null, ?int $subjectId = null, string $outcome = 'success'): void
    {
        AdminAuditLog::query()->create(['actor_id' => $actorId, 'action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'outcome' => $outcome]);
    }
}
