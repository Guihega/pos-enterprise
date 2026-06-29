<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarehouseRequest extends FormRequest
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
            'branch_uuid' => [
                'required', 'uuid',
                Rule::exists('branches', 'uuid')->where('company_id', $companyId),
            ],
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('warehouses', 'code')->where('company_id', $companyId),
            ],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['nullable', Rule::in([
                Warehouse::TYPE_MAIN, Warehouse::TYPE_STORAGE,
                Warehouse::TYPE_TRANSIT, Warehouse::TYPE_DAMAGED, Warehouse::TYPE_CONSIGNMENT,
            ])],
            'is_sellable' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
