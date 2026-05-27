<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Cash\Models\CashRegister;
use App\Domain\Sales\Models\SaleNumberCounter;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Generador de folios de venta.
 *
 * Folio compuesto: {BRANCH_CODE}-{REGISTER_CODE}-{SERIES}-{NNNNNN}
 *   ej. CTR-CAJA01-A-000001
 *
 * El contador vive en sale_number_counters por (branch, register, series).
 * Se usa SELECT ... FOR UPDATE para garantizar que no haya gaps por
 * concurrencia: si dos checkouts ocurren al mismo tiempo, uno espera al
 * otro y obtiene el siguiente número correlativo.
 *
 * NOTA: este componente debe llamarse SOLO dentro de una transacción que
 * envuelva la creación completa de la Sale. Si la transacción se hace
 * rollback, el counter no avanza (el SELECT FOR UPDATE no lo modifica
 * hasta el UPDATE/INSERT que sí está dentro de la transacción).
 */
final class SaleNumberGenerator
{
    /**
     * Devuelve un folio nuevo y pre-incrementa el contador.
     * DEBE llamarse dentro de DB::transaction.
     *
     * @return array{number: string, value: int}
     */
    public function next(Branch $branch, CashRegister $register, string $series = 'A'): array
    {
        // Garantizar que la fila exista (sin lock primero, idempotente).
        SaleNumberCounter::firstOrCreate(
            [
                'branch_id' => $branch->id,
                'cash_register_id' => $register->id,
                'series' => $series,
            ],
            [
                'company_id' => TenantContext::id(),
                'current_value' => 0,
            ]
        );

        // Lock pesimista y leer current_value
        /** @var SaleNumberCounter $counter */
        $counter = SaleNumberCounter::query()
            ->where('branch_id', $branch->id)
            ->where('cash_register_id', $register->id)
            ->where('series', $series)
            ->lockForUpdate()
            ->firstOrFail();

        $nextValue = (int) $counter->current_value + 1;
        $counter->current_value = $nextValue;
        $counter->save();

        $number = sprintf(
            '%s-%s-%s-%06d',
            $branch->code,
            $register->code,
            $series,
            $nextValue
        );

        return ['number' => $number, 'value' => $nextValue];
    }
}
