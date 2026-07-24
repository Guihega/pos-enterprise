<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Domain\Authorization\Permissions;
use App\Domain\Sales\Dto\CheckoutRequest;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use App\Domain\Sales\Services\SaleReturnService;
use App\Domain\Sales\Services\SalesService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sale\CancelSaleRequest;
use App\Http\Requests\Sale\StoreSaleRequest;
use App\Http\Requests\Sale\StoreSaleReturnRequest;
use App\Http\Resources\SaleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Endpoints HTTP del dominio de ventas.
 *
 * El controlador SOLO orquesta: autoriza, arma el DTO y delega en SalesService.
 * Toda la lógica de negocio (atomicidad, validación de pagos, crédito, stock,
 * folios) vive en el servicio. Las excepciones de dominio se dejan propagar y
 * el handler global las traduce a la envoltura de error estándar.
 */
final class SalesController extends Controller
{
    public function __construct(
        private readonly SalesService $sales,
    ) {}

    /**
     * Listado paginado de ventas del tenant (más recientes primero).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize(Permissions::SALE_VIEW);

        $query = Sale::query()
            ->with(['user'])
            ->withCount('items')
            ->latest('completed_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($branchUuid = $request->query('branch_uuid')) {
            $query->whereHas('branch', fn ($b) => $b->where('uuid', $branchUuid));
        }

        $perPage = min((int) $request->query('per_page', 25), 100);

        return SaleResource::collection($query->paginate($perPage));
    }

    /**
     * Detalle de una venta por UUID.
     */
    public function show(string $uuid): SaleResource
    {
        Gate::authorize(Permissions::SALE_VIEW);

        $sale = Sale::query()
            ->where('uuid', $uuid)
            ->with(['items.product', 'payments', 'taxes', 'customer', 'user', 'voider'])
            ->firstOrFail();

        return new SaleResource($sale);
    }

    /**
     * Registra una venta completa (checkout). Atómico en el servicio.
     */
    public function store(StoreSaleRequest $request): JsonResponse
    {
        Gate::authorize(Permissions::SALE_CREATE);

        $checkout = CheckoutRequest::fromArray($request->validated());

        $sale = $this->sales->checkout($checkout, $request->user());

        return (new SaleResource($sale))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Cancela una venta completada (revierte stock y caja por compensación).
     */
    /**
     * Devolucion basica (CU-CAJ-010). Autorizacion supervisor = permiso
     * SALE_REFUND (RN-083/paso 7 del CU, patron SALE_VOID). RN-086
     * estricta: siempre referida a la venta por uuid.
     */
    public function storeReturn(StoreSaleReturnRequest $request, string $uuid): JsonResponse
    {
        Gate::authorize(Permissions::SALE_REFUND);

        $sale = Sale::query()->where('uuid', $uuid)->firstOrFail();
        $validated = $request->validated();

        $return = app(SaleReturnService::class)->create(
            $sale,
            $validated['items'],
            $request->user(),
            $validated['reason'],
        );

        return response()->json(['data' => [
            'uuid' => $return->uuid,
            'total_amount' => $return->total_amount,
            'cash_refunded' => $return->cash_refunded,
            'sale_status' => $sale->fresh()->status,
        ]], 201);
    }

    public function indexReturns(Request $request, string $uuid): JsonResponse
    {
        Gate::authorize(Permissions::SALE_VIEW);

        $sale = Sale::query()->where('uuid', $uuid)->firstOrFail();
        $returns = SaleReturn::query()
            ->where('sale_id', $sale->id)
            ->with('items')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $returns]);
    }

    public function cancel(CancelSaleRequest $request, string $uuid): SaleResource
    {
        Gate::authorize(Permissions::SALE_VOID);

        $sale = Sale::query()->where('uuid', $uuid)->firstOrFail();

        $cancelled = $this->sales->cancel($sale, $request->user(), $request->validated()['reason']);

        return new SaleResource($cancelled);
    }
}
