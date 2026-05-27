<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;  // la autorización por permiso la hace el controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = TenantContext::id();

        return [
            // Identificación
            'sku' => [
                'required', 'string', 'max:60',
                Rule::unique('products', 'sku')->where('company_id', $companyId),
            ],
            'name' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:2000'],
            'short_description' => ['nullable', 'string', 'max:500'],

            // Clasificación: deben pertenecer al tenant en contexto.
            // Las reglas exists con where(company_id) garantizan el aislamiento
            // a nivel validación (defense in depth además de RLS).
            'category_uuid' => [
                'nullable', 'uuid',
                Rule::exists('categories', 'uuid')->where('company_id', $companyId),
            ],
            'brand_uuid' => [
                'nullable', 'uuid',
                Rule::exists('brands', 'uuid')->where('company_id', $companyId),
            ],
            'unit_uuid' => [
                'required', 'uuid',
                Rule::exists('units', 'uuid')->where('company_id', $companyId),
            ],
            'tax_uuid' => [
                'nullable', 'uuid',
                Rule::exists('taxes', 'uuid')->where('company_id', $companyId),
            ],

            // Precios
            'cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.9999'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0', 'gte:price'],
            'min_price' => ['nullable', 'numeric', 'min:0', 'lte:price'],

            // Flags
            'track_inventory' => ['nullable', 'boolean'],
            'is_sellable' => ['nullable', 'boolean'],
            'is_purchasable' => ['nullable', 'boolean'],
            'allow_decimals' => ['nullable', 'boolean'],

            // Estado
            'status' => ['nullable', Rule::in([
                Product::STATUS_DRAFT, Product::STATUS_ACTIVE, Product::STATUS_ARCHIVED,
            ])],

            // Físicos / fiscales
            'weight' => ['nullable', 'numeric', 'min:0'],
            'weight_unit' => ['nullable', 'string', 'in:g,kg,oz,lb'],
            'dimensions' => ['nullable', 'array'],
            'dimensions.length' => ['nullable', 'numeric', 'min:0'],
            'dimensions.width' => ['nullable', 'numeric', 'min:0'],
            'dimensions.height' => ['nullable', 'numeric', 'min:0'],
            'dimensions.unit' => ['nullable', 'string', 'in:cm,in,mm'],
            'tax_code' => ['nullable', 'string', 'max:30'],

            // Custom
            'custom_attributes' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
