<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Cash;

use App\Domain\Authorization\Permissions;
use App\Domain\Cash\Exceptions\CashSessionNotOpenException;
use App\Domain\Cash\Models\CashSession;
use App\Domain\Cash\Services\CashService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cash\RecordMovementRequest;
use App\Http\Resources\CashMovementResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CashMovementsController extends Controller
{
    public function __construct(
        private readonly CashService $service,
    ) {}

    /**
     * GET /api/v1/cash/sessions/{session_uuid}/movements
     */
    public function index(Request $request, CashSession $session): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::CASH_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);

        $movements = $session->movements()
            ->with('user')
            ->orderByDesc('movement_at')
            ->paginate($perPage);

        return CashMovementResource::collection($movements);
    }

    /**
     * POST /api/v1/cash/sessions/{session_uuid}/movements
     */
    public function store(RecordMovementRequest $request, CashSession $session): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::CASH_MOVEMENT), 403);

        $validated = $request->validated();

        try {
            $movement = $this->service->addMovement(
                session: $session,
                user: $request->user(),
                type: $validated['type'],
                amount: (float) $validated['amount'],
                reason: $validated['reason'],
                reference: $validated['reference'] ?? null,
                signOverride: isset($validated['sign']) ? (int) $validated['sign'] : null,
            );
        } catch (CashSessionNotOpenException $e) {
            return response()->json([
                'error' => [
                    'code' => 'SESSION_NOT_OPEN',
                    'message' => $e->getMessage(),
                ],
            ], Response::HTTP_CONFLICT);
        }

        $movement->load('user');

        return response()->json(
            ['data' => new CashMovementResource($movement)],
            Response::HTTP_CREATED
        );
    }
}
