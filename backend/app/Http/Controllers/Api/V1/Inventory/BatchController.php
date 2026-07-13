<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Domain\Authorization\Permissions;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Tenancy\Models\Branch;
use App\Http\Controllers\Controller;
use App\Http\Resources\BatchResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * API de lotes (doc maestro 29.6).
 *
 * Estandares adoptados (el maestro define endpoints sin semantica fina):
 * - Permisos: lecturas con inventory.view; quarantine/release con
 *   inventory.adjust (bloquear/liberar un lote altera disponibilidad,
 *   misma naturaleza que un ajuste). No se crean permisos nuevos.
 * - RN-233 replicada de InventoryController::stocks(): sin
 *   inventory.view.cross-branch las lecturas solo ven lotes de las
 *   sucursales del usuario; show devuelve 404 para no revelar existencia.
 * - Mutaciones validan solo permiso (patron real de adjust/transfer).
 * - Transiciones invalidas de status devuelven 409 con codigo de error.
 * - expirations: umbral configurable via ?days= (default 30, clamp 1-365);
 *   incluye lotes ya vencidos, que quedan al tope por orden FEFO.
 */
class BatchController extends Controller
{
    /**
     * GET /api/v1/inventory/batches
     *
     * Filtros: product (uuid), branch (uuid), status (available|quarantined)
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::INVENTORY_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = Batch::query()->with(['product', 'branch', 'warehouse']);

        $this->applyBranchVisibility($request, $query);

        if ($productUuid = $request->query('product')) {
            $pId = Product::query()->where('uuid', $productUuid)->value('id');
            $query->where('product_id', $pId);
        }

        if ($branchUuid = $request->query('branch')) {
            $bId = Branch::query()->where('uuid', $branchUuid)->value('id');
            $query->where('branch_id', $bId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return BatchResource::collection($query->fefo()->paginate($perPage));
    }

    /**
     * GET /api/v1/inventory/batches/{uuid}
     */
    public function show(Request $request, Batch $batch): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::INVENTORY_VIEW), 403);

        $this->assertBatchVisible($request, $batch);

        return response()->json([
            'data' => new BatchResource($batch->load(['product', 'branch', 'warehouse'])),
        ]);
    }

    /**
     * POST /api/v1/inventory/batches/{uuid}/quarantine
     *
     * Bloquear lote (maestro 29.6): fuera de circulacion total.
     * El scope available() lo excluye, por lo que el FEFO de venta
     * (InventoryService::consumeBatchesFefo) no lo consume.
     */
    public function quarantine(Request $request, Batch $batch): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::INVENTORY_ADJUST), 403);

        if ($batch->isQuarantined()) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_ALREADY_QUARANTINED',
                    'message' => 'El lote ya se encuentra en cuarentena.',
                ],
            ], Response::HTTP_CONFLICT);
        }

        $batch->update(['status' => Batch::STATUS_QUARANTINED]);

        return response()->json([
            'data' => new BatchResource($batch->load(['product', 'branch', 'warehouse'])),
        ]);
    }

    /**
     * POST /api/v1/inventory/batches/{uuid}/release
     */
    public function release(Request $request, Batch $batch): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::INVENTORY_ADJUST), 403);

        if (! $batch->isQuarantined()) {
            return response()->json([
                'error' => [
                    'code' => 'BATCH_NOT_QUARANTINED',
                    'message' => 'El lote no se encuentra en cuarentena.',
                ],
            ], Response::HTTP_CONFLICT);
        }

        $batch->update(['status' => Batch::STATUS_AVAILABLE]);

        return response()->json([
            'data' => new BatchResource($batch->load(['product', 'branch', 'warehouse'])),
        ]);
    }

    /**
     * GET /api/v1/inventory/expirations
     *
     * Proximos a caducar (maestro 29.6, RN-195). Solo lotes en circulacion
     * (available con remanente) y con caducidad definida.
     */
    public function expirations(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::INVENTORY_VIEW), 403);

        $days = min(max((int) $request->query('days', 30), 1), 365);
        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = Batch::query()
            ->with(['product', 'branch', 'warehouse'])
            ->available()
            ->whereNotNull('expiration_date')
            ->where('expiration_date', '<=', now()->addDays($days)->toDateString());

        $this->applyBranchVisibility($request, $query);

        return BatchResource::collection($query->fefo()->paginate($perPage));
    }

    // -------------------- Helpers --------------------

    /**
     * RN-233 / doc maestro 46.4 (mismo criterio que stocks()): sin
     * inventory.view.cross-branch solo se ven lotes de las sucursales
     * del usuario.
     */
    private function applyBranchVisibility(Request $request, Builder $query): void
    {
        $user = $request->user();

        if ($user->can(Permissions::INVENTORY_VIEW_CROSS_BRANCH)) {
            return;
        }

        $branchIds = $user->branches()->pluck('branches.id')->all();
        $query->whereIn('branch_id', $branchIds);
    }

    private function assertBatchVisible(Request $request, Batch $batch): void
    {
        $user = $request->user();

        if ($user->can(Permissions::INVENTORY_VIEW_CROSS_BRANCH)) {
            return;
        }

        $branchIds = $user->branches()->pluck('branches.id')->all();

        // 404 y no 403 para no revelar existencia de lotes ajenos.
        abort_unless(in_array($batch->branch_id, $branchIds, true), 404);
    }
}
