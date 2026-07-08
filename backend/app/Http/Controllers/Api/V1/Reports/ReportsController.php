<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reports;

use App\Domain\Authorization\Permissions;
use App\Domain\Reports\Services\ConsolidatedReportService;
use App\Domain\Sales\Services\SalesSummaryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\SalesSummaryRequest;
use App\Http\Resources\SalesSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        private readonly ConsolidatedReportService $consolidated,
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

    /**
     * GET /reports/consolidated/sales-daily
     *
     * Ventas globales por dia del tenant (doc maestro 46.6).
     */
    public function consolidatedSalesDaily(Request $request): JsonResponse
    {
        Gate::authorize(Permissions::REPORT_CONSOLIDATED);

        $data = $this->consolidated->salesDaily(
            $request->query('from'),
            $request->query('to'),
        );

        return response()->json(['data' => $data]);
    }

    /**
     * GET /reports/consolidated/inventory
     *
     * Stock total por producto cross-sucursal (doc maestro 46.6).
     */
    public function consolidatedInventory(Request $request): JsonResponse
    {
        Gate::authorize(Permissions::REPORT_CONSOLIDATED);

        return response()->json(['data' => $this->consolidated->inventoryGlobal()]);
    }

    /**
     * GET /reports/consolidated/branch-comparison
     *
     * KPIs comparativos por sucursal (doc maestro 46.6).
     */
    public function consolidatedBranchComparison(Request $request): JsonResponse
    {
        Gate::authorize(Permissions::REPORT_CONSOLIDATED);

        return response()->json(['data' => $this->consolidated->branchComparison()]);
    }
}
