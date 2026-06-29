<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reports;

use App\Domain\Authorization\Permissions;
use App\Domain\Sales\Services\SalesSummaryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\SalesSummaryRequest;
use App\Http\Resources\SalesSummaryResource;
use Illuminate\Support\Facades\Gate;

/**
 * Endpoints de reportes de lectura.
 *
 * El controlador solo orquesta: autoriza, resuelve filtros y delega el
 * calculo en el servicio de dominio. Sin logica de negocio aqui.
 */
final class ReportsController extends Controller
{
    public function __construct(
        private readonly SalesSummaryService $summary,
    ) {}

    /**
     * Resumen de ventas de un dia (status completed). Alimenta el Reporte
     * de ventas del dia y el Dashboard simple.
     */
    public function salesSummary(SalesSummaryRequest $request): SalesSummaryResource
    {
        Gate::authorize(Permissions::REPORT_SALES);

        $data = $this->summary->forDate(
            $request->resolvedDate(),
            $request->branchUuid(),
        );

        return new SalesSummaryResource($data);
    }
}
