<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
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

        // El producto a actualizar viene por route binding (UUID).
        // Para excluirlo del unique check, recuperamos su id.
        /** @var Product $product */
        $product = $this->route('product');
        $productId = $product?->id;

        return [
            'sku' => [
                'sometimes', 'required', 'string', 'max:60',
                Rule::unique('products', 'sku')
                    ->where('company_id', $companyId)
                    ->ignore($productId),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:2000'],
            'short_description' => ['nullable', 'string', 'max:500'],

            'category_uuid' => [
                'nullable', 'uuid',
                Rule::exists('categories', 'uuid')->where('company_id', $companyId),
            ],
            'brand_uuid' => [
                'nullable', 'uuid',
                Rule::exists('brands', 'uuid')->where('company_id', $companyId),
            ],
            'unit_uuid' => [
                'sometimes', 'required', 'uuid',
                Rule::exists('units', 'uuid')->where('company_id', $companyId),
            ],
            'tax_uuid' => [
                'nullable', 'uuid',
                Rule::exists('taxes', 'uuid')->where('company_id', $companyId),
            ],

            'cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.9999'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'min_price' => ['nullable', 'numeric', 'min:0'],

            'track_inventory' => ['nullable', 'boolean'],
            'is_sellable' => ['nullable', 'boolean'],
            'is_purchasable' => ['nullable', 'boolean'],
            'allow_decimals' => ['nullable', 'boolean'],

            'status' => ['nullable', Rule::in([
                Product::STATUS_DRAFT, Product::STATUS_ACTIVE, Product::STATUS_ARCHIVED,
            ])],

            'weight' => ['nullable', 'numeric', 'min:0'],
            'weight_unit' => ['nullable', 'string', 'in:g,kg,oz,lb'],
            'dimensions' => ['nullable', 'array'],
            'tax_code' => ['nullable', 'string', 'max:30'],

            'custom_attributes' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
