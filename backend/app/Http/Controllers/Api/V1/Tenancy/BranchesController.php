<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tenancy;

use App\Domain\Authorization\Permissions;
use App\Domain\Inventory\Models\Stock;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\StoreBranchRequest;
use App\Http\Requests\Tenancy\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class BranchesController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::BRANCH_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = Branch::query();

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return BranchResource::collection($query->orderBy('name')->paginate($perPage));
    }

    public function show(Request $request, Branch $branch): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::BRANCH_VIEW), 403);

        return response()->json(['data' => new BranchResource($branch)]);
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::BRANCH_CREATE), 403);

        $data = $request->validated();
        $data['uuid'] = (string) Str::uuid();
        $data['company_id'] = TenantContext::id();
        $data['is_active'] = true;

        $branch = Branch::create($data);

        return response()->json(
            ['data' => new BranchResource($branch)],
            Response::HTTP_CREATED
        );
    }

    public function update(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::BRANCH_UPDATE), 403);

        $branch->update($request->validated());

        return response()->json(['data' => new BranchResource($branch->fresh())]);
    }

    /**
     * POST /api/v1/branches/{branch}/deactivate
     *
     * EX-180: cerrar sucursal marca is_active=false (los datos quedan, no se
     * borra). EX-181: no se permite desactivar una sucursal con stock pendiente;
     * hay que transferirlo a otra sucursal antes. Tampoco se desactiva la
     * sucursal default (el tenant debe conservar al menos su sucursal base).
     */
    public function deactivate(Request $request, Branch $branch): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::BRANCH_UPDATE), 403);

        if ($branch->is_default) {
            return $this->conflict(
                'BRANCH_IS_DEFAULT',
                'No se puede desactivar la sucursal default del tenant.'
            );
        }

        // EX-181: stock pendiente en cualquier almacen de la sucursal.
        $hasStock = Stock::query()
            ->whereHas('warehouse', fn ($q) => $q->where('branch_id', $branch->id))
            ->where('quantity_on_hand', '>', 0)
            ->exists();

        if ($hasStock) {
            return $this->conflict(
                'BRANCH_HAS_STOCK',
                'La sucursal tiene stock pendiente. Transfiera el inventario a otra sucursal antes de desactivarla.'
            );
        }

        $branch->update(['is_active' => false]);

        return response()->json(['data' => new BranchResource($branch->fresh())]);
    }

    private function conflict(string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
        ], Response::HTTP_CONFLICT);
    }
}
