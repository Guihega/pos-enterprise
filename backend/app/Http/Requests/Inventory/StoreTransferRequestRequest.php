<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crea una solicitud de transferencia inter-sucursal (CU-GER-003).
 * from = sucursal ORIGEN que tiene el stock y aprueba;
 * to = sucursal DESTINO que solicita. Ambas activas (patron RN-232).
 */
class StoreTransferRequestRequest extends FormRequest
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
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_uuid' => [
                'required', 'uuid',
                Rule::exists('products', 'uuid')->where('company_id', $companyId),
            ],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001', 'max:9999999.9999'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
