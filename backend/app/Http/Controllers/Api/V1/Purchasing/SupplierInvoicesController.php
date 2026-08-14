<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Domain\Authorization\Permissions;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierInvoice;
use App\Domain\Purchasing\Services\SupplierInvoiceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\MatchSupplierInvoiceRequest;
use App\Http\Requests\Purchasing\PaySupplierInvoiceRequest;
use App\Http\Requests\Purchasing\StoreSupplierInvoiceRequest;
use App\Http\Resources\SupplierInvoiceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Facturas de proveedor y cuentas por pagar (4.1.5, maestro 29.7).
 *
 * GET /suppliers/{uuid}/balance vive aqui y no en SuppliersController porque
 * el saldo es una AGREGACION DE FACTURAS, no un atributo del proveedor: la
 * columna balance que el maestro define en suppliers no existe en la 000046.
 * Ademas SuppliersController no inyecta servicio y darselo por un solo
 * endpoint es el escenario de la leccion 19. La ruta publica no cambia.
 */
class SupplierInvoicesController extends Controller
{
    public function __construct(private readonly SupplierInvoiceService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::SUPPLIER_INVOICE_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);
        $query = SupplierInvoice::query()->with(['supplier', 'purchaseOrder']);

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return SupplierInvoiceResource::collection(
            $query->orderByDesc('id')->paginate($perPage)
        );
    }

    public function store(StoreSupplierInvoiceRequest $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::SUPPLIER_INVOICE_CREATE), 403);

        $data = $request->validated();
        $invoice = $this->service->create(
            $data['supplier_uuid'],
            $data['folio'],
            $data['issue_date'],
            $data['due_date'],
            (float) $data['subtotal'],
            (float) $data['tax_total'],
            $data['cfdi_uuid'] ?? null,
            $data['cfdi_xml_url'] ?? null,
            $data['payment_method'] ?? null,
            $data['notes'] ?? null,
        );

        return response()->json(
            ['data' => new SupplierInvoiceResource($invoice->load(['supplier', 'purchaseOrder']))],
            Response::HTTP_CREATED
        );
    }

    public function match(MatchSupplierInvoiceRequest $request, SupplierInvoice $supplierInvoice): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::SUPPLIER_INVOICE_CREATE), 403);

        $data = $request->validated();

        return $this->respond($this->service->match($supplierInvoice, $data['purchase_order_uuid']));
    }

    public function pay(PaySupplierInvoiceRequest $request, SupplierInvoice $supplierInvoice): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::SUPPLIER_INVOICE_PAY), 403);

        $data = $request->validated();

        return $this->respond($this->service->pay(
            $supplierInvoice,
            (float) $data['amount'],
            $data['payment_date'],
            $data['method'],
            $request->user(),
            $data['reference'] ?? null,
            $data['notes'] ?? null,
        ));
    }

    /**
     * Saldo del proveedor: suma de lo pendiente en sus facturas vivas.
     *
     * Las canceladas quedan fuera. balance() del modelo es total - paid_amount;
     * aqui se agrega por proveedor.
     */
    public function balance(Request $request, Supplier $supplier): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::SUPPLIER_INVOICE_VIEW), 403);

        $facturas = SupplierInvoice::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', '!=', SupplierInvoice::STATUS_CANCELLED)
            ->get();

        $total = round((float) $facturas->sum('total'), 4);
        $pagado = round((float) $facturas->sum('paid_amount'), 4);

        return response()->json(['data' => [
            'supplier_uuid' => $supplier->uuid,
            'invoices_count' => $facturas->count(),
            'total' => $total,
            'paid_amount' => $pagado,
            'balance' => round($total - $pagado, 4),
        ]]);
    }

    private function respond(SupplierInvoice $invoice): JsonResponse
    {
        return response()->json([
            'data' => new SupplierInvoiceResource(
                $invoice->load(['supplier', 'purchaseOrder', 'payments'])
            ),
        ]);
    }
}
