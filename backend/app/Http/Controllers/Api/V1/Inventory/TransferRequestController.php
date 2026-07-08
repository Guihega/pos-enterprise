<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Domain\Authorization\Permissions;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Exceptions\InvalidTransferRequestTransitionException;
use App\Domain\Inventory\Models\TransferRequest;
use App\Domain\Inventory\Services\TransferRequestService;
use App\Domain\Tenancy\Models\Branch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\RejectTransferRequestRequest;
use App\Http\Requests\Inventory\StoreTransferRequestRequest;
use App\Http\Resources\TransferRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TransferRequestController extends Controller
{
    public function __construct(
        private readonly TransferRequestService $service,
    ) {}

    /**
     * GET /api/v1/transfer-requests
     *
     * Filtros: status, from_branch, to_branch.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::TRANSFER_REQUESTS_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = TransferRequest::query()->with(['fromBranch', 'toBranch', 'requester', 'items.product']);

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

        return TransferRequestResource::collection(
            $query->orderByDesc('created_at')->paginate($perPage)
        );
    }

    /**
     * GET /api/v1/transfer-requests/{transferRequest}
     */
    public function show(Request $request, TransferRequest $transferRequest): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::TRANSFER_REQUESTS_VIEW), 403);

        $transferRequest->load(['fromBranch', 'toBranch', 'requester', 'resolver', 'transfer', 'items.product']);

        return response()->json(['data' => new TransferRequestResource($transferRequest)]);
    }

    /**
     * POST /api/v1/transfer-requests
     */
    public function store(StoreTransferRequestRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless((bool) $user->can(Permissions::TRANSFER_REQUESTS_CREATE), 403);

        $validated = $request->validated();

        $fromBranch = Branch::query()->where('uuid', $validated['from_branch_uuid'])->firstOrFail();
        $toBranch = Branch::query()->where('uuid', $validated['to_branch_uuid'])->firstOrFail();

        $lines = [];
        foreach ($validated['items'] as $item) {
            $lines[] = [
                'product' => Product::query()->where('uuid', $item['product_uuid'])->firstOrFail(),
                'quantity' => (float) $item['quantity'],
                'notes' => $item['notes'] ?? null,
            ];
        }

        $transferRequest = $this->service->create(
            $fromBranch,
            $toBranch,
            $lines,
            $user,
            $validated['notes'] ?? null,
        );

        $transferRequest->load(['fromBranch', 'toBranch', 'requester', 'items.product']);

        return response()->json(
            ['data' => new TransferRequestResource($transferRequest)],
            Response::HTTP_CREATED
        );
    }

    /**
     * POST /api/v1/transfer-requests/{transferRequest}/approve
     */
    public function approve(Request $request, TransferRequest $transferRequest): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless((bool) $user->can(Permissions::TRANSFER_REQUESTS_APPROVE), 403);

        try {
            $transferRequest = $this->service->approve($transferRequest, $user);
        } catch (InvalidTransferRequestTransitionException $e) {
            return $this->conflict('INVALID_TRANSITION', $e->getMessage());
        }

        $transferRequest->load(['fromBranch', 'toBranch', 'requester', 'resolver', 'transfer', 'items.product']);

        return response()->json(['data' => new TransferRequestResource($transferRequest)]);
    }

    /**
     * POST /api/v1/transfer-requests/{transferRequest}/reject
     */
    public function reject(RejectTransferRequestRequest $request, TransferRequest $transferRequest): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless((bool) $user->can(Permissions::TRANSFER_REQUESTS_APPROVE), 403);

        try {
            $transferRequest = $this->service->reject($transferRequest, $user, $request->validated()['reason']);
        } catch (InvalidTransferRequestTransitionException $e) {
            return $this->conflict('INVALID_TRANSITION', $e->getMessage());
        }

        $transferRequest->load(['fromBranch', 'toBranch', 'requester', 'resolver', 'items.product']);

        return response()->json(['data' => new TransferRequestResource($transferRequest)]);
    }

    /**
     * POST /api/v1/transfer-requests/{transferRequest}/cancel
     *
     * Solo el solicitante puede retirar su solicitud (ownership).
     */
    public function cancel(Request $request, TransferRequest $transferRequest): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($transferRequest->requested_by_user_id === $user->id, 403);

        try {
            $transferRequest = $this->service->cancel($transferRequest, $user);
        } catch (InvalidTransferRequestTransitionException $e) {
            return $this->conflict('INVALID_TRANSITION', $e->getMessage());
        }

        $transferRequest->load(['fromBranch', 'toBranch', 'requester', 'items.product']);

        return response()->json(['data' => new TransferRequestResource($transferRequest)]);
    }

    private function conflict(string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
        ], Response::HTTP_CONFLICT);
    }
}
