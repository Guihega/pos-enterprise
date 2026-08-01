<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Domain\Authorization\Permissions;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StoreSupplierRequest;
use App\Http\Requests\Purchasing\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Maestro de proveedores (4.1.5 Compras).
 *
 * Alcance de este ciclo: CRUD + baja logica. Los endpoints
 * /suppliers/{uuid}/products y /suppliers/{uuid}/balance que define el
 * maestro (29.7) quedan DIFERIDOS: el primero necesita products.supplier_id,
 * que no existe; el segundo es cuentas por pagar y depende de
 * supplier-invoices, fuera de alcance.
 */
class SuppliersController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::SUPPLIER_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);
        $query = Supplier::query();

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return SupplierResource::collection($query->orderBy('name')->paginate($perPage));
    }

    public function show(Request $request, Supplier $supplier): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::SUPPLIER_VIEW), 403);

        return response()->json(['data' => new SupplierResource($supplier)]);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::SUPPLIER_CREATE), 403);

        $data = $request->validated();
        $data['uuid'] = (string) Str::uuid();
        $data['company_id'] = TenantContext::id();
        $data['is_active'] = true;

        $supplier = Supplier::create($data);

        return response()->json(
            ['data' => new SupplierResource($supplier)],
            Response::HTTP_CREATED
        );
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::SUPPLIER_UPDATE), 403);

        $supplier->update($request->validated());

        return response()->json(['data' => new SupplierResource($supplier->fresh())]);
    }

    /**
     * POST /api/v1/suppliers/{supplier}/deactivate
     *
     * Baja logica por convencion del repo (PATCH para editar, deactivate para
     * baja, nunca DELETE). Sin guard de dependencias porque todavia no existe
     * nada que dependa de un proveedor. Cuando exista purchase_orders, aqui
     * va el conflict por ordenes abiertas, igual que BRANCH_HAS_STOCK.
     */
    public function deactivate(Request $request, Supplier $supplier): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::SUPPLIER_UPDATE), 403);

        $supplier->update(['is_active' => false]);

        return response()->json(['data' => new SupplierResource($supplier->fresh())]);
    }
}
