<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Domain\Authorization\Permissions;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductsController extends Controller
{
    /**
     * GET /api/v1/products
     *
     * Filtros disponibles vía query params:
     *   - q          : búsqueda por nombre/SKU/barcode
     *   - status     : draft|active|archived
     *   - category   : UUID de la categoría
     *   - brand      : UUID de la marca
     *   - sellable   : true|false
     *   - per_page   : tamaño de página (default 20, max 100)
     *   - sort       : name|price|created_at (default name)
     *   - direction  : asc|desc (default asc)
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 20), 100);
        $sort = in_array($request->query('sort'), ['name', 'price', 'created_at'], true)
            ? $request->query('sort')
            : 'name';
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        $query = Product::query()->with(['category', 'brand', 'unit', 'tax']);

        if ($term = trim((string) $request->query('q', ''))) {
            $query->search($term);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($categoryUuid = $request->query('category')) {
            $catId = Category::query()->where('uuid', $categoryUuid)->value('id');
            $query->where('category_id', $catId);
        }

        if ($brandUuid = $request->query('brand')) {
            $brandId = Brand::query()->where('uuid', $brandUuid)->value('id');
            $query->where('brand_id', $brandId);
        }

        if ($request->has('sellable')) {
            $query->where('is_sellable', $request->boolean('sellable'));
        }

        $products = $query->orderBy($sort, $direction)->paginate($perPage);

        return ProductResource::collection($products);
    }

    /**
     * GET /api/v1/products/{uuid}
     */
    public function show(Request $request, Product $product): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_VIEW), 403);

        $product->load(['category', 'brand', 'unit', 'tax', 'barcodes', 'images']);

        return response()->json(['data' => new ProductResource($product)]);
    }

    /**
     * POST /api/v1/products
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_CREATE), 403);

        $validated = $request->validated();

        $product = DB::transaction(function () use ($validated) {
            $data = $this->mapInputToColumns($validated);
            $data['uuid'] = (string) Str::uuid();
            $data['company_id'] = TenantContext::id();

            return Product::create($data);
        });

        $product->load(['category', 'brand', 'unit', 'tax']);

        return response()->json(
            ['data' => new ProductResource($product)],
            Response::HTTP_CREATED
        );
    }

    /**
     * PATCH /api/v1/products/{uuid}
     */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_UPDATE), 403);

        $validated = $request->validated();
        $data = $this->mapInputToColumns($validated);

        $product->update($data);
        $product->load(['category', 'brand', 'unit', 'tax']);

        return response()->json(['data' => new ProductResource($product)]);
    }

    /**
     * DELETE /api/v1/products/{uuid}
     *
     * Soft delete. El registro se conserva por regulación contable.
     */
    public function destroy(Request $request, Product $product): Response
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_DELETE), 403);

        $product->delete();

        return response()->noContent();
    }

    /**
     * Convierte UUIDs del input a IDs internos (para FKs).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function mapInputToColumns(array $input): array
    {
        $output = $input;

        // category_uuid → category_id
        if (array_key_exists('category_uuid', $input)) {
            $output['category_id'] = $input['category_uuid'] !== null
                ? Category::query()->where('uuid', $input['category_uuid'])->value('id')
                : null;
            unset($output['category_uuid']);
        }

        if (array_key_exists('brand_uuid', $input)) {
            $output['brand_id'] = $input['brand_uuid'] !== null
                ? Brand::query()->where('uuid', $input['brand_uuid'])->value('id')
                : null;
            unset($output['brand_uuid']);
        }

        if (array_key_exists('unit_uuid', $input)) {
            $output['unit_id'] = Unit::query()->where('uuid', $input['unit_uuid'])->value('id');
            unset($output['unit_uuid']);
        }

        if (array_key_exists('tax_uuid', $input)) {
            $output['tax_id'] = $input['tax_uuid'] !== null
                ? Tax::query()->where('uuid', $input['tax_uuid'])->value('id')
                : null;
            unset($output['tax_uuid']);
        }

        return $output;
    }
}
