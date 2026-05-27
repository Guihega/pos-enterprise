<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferStockRequest extends FormRequest
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
            'from_warehouse_uuid' => [
                'required', 'uuid',
                Rule::exists('warehouses', 'uuid')->where('company_id', $companyId),
            ],
            'to_warehouse_uuid' => [
                'required', 'uuid', 'different:from_warehouse_uuid',
                Rule::exists('warehouses', 'uuid')->where('company_id', $companyId),
            ],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
