<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
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

        return [
            'name' => ['required', 'string', 'max:200'],
            'slug' => [
                'required', 'string', 'max:200', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('categories', 'slug')->where('company_id', $companyId),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
            'parent_uuid' => [
                'nullable', 'uuid',
                Rule::exists('categories', 'uuid')->where('company_id', $companyId),
            ],
        ];
    }
}
