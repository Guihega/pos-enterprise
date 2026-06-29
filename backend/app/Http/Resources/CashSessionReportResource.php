<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Corte de caja (X/Z) de una sesion. El recurso recibe el array ya
 * calculado por CashSessionReportService::build() y lo expone con
 * shape estable.
 *
 * - session: misma forma que CashSessionResource (anidado).
 * - sales: ventas completadas de la sesion (count, total_amount).
 * - payments: desglose por metodo de pago.
 * - movements: desglose de cash_movements por tipo.
 * - cash: apertura, delta, esperado, contado, diferencia.
 *
 * @property-read array<string, mixed> $resource
 */
class CashSessionReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $report */
        $report = $this->resource;

        return [
            'session' => new CashSessionResource($report['session']),
            'sales' => $report['sales'],
            'payments' => $report['payments'],
            'movements' => $report['movements'],
            'cash' => $report['cash'],
        ];
    }
}
