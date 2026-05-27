<?php

declare(strict_types=1);

namespace App\Http\Requests\Cash;

use App\Domain\Cash\Models\CashMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Solo permite movimientos manuales: cash_in, cash_out, adjustment.
 * Los movimientos sale_cash, refund_cash, sale_other y tip los registra
 * el módulo de ventas (Bloque 1.7), no este endpoint.
 */
class RecordMovementRequest extends FormRequest
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
            'type' => ['required', Rule::in([
                CashMovement::TYPE_CASH_IN,
                CashMovement::TYPE_CASH_OUT,
                CashMovement::TYPE_ADJUSTMENT,
            ])],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
            // Para 'adjustment' es obligatorio especificar +1 o -1
            'sign' => ['nullable', 'integer', Rule::in([-1, 1])],
        ];
    }

    /**
     * Validación contextual: adjustment requiere sign.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator): void {
            if (
                $this->input('type') === CashMovement::TYPE_ADJUSTMENT
                && $this->input('sign') === null
            ) {
                $validator->errors()->add(
                    'sign',
                    'El campo sign (+1 o -1) es obligatorio para movimientos tipo adjustment.'
                );
            }
        });
    }
}
