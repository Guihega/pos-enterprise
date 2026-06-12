<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Domain\Authorization\Permissions;
use App\Domain\Customer\Models\Customer;
use App\Domain\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class CustomersController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(Permissions::CUSTOMER_VIEW), 403);

        $perPage = min((int) $request->query('per_page', 50), 200);

        $query = Customer::query();

        if ($q = trim((string) $request->query('q', ''))) {
            $query->search($q);
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($request->boolean('with_credit')) {
            $query->withCredit();
        }

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        if ($request->has('blocked')) {
            $query->where('is_blocked', $request->boolean('blocked'));
        }

        $sort = $request->query('sort', 'name');
        $direction = $request->query('direction', 'asc');
        if (in_array($sort, ['name', 'created_at', 'credit_balance'], true)) {
            $query->orderBy($sort, $direction === 'desc' ? 'desc' : 'asc');
        }

        return CustomerResource::collection($query->paginate($perPage));
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::CUSTOMER_VIEW), 403);

        return response()->json(['data' => new CustomerResource($customer)]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::CUSTOMER_CREATE), 403);

        $data = $request->validated();
        $data['uuid'] = (string) Str::uuid();
        $data['company_id'] = TenantContext::id();

        $customer = Customer::create($this->normalizeDefaults($data));

        return response()->json(
            ['data' => new CustomerResource($customer)],
            Response::HTTP_CREATED
        );
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::CUSTOMER_UPDATE), 403);

        $customer->update($this->normalizeDefaults($request->validated()));

        return response()->json(['data' => new CustomerResource($customer)]);
    }

    public function destroy(Request $request, Customer $customer): Response|JsonResponse
    {
        abort_unless((bool) $request->user()?->can(Permissions::CUSTOMER_DELETE), 403);

        // Bloqueo: no permitir borrar clientes con saldo deudor
        if ((float) $customer->credit_balance > 0) {
            return response()->json([
                'error' => [
                    'code' => 'CUSTOMER_HAS_BALANCE',
                    'message' => 'No se puede borrar un cliente con saldo deudor.',
                ],
            ], Response::HTTP_CONFLICT);
        }

        $customer->delete();

        return response()->noContent();
    }

    /**
     * Rellena con sus defaults las columnas NOT NULL que el formulario
     * puede enviar como null explicito cuando se dejan vacias. El default
     * de la BD solo aplica si se OMITE la columna; un null explicito en el
     * INSERT/UPDATE viola la constraint NOT NULL. Afecta a credit_limit
     * (default 0), is_active (default true) y is_blocked (default false).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeDefaults(array $data): array
    {
        if (array_key_exists('credit_limit', $data) && $data['credit_limit'] === null) {
            $data['credit_limit'] = 0;
        }
        if (array_key_exists('is_active', $data) && $data['is_active'] === null) {
            $data['is_active'] = true;
        }
        if (array_key_exists('is_blocked', $data) && $data['is_blocked'] === null) {
            $data['is_blocked'] = false;
        }

        return $data;
    }
}
