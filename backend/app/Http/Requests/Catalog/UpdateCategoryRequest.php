<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Models\Category;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = TenantContext::id();

        /** @var Category|null $category */
        $category = $this->route('category');
        $categoryId = $category?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'slug' => [
                'sometimes', 'required', 'string', 'max:200', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('categories', 'slug')
                    ->where('company_id', $companyId)
                    ->ignore($categoryId),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
            'parent_uuid' => [
                'nullable', 'uuid',
                Rule::exists('categories', 'uuid')->where('company_id', $companyId),
                // Custom: no permitir que el parent sea ESTA misma categoría ni un descendiente.
                function (string $attribute, $value, Closure $fail) use ($category): void {
                    if ($value === null || $category === null) {
                        return;
                    }

                    /** @var Category|null $parent */
                    $parent = Category::query()->where('uuid', $value)->first();
                    if ($parent === null) {
                        return;
                    }

                    if ($parent->id === $category->id) {
                        $fail('Una categoría no puede ser su propio padre.');

                        return;
                    }

                    // Caminar la cadena hacia arriba del padre candidato. Si en algún
                    // nivel encontramos a $category, hay ciclo.
                    $current = $parent;
                    while ($current !== null) {
                        if ($current->parent_id === $category->id) {
                            $fail('Esta categoría es ancestro del padre seleccionado (ciclo).');

                            return;
                        }
                        $current = $current->parent;
                    }
                },
            ],
        ];
    }
}
