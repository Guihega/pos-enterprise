<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Cash;

use App\Domain\Authorization\Permissions;
use App\Domain\Cash\Exceptions\CashSessionAlreadyOpenException;
use App\Domain\Cash\Exceptions\CashSessionNotOpenException;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Models\CashSession;
use App\Domain\Cash\Services\CashService;
use App\Domain\Cash\Services\CashSessionReportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cash\CloseSessionRequest;
use App\Http\Requests\Cash\OpenSessionRequest;
use App\Http\Resources\CashSessionReportResource;
use App\Http\Resources\CashSessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CashSessionsController extends Controller
{
    public function __construct(
        private readonly CashService $service,
        private readonly CashSessionReportService $report,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::CASH_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = CashSession::query()->with(['register', 'openedBy', 'closedBy']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($registerUuid = $request->query('register')) {
            $rId = CashRegister::query()->where('uuid', $registerUuid)->value('id');
            $query->where('cash_register_id', $rId);
        }

        return CashSessionResource::collection(
            $query->orderByDesc('opened_at')->paginate($perPage)
        );
    }

    public function show(Request $request, CashSession $session): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::CASH_VIEW), 403);

        $session->load(['register', 'openedBy', 'closedBy'])->loadCount('movements');

        return response()->json(['data' => new CashSessionResource($session)]);
    }

    /**
     * GET /api/v1/cash/sessions/{session_uuid}/report
     *
     * Corte de caja (X/Z). Sesion open -> corte X en vivo (counted_amount/
     * difference null). Sesion closed -> corte Z con el cierre persistido.
     */
    public function report(Request $request, CashSession $session): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::CASH_VIEW), 403);

        return response()->json(['data' => new CashSessionReportResource($this->report->build($session))]);
    }

    public function open(OpenSessionRequest $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::CASH_OPEN), 403);

        $validated = $request->validated();
        $register = CashRegister::query()->where('uuid', $validated['cash_register_uuid'])->firstOrFail();

        try {
            $session = $this->service->openSession(
                register: $register,
                user: $request->user(),
                openingAmount: (float) ($validated['opening_amount'] ?? 0),
                notes: $validated['opening_notes'] ?? null,
            );
        } catch (CashSessionAlreadyOpenException $e) {
            return response()->json([
                'error' => [
                    'code' => 'SESSION_ALREADY_OPEN',
                    'message' => $e->getMessage(),
                ],
            ], Response::HTTP_CONFLICT);
        }

        $session->load(['register', 'openedBy']);

        return response()->json(
            ['data' => new CashSessionResource($session)],
            Response::HTTP_CREATED
        );
    }

    public function close(CloseSessionRequest $request, CashSession $session): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::CASH_CLOSE), 403);

        $validated = $request->validated();

        try {
            $closed = $this->service->closeSession(
                session: $session,
                user: $request->user(),
                countedAmount: (float) $validated['counted_amount'],
                notes: $validated['closing_notes'] ?? null,
            );
        } catch (CashSessionNotOpenException $e) {
            return response()->json([
                'error' => [
                    'code' => 'SESSION_NOT_OPEN',
                    'message' => $e->getMessage(),
                ],
            ], Response::HTTP_CONFLICT);
        }

        $closed->load(['register', 'openedBy', 'closedBy']);

        return response()->json(['data' => new CashSessionResource($closed)]);
    }
}
