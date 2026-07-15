<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sync;

use App\Domain\Sync\Dto\SyncBatchItem;
use App\Domain\Sync\Exceptions\DeviceRevokedException;
use App\Domain\Sync\Models\SyncDevice;
use App\Domain\Sync\Services\SyncBatchService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\SyncBatchRequest;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/sync/batch
 *
 * Recibe un batch de operaciones offline y las procesa en orden.
 * Doc maestro sec. 38.3. Idempotente por batch_uuid (TODO: cache en Fase 3).
 */
final class SyncBatchController extends Controller
{
    public function __construct(
        private readonly SyncBatchService $service,
    ) {}

    public function __invoke(SyncBatchRequest $request): JsonResponse
    {
        // Enforcement SYNC_DEVICE_UNREGISTERED: si el batch se identifica,
        // el dispositivo debe existir y estar activo (uno desconocido o
        // revocado via DELETE /auth/devices no puede empujar operaciones).
        // Batches sin device_id conservan el contrato 38.3 (campo opcional).
        $deviceId = $request->validated('device_id');
        if ($deviceId !== null) {
            $isActive = SyncDevice::query()
                ->where('device_id', $deviceId)
                ->value('is_active');
            if ($isActive !== true) {
                throw DeviceRevokedException::forDevice($deviceId);
            }
        }

        $items = array_map(
            fn (array $raw) => SyncBatchItem::fromArray($raw),
            $request->validated('items'),
        );

        $results = $this->service->process(
            $items,
            $request->user(),
            $request->validated('batch_uuid'),
            $request->validated('device_id'),
        );

        return response()->json([
            'batch_uuid' => $request->validated('batch_uuid'),
            'results' => $results,
        ]);
    }
}
