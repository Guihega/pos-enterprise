<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Domain\Authorization\Permissions;
use App\Domain\Catalog\Models\Category;
use App\Domain\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Http\Requests\Catalog\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class CategoriesController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = Category::query()->with('parent');

        if ($request->boolean('roots_only')) {
            $query->whereNull('parent_id');
        }

        if ($parentUuid = $request->query('parent')) {
            $parentId = Category::query()->where('uuid', $parentUuid)->value('id');
            $query->where('parent_id', $parentId);
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $categories = $query->orderBy('sort_order')->orderBy('name')->paginate($perPage);

        return CategoryResource::collection($categories);
    }

    public function show(Request $request, Category $category): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_VIEW), 403);

        $category->load(['parent', 'children']);

        return response()->json(['data' => new CategoryResource($category)]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_CREATE), 403);

        $data = $request->validated();
        $data['uuid'] = (string) Str::uuid();
        $data['company_id'] = TenantContext::id();

        if (isset($data['parent_uuid'])) {
            $data['parent_id'] = $data['parent_uuid'] !== null
                ? Category::query()->where('uuid', $data['parent_uuid'])->value('id')
                : null;
            unset($data['parent_uuid']);
        }

        $category = Category::create($data);
        $category->load('parent');

        return response()->json(
            ['data' => new CategoryResource($category)],
            Response::HTTP_CREATED
        );
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_UPDATE), 403);

        $data = $request->validated();

        if (array_key_exists('parent_uuid', $data)) {
            $data['parent_id'] = $data['parent_uuid'] !== null
                ? Category::query()->where('uuid', $data['parent_uuid'])->value('id')
                : null;
            unset($data['parent_uuid']);
        }

        $category->update($data);
        $category->load('parent');

        return response()->json(['data' => new CategoryResource($category)]);
    }

    public function destroy(Request $request, Category $category): Response
    {
        abort_unless((bool) $request->user()?->can(Permissions::PRODUCT_DELETE), 403);

        // Productos huérfanos: la FK products.category_id tiene nullOnDelete,
        // así que borrar la categoría solo nullifica la asignación. Productos
        // sobreviven sin categoría asignada.
        $category->delete();

        return response()->noContent();
    }
}
