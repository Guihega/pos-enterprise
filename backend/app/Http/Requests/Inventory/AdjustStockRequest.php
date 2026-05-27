<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustStockRequest extends FormRequest
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
            'product_uuid' => [
                'required', 'uuid',
                Rule::exists('products', 'uuid')->where('company_id', $companyId),
            ],
            'warehouse_uuid' => [
                'required', 'uuid',
                Rule::exists('warehouses', 'uuid')->where('company_id', $companyId),
            ],
            'delta' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
