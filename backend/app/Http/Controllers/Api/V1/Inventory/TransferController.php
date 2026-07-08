<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Domain\Authorization\Permissions;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\InvalidTransferTransitionException;
use App\Domain\Inventory\Models\Transfer;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\TransferService;
use App\Domain\Tenancy\Models\Branch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreTransferRequest;
use App\Http\Resources\TransferResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TransferController extends Controller
{
    public function __construct(
        private readonly TransferService $service,
    ) {}

    /**
     * GET /api/v1/transfers
     *
     * Filtros: status, from_branch, to_branch.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::TRANSFERS_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = Transfer::query()->with(['fromBranch', 'toBranch', 'items.product']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($fromUuid = $request->query('from_branch')) {
            $id = Branch::query()->where('uuid', $fromUuid)->value('id');
            $query->where('from_branch_id', $id);
        }

        if ($toUuid = $request->query('to_branch')) {
            $id = Branch::query()->where('uuid', $toUuid)->value('id');
            $query->where('to_branch_id', $id);
        }

        return TransferResource::collection(
            $query->orderByDesc('created_at')->paginate($perPage)
        );
    }

    /**
     * GET /api/v1/transfers/{transfer}
     */
    public function show(Request $request, Transfer $transfer): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::TRANSFERS_VIEW), 403);

        $transfer->load(['fromBranch', 'toBranch', 'fromWarehouse', 'toWarehouse', 'items.product']);

        return response()->json(['data' => new TransferResource($transfer)]);
    }

    /**
     * POST /api/v1/transfers
     */
    public function store(StoreTransferRequest $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::TRANSFERS_CREATE), 403);

        $validated = $request->validated();

        $fromBranch = Branch::query()->where('uuid', $validated['from_branch_uuid'])->firstOrFail();
        $toBranch = Branch::query()->where('uuid', $validated['to_branch_uuid'])->firstOrFail();

        $fromWarehouse = isset($validated['from_warehouse_uuid'])
            ? Warehouse::query()->where('uuid', $validated['from_warehouse_uuid'])->firstOrFail()
            : null;
        $toWarehouse = isset($validated['to_warehouse_uuid'])
            ? Warehouse::query()->where('uuid', $validated['to_warehouse_uuid'])->firstOrFail()
            : null;

        $lines = [];
        foreach ($validated['items'] as $item) {
            $lines[] = [
                'product' => Product::query()->where('uuid', $item['product_uuid'])->firstOrFail(),
                'quantity' => (float) $item['quantity'],
                'unit_cost' => isset($item['unit_cost']) ? (float) $item['unit_cost'] : 0.0,
                'notes' => $item['notes'] ?? null,
            ];
        }

        $transfer = $this->service->create(
            fromBranch: $fromBranch,
            toBranch: $toBranch,
            lines: $lines,
            fromWarehouse: $fromWarehouse,
            toWarehouse: $toWarehouse,
            transportMethod: $validated['transport_method'] ?? null,
            transportReference: $validated['transport_reference'] ?? null,
            notes: $validated['notes'] ?? null,
        );

        $transfer->load(['fromBranch', 'toBranch', 'items.product']);

        return response()->json(
            ['data' => new TransferResource($transfer)],
            Response::HTTP_CREATED
        );
    }

    /**
     * POST /api/v1/transfers/{transfer}/send
     */
    public function send(Request $request, Transfer $transfer): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::TRANSFERS_SEND), 403);

        try {
            $transfer = $this->service->send($transfer, $request->user()->id);
        } catch (InvalidTransferTransitionException $e) {
            return $this->conflict('INVALID_TRANSITION', $e->getMessage());
        } catch (InsufficientStockException $e) {
            return $this->conflict('INSUFFICIENT_STOCK', $e->getMessage());
        }

        $transfer->load(['fromBranch', 'toBranch', 'items.product']);

        return response()->json(['data' => new TransferResource($transfer)]);
    }

    /**
     * POST /api/v1/transfers/{transfer}/receive
     *
     * Body opcional: items[] = [{ product_uuid, received }]. Las lineas no
     * incluidas se asumen recibidas completas. RN-049: la merma genera ajuste
     * transfer_loss automatico en el service.
     */
    public function receive(Request $request, Transfer $transfer): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::TRANSFERS_RECEIVE), 403);

        $transfer->load(['items.product']);

        // Traducir product_uuid -> item_id para el contrato del service.
        $receivedByItemId = [];
        foreach ((array) $request->input('items', []) as $line) {
            if (! isset($line['product_uuid']) || ! array_key_exists('received', $line)) {
                continue;
            }
            $item = $transfer->items->first(
                fn ($it) => $it->product !== null && $it->product->uuid === $line['product_uuid']
            );
            if ($item !== null) {
                $receivedByItemId[$item->id] = (float) $line['received'];
            }
        }

        try {
            $transfer = $this->service->receive($transfer, $receivedByItemId, $request->user()->id);
        } catch (InvalidTransferTransitionException $e) {
            return $this->conflict('INVALID_TRANSITION', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => ['code' => 'INVALID_RECEIVED_QUANTITY', 'message' => $e->getMessage()],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $transfer->load(['fromBranch', 'toBranch', 'items.product']);

        return response()->json(['data' => new TransferResource($transfer)]);
    }

    /**
     * POST /api/v1/transfers/{transfer}/cancel
     */
    public function cancel(Request $request, Transfer $transfer): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::TRANSFERS_CANCEL), 403);

        $reason = $request->input('reason');

        try {
            $transfer = $this->service->cancel($transfer, $reason, $request->user()->id);
        } catch (InvalidTransferTransitionException $e) {
            return $this->conflict('INVALID_TRANSITION', $e->getMessage());
        }

        $transfer->load(['fromBranch', 'toBranch', 'items.product']);

        return response()->json(['data' => new TransferResource($transfer)]);
    }

    private function conflict(string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
        ], Response::HTTP_CONFLICT);
    }
}
