<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Cash\Models\CashRegister;
use App\Domain\Sales\Models\SaleNumberCounter;
use App\Domain\Sales\Models\SaleNumberRange;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Reserva rangos de folios para dispositivos PWA.
 *
 * ADR-0009: usa el mismo sale_number_counters como techo global y asigna
 * bloques disjuntos. El lock pesimista garantiza que dos solicitudes
 * simultaneas obtengan rangos que no se solapan.
 */
final class FolioRangeService
{
    private const DEFAULT_SIZE = 50;

    private const MAX_SIZE = 500;

    /**
     * Reserva (o devuelve el activo) un rango de folios para el dispositivo.
     *
     * @return array{range_start: int, range_end: int, series: string, device_id: string}
     */
    public function reserve(
        CashRegister $register,
        string $series,
        string $deviceId,
        int $size = self::DEFAULT_SIZE,
    ): array {
        $size = min(max(1, $size), self::MAX_SIZE);

        return DB::transaction(function () use ($register, $series, $deviceId, $size): array {
            // Si ya existe un rango activo para este dispositivo, devolverlo.
            $existing = SaleNumberRange::query()
                ->where('cash_register_id', $register->id)
                ->where('series', $series)
                ->where('device_id', $deviceId)
                ->whereNull('exhausted_at')
                ->first();

            if ($existing) {
                return [
                    'range_start' => $existing->range_start,
                    'range_end' => $existing->range_end,
                    'series' => $existing->series,
                    'device_id' => $existing->device_id,
                ];
            }

            // Lock pesimista sobre el contador global (igual que SaleNumberGenerator).
            SaleNumberCounter::firstOrCreate(
                [
                    'branch_id' => $register->branch_id,
                    'cash_register_id' => $register->id,
                    'series' => $series,
                ],
                [
                    'company_id' => TenantContext::id(),
                    'current_value' => 0,
                ]
            );

            /** @var SaleNumberCounter $counter */
            $counter = SaleNumberCounter::query()
                ->where('branch_id', $register->branch_id)
                ->where('cash_register_id', $register->id)
                ->where('series', $series)
                ->lockForUpdate()
                ->firstOrFail();

            $rangeStart = (int) $counter->current_value + 1;
            $rangeEnd = $rangeStart + $size - 1;

            $counter->current_value = $rangeEnd;
            $counter->save();

            SaleNumberRange::create([
                'company_id' => TenantContext::id(),
                'cash_register_id' => $register->id,
                'series' => $series,
                'device_id' => $deviceId,
                'range_start' => $rangeStart,
                'range_end' => $rangeEnd,
                'exhausted_at' => null,
            ]);

            return [
                'range_start' => $rangeStart,
                'range_end' => $rangeEnd,
                'series' => $series,
                'device_id' => $deviceId,
            ];
        });
    }

    /**
     * Valida y consume un folio del rango activo del dispositivo (ADR-0009
     * paso 3). Retorna false si no hay rango activo o el folio cae fuera de
     * [range_start, range_end]: folio invalido no es excepcional (EX-118),
     * es la senal para que el caller haga fallback al generador central.
     *
     * Paso 4 del ADR adaptado: la migracion no incluye next_value (el
     * cliente lo consume localmente, ver docblock de la migracion), por lo
     * que el rango se marca exhausted_at cuando se consume range_end, no
     * por comparacion contra next_value.
     *
     * "Folio ya usado dentro del rango" no se valida aqui: el unique
     * (company_id, number) de sales lo garantiza en BD (el insert fallaria
     * y el caller trata la venta como error, sin duplicado posible).
     *
     * DEBE llamarse dentro de la transaccion que crea la Sale (mismo
     * requisito que SaleNumberGenerator::next).
     */
    public function consume(
        CashRegister $register,
        string $series,
        string $deviceId,
        int $numberValue,
    ): bool {
        /** @var SaleNumberRange|null $range */
        $range = SaleNumberRange::query()
            ->where('cash_register_id', $register->id)
            ->where('series', $series)
            ->where('device_id', $deviceId)
            ->whereNull('exhausted_at')
            ->lockForUpdate()
            ->first();

        if ($range === null) {
            return false;
        }

        if ($numberValue < $range->range_start || $numberValue > $range->range_end) {
            return false;
        }

        if ($numberValue === (int) $range->range_end) {
            $range->update(['exhausted_at' => now()]);
        }

        return true;
    }
}
