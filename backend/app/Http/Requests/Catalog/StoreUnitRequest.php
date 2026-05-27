<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Models\Unit;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitRequest extends FormRequest
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
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('units', 'code')->where('company_id', $companyId),
            ],
            'name' => ['required', 'string', 'max:100'],
            'plural_name' => ['nullable', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:10'],
            'category' => ['required', Rule::in([
                Unit::CATEGORY_COUNT, Unit::CATEGORY_WEIGHT, Unit::CATEGORY_VOLUME,
                Unit::CATEGORY_LENGTH, Unit::CATEGORY_OTHER,
            ])],
            'factor' => ['required', 'numeric', 'min:0.000001'],
            'is_decimal' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
