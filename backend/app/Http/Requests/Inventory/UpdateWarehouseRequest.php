<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/warehouses/{warehouse}
 *
 * branch_uuid NO es editable: mover un almacen de sucursal desplazaria el stock
 * entre sucursales sin movimiento de inventario registrado. Para reubicar hay que
 * transferir el stock y crear un almacen nuevo en la sucursal destino.
 *
 * is_active tampoco es editable: la baja pasa por
 * POST /warehouses/{warehouse}/deactivate, que valida stock pendiente y almacen
 * default. Aceptarlo aqui saltaria esas guardas.
 *
 * is_default SI es editable (divergencia deliberada respecto a UpdateBranchRequest):
 * store ya lo acepta, y sin el no habria forma de cambiar el almacen default de una
 * sucursal despues de crearlo.
 */
class UpdateWarehouseRequest extends FormRequest
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
        $warehouse = $this->route('warehouse');

        return [
            'code' => [
                'sometimes', 'required', 'string', 'max:30',
                Rule::unique('warehouses', 'code')
                    ->where('company_id', $companyId)
                    ->ignore($warehouse?->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['nullable', Rule::in([
                Warehouse::TYPE_MAIN, Warehouse::TYPE_STORAGE,
                Warehouse::TYPE_TRANSIT, Warehouse::TYPE_DAMAGED, Warehouse::TYPE_CONSIGNMENT,
            ])],
            'is_sellable' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
