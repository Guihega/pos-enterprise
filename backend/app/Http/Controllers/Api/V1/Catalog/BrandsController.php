<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Domain\Authorization\Permissions;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreBrandRequest;
use App\Http\Requests\Catalog\UpdateBrandRequest;
use App\Http\Resources\BrandResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class BrandsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = Brand::query();

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        if ($q = trim((string) $request->query('q', ''))) {
            $query->where('name', 'ilike', "%{$q}%");
        }

        return BrandResource::collection($query->orderBy('name')->paginate($perPage));
    }

    public function show(Request $request, Brand $brand): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_VIEW), 403);

        return response()->json(['data' => new BrandResource($brand)]);
    }

    public function store(StoreBrandRequest $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_CREATE), 403);

        $data = $request->validated();
        $data['uuid'] = (string) Str::uuid();
        $data['company_id'] = TenantContext::id();

        $brand = Brand::create($data);

        return response()->json(
            ['data' => new BrandResource($brand)],
            Response::HTTP_CREATED
        );
    }

    public function update(UpdateBrandRequest $request, Brand $brand): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_UPDATE), 403);

        $brand->update($request->validated());

        return response()->json(['data' => new BrandResource($brand)]);
    }

    public function destroy(Request $request, Brand $brand): Response
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_DELETE), 403);

        $brand->delete();

        return response()->noContent();
    }
}
