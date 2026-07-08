<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Domain\Authorization\Permissions;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\Stock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Requests\Inventory\TransferStockRequest;
use App\Http\Resources\InventoryMovementResource;
use App\Http\Resources\StockResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $service,
    ) {}

    /**
     * GET /api/v1/inventory/stocks
     *
     * Filtros: warehouse, product, low_stock=true|false
     */
    public function stocks(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::INVENTORY_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = Stock::query()->with(['product', 'warehouse']);

        // RN-233 / doc maestro 46.4: por defecto la terminal solo ve el stock
        // de su(s) sucursal(es). Solo con inventory.view.cross-branch puede ver
        // el de otras (caso gerente: sugerencias de transferencia).
        $user = $request->user();
        if (! $user->can(Permissions::INVENTORY_VIEW_CROSS_BRANCH)) {
            $branchIds = $user->branches()->pluck('branches.id')->all();
            $query->whereHas('warehouse', function ($q) use ($branchIds): void {
                $q->whereIn('branch_id', $branchIds);
            });
        }

        if ($warehouseUuid = $request->query('warehouse')) {
            $whId = Warehouse::query()->where('uuid', $warehouseUuid)->value('id');
            $query->where('warehouse_id', $whId);
        }

        if ($productUuid = $request->query('product')) {
            $pId = Product::query()->where('uuid', $productUuid)->value('id');
            $query->where('product_id', $pId);
        }

        if ($request->boolean('low_stock')) {
            // Postgres: comparar quantity_on_hand <= stock_min cuando stock_min IS NOT NULL
            $query->whereNotNull('stock_min')
                ->whereColumn('quantity_on_hand', '<=', 'stock_min');
        }

        return StockResource::collection($query->paginate($perPage));
    }

    /**
     * POST /api/v1/inventory/adjust
     */
    public function adjust(AdjustStockRequest $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::INVENTORY_ADJUST), 403);

        $validated = $request->validated();

        $product = Product::query()->where('uuid', $validated['product_uuid'])->firstOrFail();
        $warehouse = Warehouse::query()->where('uuid', $validated['warehouse_uuid'])->firstOrFail();

        try {
            $movement = $this->service->adjust(
                product: $product,
                warehouse: $warehouse,
                delta: (float) $validated['delta'],
                reason: $validated['reason'],
                userId: $request->user()->id,
            );
        } catch (InsufficientStockException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INSUFFICIENT_STOCK',
                    'message' => $e->getMessage(),
                ],
            ], Response::HTTP_CONFLICT);
        }

        $movement->load(['product', 'warehouse', 'user']);

        return response()->json(
            ['data' => new InventoryMovementResource($movement)],
            Response::HTTP_CREATED
        );
    }

    /**
     * POST /api/v1/inventory/transfer
     */
    public function transfer(TransferStockRequest $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::INVENTORY_TRANSFER), 403);

        $validated = $request->validated();

        $product = Product::query()->where('uuid', $validated['product_uuid'])->firstOrFail();
        $from = Warehouse::query()->where('uuid', $validated['from_warehouse_uuid'])->firstOrFail();
        $to = Warehouse::query()->where('uuid', $validated['to_warehouse_uuid'])->firstOrFail();

        try {
            $result = $this->service->transfer(
                product: $product,
                from: $from,
                to: $to,
                quantity: (float) $validated['quantity'],
                reason: $validated['reason'] ?? null,
                userId: $request->user()->id,
            );
        } catch (InsufficientStockException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INSUFFICIENT_STOCK',
                    'message' => $e->getMessage(),
                ],
            ], Response::HTTP_CONFLICT);
        }

        $result['out']->load(['product', 'warehouse', 'user']);
        $result['in']->load(['product', 'warehouse', 'user']);

        return response()->json([
            'data' => [
                'transfer_id' => $result['transfer_id'],
                'out' => new InventoryMovementResource($result['out']),
                'in' => new InventoryMovementResource($result['in']),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/inventory/movements
     *
     * Kardex con filtros: product, warehouse, type, from, to.
     */
    public function movements(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::INVENTORY_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = InventoryMovement::query()->with(['product', 'warehouse', 'user']);

        if ($productUuid = $request->query('product')) {
            $pId = Product::query()->where('uuid', $productUuid)->value('id');
            $query->where('product_id', $pId);
        }

        if ($warehouseUuid = $request->query('warehouse')) {
            $whId = Warehouse::query()->where('uuid', $warehouseUuid)->value('id');
            $query->where('warehouse_id', $whId);
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($from = $request->query('from')) {
            $query->where('movement_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('movement_at', '<=', $to);
        }

        return InventoryMovementResource::collection(
            $query->orderByDesc('movement_at')->paginate($perPage)
        );
    }
}
