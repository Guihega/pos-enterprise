<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sync;

use App\Domain\Sync\Services\SyncSnapshotService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\SyncSnapshotPageRequest;
use Illuminate\Http\JsonResponse;

/**
 * Snapshot inicial del catalogo (doc maestro sec. 38.6).
 *
 * POST /api/v1/sync/snapshot/{entity} => manifest (total, per_page, cursor).
 * GET  /api/v1/sync/snapshot/{entity}?cursor=N => pagina keyset.
 *
 * Divergencia documentada: sin job async (ver docblock del service).
 * Autorizacion: mismo criterio que sync/changes (dispositivo autenticado
 * via grupo sync: tenant + auth:sanctum), sin permiso administrativo.
 * Entidad invalida => 404 (whereIn en la ruta).
 */
final class SyncSnapshotController extends Controller
{
    public function __construct(
        private readonly SyncSnapshotService $service,
    ) {}

    public function manifest(string $entity): JsonResponse
    {
        return response()->json($this->service->manifest($entity));
    }

    public function page(SyncSnapshotPageRequest $request, string $entity): JsonResponse
    {
        return response()->json($this->service->page($entity, $request->cursor()));
    }
}
