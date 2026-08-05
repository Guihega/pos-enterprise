<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Domain\Authorization\Permissions;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Services\PurchaseOrderService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\ReceivePurchaseOrderRequest;
use App\Http\Requests\Purchasing\StorePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Ordenes de compra (4.1.5, maestro 29.7).
 *
 * Entrega actual: crear, listar, ver, submit, approve, cancel, receive.
 * DIFERIDO: PATCH de la OC en draft. La recepcion manual sin OC
 * (purchase-receipts, 3 endpoints de 29.7) va en su propia entrega: el
 * maestro no define su tabla.
 *
 * Las transiciones invalidas las lanza el service y las traduce a 409
 * PURCHASE_ORDER_TRANSITION el handler central de bootstrap/app.php.
 */
class PurchaseOrdersController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::PURCHASE_ORDER_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);
        $query = PurchaseOrder::query()->with(['supplier', 'branch']);

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return PurchaseOrderResource::collection(
            $query->orderByDesc('id')->paginate($perPage)
        );
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PURCHASE_ORDER_VIEW), 403);

        return response()->json([
            'data' => new PurchaseOrderResource($purchaseOrder->load(['items', 'supplier', 'branch'])),
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PURCHASE_ORDER_CREATE), 403);

        $data = $request->validated();

        $order = $this->service->create(
            $data['supplier_uuid'],
            $data['branch_uuid'],
            $data['items'],
            $request->user(),
            $data['expected_date'] ?? null,
            $data['notes'] ?? null,
        );

        return response()->json(
            ['data' => new PurchaseOrderResource($order->load(['items', 'supplier', 'branch']))],
            Response::HTTP_CREATED
        );
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PURCHASE_ORDER_CREATE), 403);

        return $this->respond($this->service->submit($purchaseOrder));
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PURCHASE_ORDER_APPROVE), 403);

        return $this->respond($this->service->approve($purchaseOrder, $request->user()));
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PURCHASE_ORDER_CREATE), 403);

        return $this->respond($this->service->cancel($purchaseOrder));
    }

    public function receive(ReceivePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::PURCHASE_ORDER_RECEIVE), 403);

        $data = $request->validated();

        return $this->respond($this->service->receive(
            $purchaseOrder,
            $data['warehouse_uuid'],
            $data['items'],
            $request->user(),
        ));
    }

    private function respond(PurchaseOrder $order): JsonResponse
    {
        return response()->json([
            'data' => new PurchaseOrderResource($order->load(['items', 'supplier', 'branch'])),
        ]);
    }
}
