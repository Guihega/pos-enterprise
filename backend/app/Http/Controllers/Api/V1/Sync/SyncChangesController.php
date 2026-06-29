<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sync;

use App\Domain\Sync\Services\SyncChangesService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\SyncChangesRequest;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/sync/changes?since=...&entities=products,taxes,customers
 *
 * Pull de cambios del catalogo desde un timestamp (sec. 38.5).
 * Devuelve created/updated/deleted por entidad + meta con snapshot.
 */
final class SyncChangesController extends Controller
{
    public function __construct(
        private readonly SyncChangesService $service,
    ) {}

    public function __invoke(SyncChangesRequest $request): JsonResponse
    {
        $result = $this->service->changesSince(
            $request->since(),
            $request->entitiesList(),
        );

        return response()->json($result);
    }
}
