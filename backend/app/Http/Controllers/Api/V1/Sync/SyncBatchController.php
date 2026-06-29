<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sync;

use App\Domain\Sync\Dto\SyncBatchItem;
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
        $items = array_map(
            fn (array $raw) => SyncBatchItem::fromArray($raw),
            $request->validated('items'),
        );

        $results = $this->service->process($items, $request->user());

        return response()->json([
            'batch_uuid' => $request->validated('batch_uuid'),
            'results' => $results,
        ]);
    }
}
