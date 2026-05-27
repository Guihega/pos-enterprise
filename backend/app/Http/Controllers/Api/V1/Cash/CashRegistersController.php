<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Cash;

use App\Domain\Authorization\Permissions;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cash\StoreCashRegisterRequest;
use App\Http\Resources\CashRegisterResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class CashRegistersController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::CASH_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = CashRegister::query()->with('branch');

        if ($branchUuid = $request->query('branch')) {
            $branchId = Branch::query()->where('uuid', $branchUuid)->value('id');
            $query->where('branch_id', $branchId);
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return CashRegisterResource::collection($query->orderBy('name')->paginate($perPage));
    }

    public function show(Request $request, CashRegister $register): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::CASH_VIEW), 403);

        $register->load('branch');
        $register->setAttribute('has_open_session', $register->hasOpenSession());

        return response()->json(['data' => new CashRegisterResource($register)]);
    }

    public function store(StoreCashRegisterRequest $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::SETTINGS_UPDATE), 403);

        $data = $request->validated();
        $data['uuid'] = (string) Str::uuid();
        $data['company_id'] = TenantContext::id();
        $data['branch_id'] = Branch::query()->where('uuid', $data['branch_uuid'])->value('id');
        unset($data['branch_uuid']);

        $register = CashRegister::create($data);
        $register->load('branch');

        return response()->json(
            ['data' => new CashRegisterResource($register)],
            Response::HTTP_CREATED
        );
    }
}
