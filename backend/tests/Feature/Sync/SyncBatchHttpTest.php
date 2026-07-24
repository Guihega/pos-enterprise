<?php

declare(strict_types=1);
use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Services\CashService;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sync\Models\SyncBatch;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'sync-test', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA-01']);
    $this->warehouse = Warehouse::factory()->create([
        'company_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'is_sellable' => true,
        'is_active' => true,
    ]);

    $this->cashier = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cashier->assignRole(Roles::CAJERO);
    $this->session = app(CashService::class)->openSession($this->register, $this->cashier, 1000);

    $unit = Unit::factory()->create(['company_id' => $this->tenant->id, 'code' => 'PZA-SY']);
    $tax = Tax::factory()->create([
        'company_id' => $this->tenant->id,
        'code' => 'IVA-16-SY',
        'rate' => 0.16,
        'is_inclusive' => true,
    ]);
    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $unit->id,
        'tax_id' => $tax->id,
        'price' => 116.00,
        'track_inventory' => true,
        'is_sellable' => true,
        'status' => Product::STATUS_ACTIVE,
    ]);
    app(InventoryService::class)->recordEntry($this->product, $this->warehouse, 100, 50);

    Sanctum::actingAs($this->cashier);
});

test('falla 422 si batch_uuid no es uuid valido', function () {
    $this->withHeaders(['X-Tenant' => 'sync-test'])
        ->postJson('/api/v1/sync/batch', [
            'batch_uuid' => 'no-es-uuid',
            'items' => [],
        ])
        ->assertStatus(422);
});

test('falla 422 si items esta vacio', function () {
    $this->withHeaders(['X-Tenant' => 'sync-test'])
        ->postJson('/api/v1/sync/batch', [
            'batch_uuid' => (string) Str::uuid(),
            'items' => [],
        ])
        ->assertStatus(422);
});

test('requiere autenticacion', function () {
    $this->app['auth']->forgetGuards();
    $this->withHeaders(['X-Tenant' => 'sync-test'])
        ->postJson('/api/v1/sync/batch', [
            'batch_uuid' => (string) Str::uuid(),
            'items' => [],
        ])
        ->assertStatus(401);
});

test('devuelve error para operacion no soportada', function () {
    $item = [
        'client_uuid' => (string) Str::uuid(),
        'entity_type' => 'sale',
        'entity_uuid' => (string) Str::uuid(),
        'operation' => 'delete',
        'client_timestamp' => '2026-01-01T10:00:00Z',
        'payload' => ['dummy' => true],
    ];

    $this->withHeaders(['X-Tenant' => 'sync-test'])
        ->postJson('/api/v1/sync/batch', [
            'batch_uuid' => (string) Str::uuid(),
            'items' => [$item],
        ])
        ->assertStatus(200)
        ->assertJsonPath('results.0.status', 'error');
});

test('POST /sync/batch procesa una venta exitosamente', function () {
    $item = [
        'client_uuid' => (string) Str::uuid(),
        'entity_type' => 'sale',
        'entity_uuid' => (string) Str::uuid(),
        'operation' => 'create',
        'client_timestamp' => '2026-01-01T10:00:00Z',
        'payload' => [
            'cash_session_uuid' => $this->session->uuid,
            'warehouse_uuid' => $this->warehouse->uuid,
            'items' => [[
                'product_uuid' => $this->product->uuid,
                'quantity' => 1,
            ]],
            'payments' => [[
                'method' => 'cash',
                'amount' => 116.00,
                'tendered_amount' => 116.00,
            ]],
        ],
    ];

    $response = $this->withHeaders(['X-Tenant' => 'sync-test'])
        ->postJson('/api/v1/sync/batch', [
            'batch_uuid' => (string) Str::uuid(),
            'items' => [$item],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('results.0.status', 'success')
        ->assertJsonStructure(['batch_uuid', 'results' => [['client_uuid', 'status', 'data']]]);

    // Contrato 38.3 linea 7062: el cliente actualiza su entidad local
    // con el folio del servidor. Sale.number es el folio real (Sale no
    // tiene atributo folio; antes del fix este campo llegaba null).
    // Re-setear TenantContext: el checkout dentro del request lo pierde.
    TenantContext::set($this->tenant);
    $response->assertJsonPath('results.0.data.folio', Sale::query()->firstOrFail()->number);
});

test('batch a tenant suspendido devuelve 402 sin persistir nada (39.1 evidencia)', function () {
    // 39.1 "tenant suspendido": cubierto implicito por EnsureTenantContext,
    // que corta con 402 TENANT_SUSPENDED antes de tocar el service. Este
    // test es la evidencia formal: el batch muere en el middleware aunque
    // el token Sanctum sea valido, y ni el batch ni ventas llegan a BD.
    // No requiere conflicto en la cola 39.3: el cliente recibe un rechazo
    // deterministico (no transitorio) y debe detener el sync.
    $this->tenant->update(['status' => 'suspended']);

    $this->withHeaders(['X-Tenant' => 'sync-test'])
        ->postJson('/api/v1/sync/batch', [
            'batch_uuid' => (string) Str::uuid(),
            'items' => [],
        ])
        ->assertStatus(402)
        ->assertJsonPath('error.code', 'TENANT_SUSPENDED');

    TenantContext::set($this->tenant);
    expect(SyncBatch::query()->count())->toBe(0);
});
