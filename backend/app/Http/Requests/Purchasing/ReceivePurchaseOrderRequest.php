<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Recepcion de mercancia contra una OC aprobada.
 *
 * Valida forma, no existencia: la resolucion de uuid a modelo y el
 * aislamiento por tenant viven en el controller, igual que en
 * StorePurchaseOrderRequest.
 *
 * warehouse_uuid es obligatorio y no se deriva de la sucursal: el indice
 * unique parcial garantiza como maximo un almacen por defecto por branch,
 * pero no que exista, y la mercancia entra a un almacen concreto que
 * decide quien recibe.
 */
class ReceivePurchaseOrderRequest extends FormRequest
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
            'warehouse_uuid' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_uuid' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
