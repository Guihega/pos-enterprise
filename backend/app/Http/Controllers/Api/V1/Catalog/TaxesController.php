<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Domain\Authorization\Permissions;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreTaxRequest;
use App\Http\Requests\Catalog\UpdateTaxRequest;
use App\Http\Resources\TaxResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaxesController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = Tax::query();

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return TaxResource::collection(
            $query->orderByDesc('is_default')->orderBy('rate')->paginate($perPage)
        );
    }

    public function show(Request $request, Tax $tax): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_VIEW), 403);

        return response()->json(['data' => new TaxResource($tax)]);
    }

    public function store(StoreTaxRequest $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_CREATE), 403);

        $data = $request->validated();
        $data['uuid'] = (string) Str::uuid();
        $data['company_id'] = TenantContext::id();

        // Si se marca como default, primero quitar default a los demás taxes
        // del tenant (para no chocar con el índice parcial unique).
        $tax = DB::transaction(function () use ($data) {
            if (! empty($data['is_default'])) {
                Tax::query()->where('is_default', true)->update(['is_default' => false]);
            }

            return Tax::create($data);
        });

        return response()->json(
            ['data' => new TaxResource($tax)],
            Response::HTTP_CREATED
        );
    }

    public function update(UpdateTaxRequest $request, Tax $tax): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_UPDATE), 403);

        $data = $request->validated();

        $tax = DB::transaction(function () use ($data, $tax) {
            // Si se está marcando este como default, quitar el flag a los demás.
            if (! empty($data['is_default']) && ! $tax->is_default) {
                Tax::query()
                    ->where('is_default', true)
                    ->where('id', '!=', $tax->id)
                    ->update(['is_default' => false]);
            }

            $tax->update($data);

            return $tax;
        });

        return response()->json(['data' => new TaxResource($tax)]);
    }

    public function destroy(Request $request, Tax $tax): Response|JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_DELETE), 403);

        // No permitir borrar el tax default (siempre debe haber uno)
        if ($tax->is_default) {
            return response()->json([
                'error' => [
                    'code' => 'TAX_IS_DEFAULT',
                    'message' => 'No se puede borrar el impuesto por defecto. Primero asigne otro como default.',
                ],
            ], Response::HTTP_CONFLICT);
        }

        $tax->delete();

        return response()->noContent();
    }
}
