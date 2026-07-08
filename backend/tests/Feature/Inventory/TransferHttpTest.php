<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\Stock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'mi-tenant', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);

    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(CatalogProvisioner::class)->provision($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->unit = Unit::query()->where('code', 'PZA')->firstOrFail();

    $this->branchA = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->warehouseA = Warehouse::factory()->default()->ofBranch($this->branchA)->create();

    $this->branchB = Branch::factory()->create(['company_id' => $this->tenant->id, 'is_default' => false]);
    $this->warehouseB = Warehouse::factory()->default()->ofBranch($this->branchB)->create();

    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);

    $this->admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->admin->assignRole(Roles::ADMIN);

    $this->cashier = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cashier->assignRole(Roles::CAJERO);
});

function storePayload(string $fromUuid, string $toUuid, string $productUuid, float $qty = 10): array
{
    return [
        'from_branch_uuid' => $fromUuid,
        'to_branch_uuid' => $toUuid,
        'items' => [['product_uuid' => $productUuid, 'quantity' => $qty, 'unit_cost' => 5]],
    ];
}

// ====================================================================
//  POST /transfers (crear)
// ====================================================================

it('POST /transfers con admin crea transferencia en draft', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        '/api/v1/transfers',
        storePayload($this->branchA->uuid, $this->branchB->uuid, $this->product->uuid),
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonStructure(['data' => ['uuid', 'folio', 'status', 'from_branch', 'to_branch', 'items']]);
});

it('POST /transfers con cajero responde 403', function () {
    Sanctum::actingAs($this->cashier);
    $response = $this->postJson(
        '/api/v1/transfers',
        storePayload($this->branchA->uuid, $this->branchB->uuid, $this->product->uuid),
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(403);
});

it('POST /transfers con misma sucursal origen y destino devuelve 422', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        '/api/v1/transfers',
        storePayload($this->branchA->uuid, $this->branchA->uuid, $this->product->uuid),
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(422)->assertJsonValidationErrors(['to_branch_uuid']);
});

it('POST /transfers con sucursal destino inactiva devuelve 422 (RN-232)', function () {
    $this->branchB->update(['is_active' => false]);

    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        '/api/v1/transfers',
        storePayload($this->branchA->uuid, $this->branchB->uuid, $this->product->uuid),
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(422)->assertJsonValidationErrors(['to_branch_uuid']);
});

// ====================================================================
//  Ciclo completo: crear -> enviar -> recibir
// ====================================================================

it('ciclo crear-enviar-recibir mueve stock de origen a destino', function () {
    app(InventoryService::class)->recordEntry($this->product, $this->warehouseA, 100, 5);

    Sanctum::actingAs($this->admin);

    $create = $this->postJson(
        '/api/v1/transfers',
        storePayload($this->branchA->uuid, $this->branchB->uuid, $this->product->uuid, 40),
        ['X-Tenant' => 'mi-tenant']
    )->assertCreated();
    $uuid = $create->json('data.uuid');

    $this->postJson("/api/v1/transfers/{$uuid}/send", [], ['X-Tenant' => 'mi-tenant'])
        ->assertOk()
        ->assertJsonPath('data.status', 'sent');

    expect(stockOfWh($this->warehouseA->id, $this->tenant))->toBe(60.0);

    $this->postJson("/api/v1/transfers/{$uuid}/receive", [], ['X-Tenant' => 'mi-tenant'])
        ->assertOk()
        ->assertJsonPath('data.status', 'received');

    expect(stockOfWh($this->warehouseB->id, $this->tenant))->toBe(40.0);
});

it('receive con merma via API descuenta transfer_loss (RN-049)', function () {
    app(InventoryService::class)->recordEntry($this->product, $this->warehouseA, 100, 5);

    Sanctum::actingAs($this->admin);
    $create = $this->postJson(
        '/api/v1/transfers',
        storePayload($this->branchA->uuid, $this->branchB->uuid, $this->product->uuid, 40),
        ['X-Tenant' => 'mi-tenant']
    )->assertCreated();
    $uuid = $create->json('data.uuid');

    $this->postJson("/api/v1/transfers/{$uuid}/send", [], ['X-Tenant' => 'mi-tenant'])->assertOk();

    // Llegaron 35 de 40.
    $this->postJson(
        "/api/v1/transfers/{$uuid}/receive",
        ['items' => [['product_uuid' => $this->product->uuid, 'received' => 35]]],
        ['X-Tenant' => 'mi-tenant']
    )->assertOk();

    expect(stockOfWh($this->warehouseB->id, $this->tenant))->toBe(35.0);

    TenantContext::set($this->tenant);
    $loss = InventoryMovement::query()
        ->where('warehouse_id', $this->warehouseB->id)
        ->where('type', InventoryMovement::TYPE_ADJUSTMENT)
        ->where('reason', 'transfer_loss')
        ->first();
    expect($loss)->not->toBeNull()
        ->and((float) $loss->quantity_delta)->toBe(-5.0);
});

it('enviar dos veces la misma transferencia devuelve 409', function () {
    app(InventoryService::class)->recordEntry($this->product, $this->warehouseA, 100, 5);

    Sanctum::actingAs($this->admin);
    $uuid = $this->postJson(
        '/api/v1/transfers',
        storePayload($this->branchA->uuid, $this->branchB->uuid, $this->product->uuid, 10),
        ['X-Tenant' => 'mi-tenant']
    )->json('data.uuid');

    $this->postJson("/api/v1/transfers/{$uuid}/send", [], ['X-Tenant' => 'mi-tenant'])->assertOk();
    $this->postJson("/api/v1/transfers/{$uuid}/send", [], ['X-Tenant' => 'mi-tenant'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_TRANSITION');
});

it('cancelar una transferencia en draft la marca cancelled', function () {
    Sanctum::actingAs($this->admin);
    $uuid = $this->postJson(
        '/api/v1/transfers',
        storePayload($this->branchA->uuid, $this->branchB->uuid, $this->product->uuid),
        ['X-Tenant' => 'mi-tenant']
    )->json('data.uuid');

    $this->postJson(
        "/api/v1/transfers/{$uuid}/cancel",
        ['reason' => 'Pedido duplicado'],
        ['X-Tenant' => 'mi-tenant']
    )->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('GET /transfers lista las transferencias del tenant', function () {
    Sanctum::actingAs($this->admin);
    $this->postJson(
        '/api/v1/transfers',
        storePayload($this->branchA->uuid, $this->branchB->uuid, $this->product->uuid),
        ['X-Tenant' => 'mi-tenant']
    )->assertCreated();

    $response = $this->getJson('/api/v1/transfers', ['X-Tenant' => 'mi-tenant']);
    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);
});

it('almacenista puede crear y enviar transferencias', function () {
    app(InventoryService::class)->recordEntry($this->product, $this->warehouseA, 50, 5);

    $almacen = User::factory()->create(['company_id' => $this->tenant->id]);
    $almacen->assignRole(Roles::ALMACEN);

    Sanctum::actingAs($almacen);
    $uuid = $this->postJson(
        '/api/v1/transfers',
        storePayload($this->branchA->uuid, $this->branchB->uuid, $this->product->uuid, 10),
        ['X-Tenant' => 'mi-tenant']
    )->assertCreated()->json('data.uuid');

    $this->postJson("/api/v1/transfers/{$uuid}/send", [], ['X-Tenant' => 'mi-tenant'])
        ->assertOk()->assertJsonPath('data.status', 'sent');
});

function stockOfWh(int $warehouseId, ?Company $tenant = null): float
{
    // Tras un request HTTP el TenantContext del proceso de test pierde el
    // binding con la sesion de PostgreSQL (RLS): re-establecerlo para que la
    // consulta vea las filas del tenant.
    if ($tenant !== null) {
        TenantContext::set($tenant);
    }
    $stock = Stock::query()->where('warehouse_id', $warehouseId)->first();

    return $stock !== null ? (float) $stock->quantity_on_hand : 0.0;
}
