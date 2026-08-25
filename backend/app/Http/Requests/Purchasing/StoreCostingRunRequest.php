<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de corrida de costeo con sus lineas (DISENO_COSTEO.md).
 *
 * waste_pct y margin_pct son FRACCIONES (0.05 = 5%); el tope 0.99 del
 * lado de forma refleja el limite duro del servicio (divisores 1-x).
 *
 * Valida FORMA, no existencia: el servicio resuelve product_uuid por
 * whereIn y devuelve 422 con la lista de faltantes.
 */
class StoreCostingRunRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'freight_total' => ['nullable', 'numeric', 'min:0'],
            'other_costs_total' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_uuid' => ['required', 'uuid'],
            'lines.*.pack_description' => ['required', 'string', 'max:60'],
            'lines.*.pack_price' => ['required', 'numeric', 'min:0'],
            'lines.*.units_per_pack' => ['required', 'numeric', 'gt:0'],
            'lines.*.packs_qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.extra_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.waste_pct' => ['nullable', 'numeric', 'min:0', 'max:0.99'],
            'lines.*.margin_pct' => ['nullable', 'numeric', 'min:0'],
            'lines.*.margin_type' => ['nullable', 'string', 'in:markup,on_price'],
        ];
    }
}
