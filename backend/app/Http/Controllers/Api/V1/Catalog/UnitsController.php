<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Domain\Authorization\Permissions;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreUnitRequest;
use App\Http\Requests\Catalog\UpdateUnitRequest;
use App\Http\Resources\UnitResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class UnitsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = Unit::query();

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return UnitResource::collection(
            $query->orderBy('category')->orderBy('factor')->paginate($perPage)
        );
    }

    public function show(Request $request, Unit $unit): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_VIEW), 403);

        return response()->json(['data' => new UnitResource($unit)]);
    }

    public function store(StoreUnitRequest $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_CREATE), 403);

        $data = $request->validated();
        $data['uuid'] = (string) Str::uuid();
        $data['company_id'] = TenantContext::id();

        $unit = Unit::create($data);

        return response()->json(
            ['data' => new UnitResource($unit)],
            Response::HTTP_CREATED
        );
    }

    public function update(UpdateUnitRequest $request, Unit $unit): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_UPDATE), 403);

        $unit->update($request->validated());

        return response()->json(['data' => new UnitResource($unit)]);
    }

    public function destroy(Request $request, Unit $unit): Response|JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_DELETE), 403);

        // FK products.unit_id es restrictOnDelete: si hay productos usándola,
        // la BD rechaza el delete. Verificamos primero para devolver un error
        // útil (409 Conflict) en lugar de 500 por QueryException.
        $inUse = Product::query()->where('unit_id', $unit->id)->exists();
        if ($inUse) {
            return response()->json([
                'error' => [
                    'code' => 'UNIT_IN_USE',
                    'message' => 'No se puede borrar una unidad que está asignada a productos.',
                ],
            ], Response::HTTP_CONFLICT);
        }

        $unit->delete();

        return response()->noContent();
    }
}
