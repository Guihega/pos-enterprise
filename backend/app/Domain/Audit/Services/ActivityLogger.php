<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Punto unico de escritura de activity_log (RN-170: toda accion
 * modificante se registra; RN-171: inmutable, garantizado por trigger
 * de BD en migracion 000043).
 *
 * Captura automatica de contexto HTTP cuando hay request activo:
 * causer (usuario autenticado + snapshot de nombre, RN-177), ip,
 * user agent (RN-176), request_id (mismo patron X-Request-Id de
 * bootstrap/app.php y EnsureTenantContext).
 *
 * company_id lo asigna BelongsToTenant desde TenantContext (patron
 * NotificationService). deviceId y batchUuid son overrides para
 * caminos sync donde el contexto no viene del request.
 */
final class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(
        string $logName,
        string $event,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        string $severity = 'info',
        ?int $branchId = null,
        ?string $deviceId = null,
        ?string $batchUuid = null,
    ): ActivityLog {
        $request = request();
        $causer = $request?->user();

        return ActivityLog::query()->create([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $branchId,
            'log_name' => $logName,
            'description' => $description,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'causer_type' => $causer?->getMorphClass(),
            'causer_id' => $causer?->getKey(),
            'causer_name' => $causer?->name,
            'event' => $event,
            'properties' => $properties === [] ? null : $properties,
            'batch_uuid' => $batchUuid,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'device_id' => $deviceId,
            'request_id' => $request?->header('X-Request-Id') ?? $request?->headers->get('X-Request-ID'),
        ]);
    }
}
