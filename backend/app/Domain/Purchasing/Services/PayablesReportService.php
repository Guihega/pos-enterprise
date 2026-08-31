<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

use App\Domain\Purchasing\Models\SupplierInvoice;
use Illuminate\Support\Carbon;

/**
 * Reporte de antiguedad de cuentas por pagar (aging).
 *
 * Foto de HOY, sin parametros. El saldo se calcula al vuelo
 * (total - paid_amount): ninguna migracion define columna balance
 * generada (ver migracion 000046).
 */
final class PayablesReportService
{
    /**
     * Una fila por proveedor con saldo vivo, orden balance desc.
     * Universo: status != cancelled y saldo > 0 (pending y partial
     * entran, paid queda fuera). Cubetas 30/60/90 contra due_date.
     * Importes round 2 en la respuesta (18,4 es de almacen).
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, float>}
     */
    public function aging(): array
    {
        $hoy = Carbon::today();
        $cubetas = ['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];

        $facturas = SupplierInvoice::query()
            ->where('status', '!=', SupplierInvoice::STATUS_CANCELLED)
            ->whereColumn('paid_amount', '<', 'total')
            ->with('supplier')
            ->get();

        $filas = [];

        foreach ($facturas as $factura) {
            $saldo = (float) $factura->total - (float) $factura->paid_amount;
            if ($saldo <= 0) {
                continue;
            }

            $clave = $factura->supplier->uuid;
            if (! isset($filas[$clave])) {
                $filas[$clave] = array_merge([
                    'supplier_uuid' => $factura->supplier->uuid,
                    'name' => $factura->supplier->name,
                    'invoices_count' => 0,
                    'balance' => 0.0,
                ], array_fill_keys($cubetas, 0.0));
            }

            $dias = $factura->due_date->isBefore($hoy) ? (int) $factura->due_date->diffInDays($hoy) : 0;
            $cubeta = match (true) {
                $dias <= 0 => 'current',
                $dias <= 30 => 'd1_30',
                $dias <= 60 => 'd31_60',
                $dias <= 90 => 'd61_90',
                default => 'd90_plus',
            };

            $filas[$clave]['invoices_count']++;
            $filas[$clave]['balance'] += $saldo;
            $filas[$clave][$cubeta] += $saldo;
        }

        $filas = array_values($filas);
        usort($filas, fn (array $a, array $b): int => $b['balance'] <=> $a['balance']);

        $totales = array_merge(array_fill_keys($cubetas, 0.0), ['balance' => 0.0]);

        foreach ($filas as &$fila) {
            foreach ([...$cubetas, 'balance'] as $campo) {
                $totales[$campo] += $fila[$campo];
                $fila[$campo] = round($fila[$campo], 2);
            }
        }
        unset($fila);

        foreach ($totales as $campo => $valor) {
            $totales[$campo] = round($valor, 2);
        }

        return ['rows' => $filas, 'totals' => $totales];
    }
}
