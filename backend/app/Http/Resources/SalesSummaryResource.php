<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resumen de ventas de un dia. El recurso recibe el array ya calculado
 * por SalesSummaryService::forDate() y lo expone con shape estable.
 *
 * @property-read array<string, mixed> $resource
 */
class SalesSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $summary */
        $summary = $this->resource;

        return [
            'date' => $summary['date'],
            'branch' => $summary['branch'],
            'totals' => $summary['totals'],
            'payments' => $summary['payments'],
            'top_products' => $summary['top_products'],
        ];
    }
}
