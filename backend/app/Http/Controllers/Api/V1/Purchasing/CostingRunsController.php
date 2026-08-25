<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Domain\Authorization\Permissions;
use App\Domain\Purchasing\Models\CostingRun;
use App\Domain\Purchasing\Services\CostingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StoreCostingRunRequest;
use App\Http\Resources\CostingRunResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Corridas de costeo: landed cost -> precio sugerido (DISENO_COSTEO.md).
 */
class CostingRunsController extends Controller
{
    public function __construct(private readonly CostingService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::COSTING_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);
        $query = CostingRun::query();

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return CostingRunResource::collection(
            $query->orderByDesc('id')->paginate($perPage)
        );
    }

    public function store(StoreCostingRunRequest $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::COSTING_CREATE), 403);

        $data = $request->validated();
        $run = $this->service->create(
            name: $data['name'],
            lines: $data['lines'],
            freightTotal: (float) ($data['freight_total'] ?? 0),
            otherCostsTotal: (float) ($data['other_costs_total'] ?? 0),
            notes: $data['notes'] ?? null,
            userId: $request->user()?->id,
        );

        return (new CostingRunResource($run))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, CostingRun $costingRun): CostingRunResource
    {
        abort_unless((bool) $request->user()?->can(Permissions::COSTING_VIEW), 403);

        return new CostingRunResource($costingRun->load('lines.product'));
    }

    public function confirm(Request $request, CostingRun $costingRun): CostingRunResource
    {
        abort_unless((bool) $request->user()?->can(Permissions::COSTING_CONFIRM), 403);

        return new CostingRunResource(
            $this->service->confirm($costingRun)->load('lines.product')
        );
    }
}
