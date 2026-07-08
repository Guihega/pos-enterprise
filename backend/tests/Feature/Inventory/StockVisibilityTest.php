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

    // Sucursal A con stock 10, Sucursal B con stock 99.
    $this->branchA = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->warehouseA = Warehouse::factory()->default()->ofBranch($this->branchA)->create();
    $this->branchB = Branch::factory()->create(['company_id' => $this->tenant->id, 'is_default' => false]);
    $this->warehouseB = Warehouse::factory()->default()->ofBranch($this->branchB)->create();

    $this->productA = Product::factory()->create(['company_id' => $this->tenant->id, 'unit_id' => $this->unit->id]);
    $this->productB = Product::factory()->create(['company_id' => $this->tenant->id, 'unit_id' => $this->unit->id]);

    $svc = app(InventoryService::class);
    $svc->recordEntry($this->productA, $this->warehouseA, 10);
    $svc->recordEntry($this->productB, $this->warehouseB, 99);
});

// ====================================================================
//  RN-233 / 46.4: visibilidad de stock cross-branch
// ====================================================================

it('gerente con permiso cross-branch ve stock de todas las sucursales', function () {
    $gerente = User::factory()->create(['company_id' => $this->tenant->id]);
    $gerente->assignRole(Roles::GERENTE);
    $gerente->syncBranches([$this->branchA]);

    Sanctum::actingAs($gerente);
    $response = $this->getJson('/api/v1/inventory/stocks', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk();
    // Ve A (10) y B (99) aunque solo este asignado a A.
    expect($response->json('meta.total'))->toBe(2);
});

it('almacenista sin cross-branch ve solo el stock de su sucursal', function () {
    $almacen = User::factory()->create(['company_id' => $this->tenant->id]);
    $almacen->assignRole(Roles::ALMACEN);
    $almacen->syncBranches([$this->branchA]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Sanctum::actingAs($almacen);
    $response = $this->getJson('/api/v1/inventory/stocks', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk();
    // Solo ve el stock de A (10), no el de B.
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.quantity.on_hand'))->toBe(10);
});

it('almacenista sin sucursal asignada y sin cross-branch no ve stock (RN-233 estricto)', function () {
    $almacen = User::factory()->create(['company_id' => $this->tenant->id]);
    $almacen->assignRole(Roles::ALMACEN);
    // sin syncBranches
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Sanctum::actingAs($almacen);
    $response = $this->getJson('/api/v1/inventory/stocks', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(0);
});

it('almacenista asignado a dos sucursales ve el stock de ambas', function () {
    $almacen = User::factory()->create(['company_id' => $this->tenant->id]);
    $almacen->assignRole(Roles::ALMACEN);
    $almacen->syncBranches([$this->branchA, $this->branchB]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Sanctum::actingAs($almacen);
    $response = $this->getJson('/api/v1/inventory/stocks', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(2);
});
