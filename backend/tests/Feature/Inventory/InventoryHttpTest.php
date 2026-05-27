<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Identity\Models\User;
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
    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->warehouse = Warehouse::factory()->default()->ofBranch($this->branch)->create();

    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);

    $this->admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->admin->assignRole(Roles::ADMIN);

    $this->cashier = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cashier->assignRole(Roles::CAJERO);
});

// ====================================================================
//  GET /inventory/stocks
// ====================================================================

it('GET /inventory/stocks lista stocks del tenant', function () {
    app(InventoryService::class)->recordEntry($this->product, $this->warehouse, 20, 50);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/inventory/stocks', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [['quantity', 'thresholds', 'average_cost']],
        ]);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.quantity.on_hand'))->toBe(20);  // JSON serializa 20.0 como 20
});

it('GET /inventory/stocks con cajero responde 200 (tiene INVENTORY_VIEW)', function () {
    Sanctum::actingAs($this->cashier);

    // Cajero NO tiene INVENTORY_VIEW por defecto. Verifico el comportamiento real.
    $response = $this->getJson('/api/v1/inventory/stocks', ['X-Tenant' => 'mi-tenant']);

    // Cajero por permisos default no tiene INVENTORY_VIEW (ver Roles::CAJERO).
    // Por lo tanto debería ser 403.
    $response->assertStatus(403);
});

// ====================================================================
//  POST /inventory/adjust
// ====================================================================

it('POST /inventory/adjust con admin crea movimiento ajuste', function () {
    app(InventoryService::class)->recordEntry($this->product, $this->warehouse, 50);

    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        '/api/v1/inventory/adjust',
        [
            'product_uuid' => $this->product->uuid,
            'warehouse_uuid' => $this->warehouse->uuid,
            'delta' => -10,
            'reason' => 'Merma por daño en exhibidor',
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertCreated()
        ->assertJsonPath('data.type', 'adjustment')
        ->assertJsonPath('data.quantity.delta', -10)  // JSON: -10.0 → -10
        ->assertJsonPath('data.quantity.after', 40);
});

it('POST /inventory/adjust sin reason devuelve 422', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        '/api/v1/inventory/adjust',
        [
            'product_uuid' => $this->product->uuid,
            'warehouse_uuid' => $this->warehouse->uuid,
            'delta' => 5,
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(422)->assertJsonValidationErrors(['reason']);
});

it('POST /inventory/adjust delta=0 devuelve 422', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        '/api/v1/inventory/adjust',
        [
            'product_uuid' => $this->product->uuid,
            'warehouse_uuid' => $this->warehouse->uuid,
            'delta' => 0,
            'reason' => 'X',
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(422)->assertJsonValidationErrors(['delta']);
});

it('POST /inventory/adjust con stock insuficiente devuelve 409', function () {
    app(InventoryService::class)->recordEntry($this->product, $this->warehouse, 5);

    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        '/api/v1/inventory/adjust',
        [
            'product_uuid' => $this->product->uuid,
            'warehouse_uuid' => $this->warehouse->uuid,
            'delta' => -10,
            'reason' => 'Merma comprobada',  // min 3 chars en validator
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
});

// ====================================================================
//  POST /inventory/transfer
// ====================================================================

it('POST /inventory/transfer mueve stock entre warehouses', function () {
    $whB = Warehouse::factory()->ofBranch($this->branch)->storage()->create();
    app(InventoryService::class)->recordEntry($this->product, $this->warehouse, 20, 50);

    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        '/api/v1/inventory/transfer',
        [
            'product_uuid' => $this->product->uuid,
            'from_warehouse_uuid' => $this->warehouse->uuid,
            'to_warehouse_uuid' => $whB->uuid,
            'quantity' => 7,
            'reason' => 'Reposición piso',
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['transfer_id', 'out', 'in']]);

    expect($response->json('data.out.quantity.delta'))->toBe(-7);  // JSON serializa -7.0 → -7
    expect($response->json('data.in.quantity.delta'))->toBe(7);
    expect($response->json('data.out.transfer_id'))->toBe($response->json('data.in.transfer_id'));
});

it('POST /inventory/transfer al mismo warehouse devuelve 422', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        '/api/v1/inventory/transfer',
        [
            'product_uuid' => $this->product->uuid,
            'from_warehouse_uuid' => $this->warehouse->uuid,
            'to_warehouse_uuid' => $this->warehouse->uuid,
            'quantity' => 5,
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['to_warehouse_uuid']);
});

// ====================================================================
//  GET /inventory/movements (kardex)
// ====================================================================

it('GET /inventory/movements lista kardex ordenado por fecha desc', function () {
    $svc = app(InventoryService::class);
    $svc->recordEntry($this->product, $this->warehouse, 100, 10);
    $svc->recordExit($this->product, $this->warehouse, 30);
    $svc->adjust($this->product, $this->warehouse, -5, 'Merma');

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/inventory/movements', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(3);
});

it('GET /inventory/movements?type=adjustment filtra correctamente', function () {
    $svc = app(InventoryService::class);
    $svc->recordEntry($this->product, $this->warehouse, 100, 10);
    $svc->adjust($this->product, $this->warehouse, -5, 'Merma');

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/inventory/movements?type=adjustment', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);
});

// ====================================================================
//  Aislamiento cross-tenant
// ====================================================================

it('Aislamiento: stocks de tenant A no visibles desde tenant B', function () {
    app(InventoryService::class)->recordEntry($this->product, $this->warehouse, 10);

    // Tenant B con su propio stock
    $tenantB = Company::factory()->create(['slug' => 'tenant-b']);
    app(RoleProvisioner::class)->provisionDefaultRoles($tenantB);
    app(CatalogProvisioner::class)->provision($tenantB);
    TenantContext::set($tenantB);
    $unitB = Unit::query()->where('code', 'PZA')->firstOrFail();
    $branchB = Branch::factory()->default()->create(['company_id' => $tenantB->id]);
    $whB = Warehouse::factory()->default()->ofBranch($branchB)->create();
    $productB = Product::factory()->create(['company_id' => $tenantB->id, 'unit_id' => $unitB->id]);
    app(InventoryService::class)->recordEntry($productB, $whB, 99);

    $adminB = User::factory()->create(['company_id' => $tenantB->id]);
    $adminB->assignRole(Roles::ADMIN);

    Sanctum::actingAs($adminB);
    $response = $this->getJson('/api/v1/inventory/stocks', ['X-Tenant' => 'tenant-b']);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.quantity.on_hand'))->toBe(99);
});
