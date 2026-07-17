<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sync;

use App\Domain\Authorization\Permissions;
use App\Domain\Sync\Models\SyncConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\ResolveSyncConflictRequest;
use App\Http\Resources\SyncConflictResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /sync/conflicts + POST /sync/conflicts/{uuid}/resolve
 * (maestro 29.13 lineas 6118-6119, sec. 39.3).
 *
 * resolve() registra la decision humana (resolution, resolved_by,
 * notes) y desbloquea la cola; NO ejecuta efectos secundarios (las
 * acciones de la UI 39.3 como "asignar a otra sesion" o "cancelar
 * venta" no tienen especificacion de backend: DIFERIDAS; la accion
 * correctiva se hace por los endpoints normales del dominio).
 * Auditoria RN-170 DIFERIDA (activity_log inexistente).
 */
final class SyncConflictsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::SYNC_CONFLICT_VIEW), 403);

        $query = SyncConflict::query()->orderByDesc('created_at');

        if ($request->boolean('resolved')) {
            $query->whereNotNull('resolved_at');
        } else {
            $query->pending();
        }

        return SyncConflictResource::collection($query->get());
    }

    public function resolve(ResolveSyncConflictRequest $request, SyncConflict $conflict): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::SYNC_CONFLICT_RESOLVE), 403);

        // Transicion invalida: ya resuelto => 409 (patron BatchController).
        abort_if($conflict->resolved_at !== null, 409, 'El conflicto ya fue resuelto.');

        $conflict->update([
            'resolution' => $request->validated('resolution'),
            'notes' => $request->validated('notes'),
            'resolved_at' => now(),
            'resolved_by' => $request->user()->id,
        ]);

        return response()->json(['data' => new SyncConflictResource($conflict->fresh())]);
    }
}
