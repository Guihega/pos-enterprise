<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Models\Tax;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaxRequest extends FormRequest
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

        /** @var Tax|null $tax */
        $tax = $this->route('tax');
        $taxId = $tax?->id;

        return [
            'code' => [
                'sometimes', 'required', 'string', 'max:30',
                Rule::unique('taxes', 'code')
                    ->where('company_id', $companyId)
                    ->ignore($taxId),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'rate' => ['sometimes', 'required', 'numeric', 'min:0', 'max:1'],
            'type' => ['sometimes', 'required', Rule::in([
                Tax::TYPE_VAT, Tax::TYPE_SALES_TAX, Tax::TYPE_EXCISE,
                Tax::TYPE_WITHHOLDING, Tax::TYPE_OTHER,
            ])],
            'is_inclusive' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
