<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crea una transferencia inter-sucursal en estado draft (doc maestro 46.4,
 * CU-ALM-005). RN-232: ambas sucursales deben estar activas (is_active),
 * validado via Rule::exists con where is_active=true => una inactiva da 422.
 */
class StoreTransferRequest extends FormRequest
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
            'from_branch_uuid' => [
                'required', 'uuid',
                Rule::exists('branches', 'uuid')
                    ->where('company_id', $companyId)
                    ->where('is_active', true),
            ],
            'to_branch_uuid' => [
                'required', 'uuid', 'different:from_branch_uuid',
                Rule::exists('branches', 'uuid')
                    ->where('company_id', $companyId)
                    ->where('is_active', true),
            ],
            'from_warehouse_uuid' => [
                'nullable', 'uuid',
                Rule::exists('warehouses', 'uuid')->where('company_id', $companyId),
            ],
            'to_warehouse_uuid' => [
                'nullable', 'uuid',
                Rule::exists('warehouses', 'uuid')->where('company_id', $companyId),
            ],
            'transport_method' => ['nullable', 'string', 'max:60'],
            'transport_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_uuid' => [
                'required', 'uuid',
                Rule::exists('products', 'uuid')->where('company_id', $companyId),
            ],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001', 'max:9999999.9999'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999.9999'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
