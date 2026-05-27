<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBrandRequest extends FormRequest
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

        /** @var Brand|null $brand */
        $brand = $this->route('brand');
        $brandId = $brand?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'slug' => [
                'sometimes', 'required', 'string', 'max:200', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('brands', 'slug')
                    ->where('company_id', $companyId)
                    ->ignore($brandId),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'logo_url' => ['nullable', 'string', 'max:500', 'url'],
            'website' => ['nullable', 'string', 'max:200', 'url'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
