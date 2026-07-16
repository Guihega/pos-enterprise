<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sync;

use App\Domain\Authorization\Permissions;
use App\Domain\Sync\Models\SyncBatch;
use App\Domain\Sync\Models\SyncDevice;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/sync/status/{device} (maestro 29.13).
 *
 * Estado de sync de un dispositivo: lectura de gestion (permiso
 * DEVICE_VIEW, consistente con GET /auth/devices), no endpoint
 * operado por el dispositivo. El maestro no especifica el shape de
 * respuesta; estandar defendible: identidad + actividad del device
 * (last_seen/last_sync) + resumen de sus ultimos batches desde
 * sync_batches. client_clock_drift (42.5) DIFERIDO: la columna no
 * existe en el DDL de sync_devices.
 */
final class SyncStatusController extends Controller
{
    public function __invoke(Request $request, SyncDevice $device): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::DEVICE_VIEW), 403);

        $recentBatches = SyncBatch::query()
            ->where('device_id', $device->device_id)
            ->orderByDesc('received_at')
            ->limit(10)
            ->get(['uuid', 'status', 'operations_count', 'success_count', 'conflict_count', 'error_count', 'received_at', 'completed_at']);

        return response()->json([
            'data' => [
                'device_uuid' => $device->uuid,
                'device_id' => $device->device_id,
                'is_active' => $device->is_active,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'last_sync_at' => $device->last_sync_at?->toIso8601String(),
                'recent_batches' => $recentBatches->map(fn (SyncBatch $b): array => [
                    'uuid' => $b->uuid,
                    'status' => $b->status,
                    'operations_count' => $b->operations_count,
                    'success_count' => $b->success_count,
                    'conflict_count' => $b->conflict_count,
                    'error_count' => $b->error_count,
                    'received_at' => $b->received_at?->toIso8601String(),
                    'completed_at' => $b->completed_at?->toIso8601String(),
                ]),
            ],
        ]);
    }
}
