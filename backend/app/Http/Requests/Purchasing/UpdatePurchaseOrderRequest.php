<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Actualizacion de una orden de compra en draft (maestro 29.7).
 *
 * PATCH parcial: los tres campos son opcionales. Si viene items se
 * reemplazan TODAS las lineas y se recalculan los totales; si no viene,
 * las lineas y los totales quedan intactos.
 *
 * Valida forma, no existencia, igual que StorePurchaseOrderRequest. El
 * estado draft lo exige el servicio (409), no este request: es una regla
 * de dominio y no de forma.
 *
 * supplier_uuid y branch_uuid NO son modificables: cambiar de proveedor o
 * de sucursal es otra orden, y branch_id determina el almacen de entrada.
 */
class UpdatePurchaseOrderRequest extends FormRequest
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
        return [
            'expected_date' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.product_uuid' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }
}
