<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Domain\Authorization\Permissions;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreWarehouseRequest;
use App\Http\Requests\Inventory\UpdateWarehouseRequest;
use App\Http\Resources\WarehouseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class WarehousesController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::INVENTORY_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = Warehouse::query()->with('branch');

        if ($branchUuid = $request->query('branch')) {
            $branchId = Branch::query()->where('uuid', $branchUuid)->value('id');
            $query->where('branch_id', $branchId);
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return WarehouseResource::collection(
            $query->orderBy('name')->paginate($perPage)
        );
    }

    public function show(Request $request, Warehouse $warehouse): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::INVENTORY_VIEW), 403);

        $warehouse->load('branch');

        return response()->json(['data' => new WarehouseResource($warehouse)]);
    }

    public function store(StoreWarehouseRequest $request): JsonResponse
    {
        // Crear/editar warehouses requiere SETTINGS_UPDATE (es estructura, no operación)
        abort_unless((bool) $request->user()?->can(Permissions::SETTINGS_UPDATE), 403);

        $data = $request->validated();
        $data['uuid'] = (string) Str::uuid();
        $data['company_id'] = TenantContext::id();
        $data['branch_id'] = Branch::query()->where('uuid', $data['branch_uuid'])->value('id');
        unset($data['branch_uuid']);

        // Si se marca como default, desmarcar el actual default de la branch
        if (! empty($data['is_default'])) {
            Warehouse::query()
                ->where('branch_id', $data['branch_id'])
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $warehouse = Warehouse::create($data);
        $warehouse->load('branch');

        return response()->json(
            ['data' => new WarehouseResource($warehouse)],
            Response::HTTP_CREATED
        );
    }

    /**
     * PATCH /api/v1/warehouses/{warehouse}
     *
     * No permite mover el almacen de sucursal ni darlo de baja: branch_uuid e
     * is_active quedan fuera de UpdateWarehouseRequest. La baja pasa por deactivate().
     */
    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): JsonResponse
    {
        // Editar warehouses requiere SETTINGS_UPDATE (es estructura, no operacion)
        abort_unless((bool) $request->user()?->can(Permissions::SETTINGS_UPDATE), 403);

        $data = $request->validated();

        // Si se marca como default, desmarcar el actual default de la misma branch
        if (! empty($data['is_default'])) {
            Warehouse::query()
                ->where('branch_id', $warehouse->branch_id)
                ->where('id', '!=', $warehouse->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $warehouse->update($data);
        $warehouse->refresh()->load('branch');

        return response()->json(['data' => new WarehouseResource($warehouse)]);
    }

    /**
     * POST /api/v1/warehouses/{warehouse}/deactivate
     *
     * Baja logica (is_active=false), nunca borrado: los movimientos historicos
     * referencian el almacen. No se desactiva el almacen default de la sucursal ni
     * uno con stock pendiente; hay que transferir el inventario antes.
     */
    public function deactivate(Request $request, Warehouse $warehouse): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::SETTINGS_UPDATE), 403);

        if ($warehouse->is_default) {
            return $this->conflict(
                'WAREHOUSE_IS_DEFAULT',
                'No se puede desactivar el almacen default de la sucursal.'
            );
        }

        $hasStock = $warehouse->stocks()
            ->where('quantity_on_hand', '>', 0)
            ->exists();

        if ($hasStock) {
            return $this->conflict(
                'WAREHOUSE_HAS_STOCK',
                'El almacen tiene stock pendiente. Transfiera el inventario antes de desactivarlo.'
            );
        }

        $warehouse->update(['is_active' => false]);
        $warehouse->refresh()->load('branch');

        return response()->json(['data' => new WarehouseResource($warehouse)]);
    }

    private function conflict(string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
        ], Response::HTTP_CONFLICT);
    }
}
