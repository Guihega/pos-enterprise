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
    $this->defaultBranch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);

    $this->admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->admin->assignRole(Roles::ADMIN);

    $this->cashier = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cashier->assignRole(Roles::CAJERO);
});

// ====================================================================
//  PATCH /warehouses/{uuid}
// ====================================================================

it('PATCH /warehouses con admin actualiza el nombre', function () {
    $wh = Warehouse::factory()->ofBranch($this->defaultBranch)->create(['name' => 'Almacen Viejo']);

    Sanctum::actingAs($this->admin);
    $this->patchJson(
        "/api/v1/warehouses/{$wh->uuid}",
        ['name' => 'Almacen Nuevo'],
        ['X-Tenant' => 'mi-tenant']
    )->assertOk()->assertJsonPath('data.name', 'Almacen Nuevo');
});

it('PATCH /warehouses con cajero responde 403', function () {
    $wh = Warehouse::factory()->ofBranch($this->defaultBranch)->create();

    Sanctum::actingAs($this->cashier);
    $this->patchJson(
        "/api/v1/warehouses/{$wh->uuid}",
        ['name' => 'X'],
        ['X-Tenant' => 'mi-tenant']
    )->assertStatus(403);
});

it('PATCH con code duplicado en el tenant devuelve 422', function () {
    Warehouse::factory()->ofBranch($this->defaultBranch)->create(['code' => 'WH-AAA']);
    $wh = Warehouse::factory()->ofBranch($this->defaultBranch)->create(['code' => 'WH-BBB']);

    Sanctum::actingAs($this->admin);
    $this->patchJson(
        "/api/v1/warehouses/{$wh->uuid}",
        ['code' => 'WH-AAA'],
        ['X-Tenant' => 'mi-tenant']
    )->assertStatus(422)->assertJsonValidationErrors(['code']);
});

it('PATCH con su propio code no dispara el unique (ignore)', function () {
    $wh = Warehouse::factory()->ofBranch($this->defaultBranch)->create(['code' => 'WH-CCC']);

    Sanctum::actingAs($this->admin);
    $this->patchJson(
        "/api/v1/warehouses/{$wh->uuid}",
        ['code' => 'WH-CCC', 'name' => 'Mismo Code'],
        ['X-Tenant' => 'mi-tenant']
    )->assertOk()->assertJsonPath('data.name', 'Mismo Code');
});

it('PATCH is_default=true desmarca el default anterior de la misma branch', function () {
    $viejo = Warehouse::factory()->default()->ofBranch($this->defaultBranch)->create(['code' => 'WH-OLD']);
    $nuevo = Warehouse::factory()->ofBranch($this->defaultBranch)->create(['code' => 'WH-NEW']);

    Sanctum::actingAs($this->admin);
    $this->patchJson(
        "/api/v1/warehouses/{$nuevo->uuid}",
        ['is_default' => true],
        ['X-Tenant' => 'mi-tenant']
    )->assertOk()->assertJsonPath('data.is_default', true);

    TenantContext::set($this->tenant);
    expect($viejo->fresh()->is_default)->toBeFalse();
});

it('PATCH ignora branch_uuid e is_active por no ser editables', function () {
    $otra = Branch::factory()->create(['company_id' => $this->tenant->id, 'is_default' => false]);
    $wh = Warehouse::factory()->ofBranch($this->defaultBranch)->create();

    Sanctum::actingAs($this->admin);
    $this->patchJson(
        "/api/v1/warehouses/{$wh->uuid}",
        ['branch_uuid' => $otra->uuid, 'is_active' => false, 'name' => 'Solo Nombre'],
        ['X-Tenant' => 'mi-tenant']
    )->assertOk();

    TenantContext::set($this->tenant);
    $wh->refresh();
    expect($wh->branch_id)->toBe($this->defaultBranch->id);
    expect($wh->is_active)->toBeTrue();
    expect($wh->name)->toBe('Solo Nombre');
});

// ====================================================================
//  POST /warehouses/{uuid}/deactivate
// ====================================================================

it('deactivate marca is_active=false sin stock', function () {
    $wh = Warehouse::factory()->ofBranch($this->defaultBranch)->create();

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/warehouses/{$wh->uuid}/deactivate", [], ['X-Tenant' => 'mi-tenant'])
        ->assertOk()->assertJsonPath('data.is_active', false);
});

it('deactivate con stock pendiente devuelve 409', function () {
    $wh = Warehouse::factory()->ofBranch($this->defaultBranch)->create();
    $product = Product::factory()->create(['company_id' => $this->tenant->id, 'unit_id' => $this->unit->id]);
    app(InventoryService::class)->recordEntry($product, $wh, 15);

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/warehouses/{$wh->uuid}/deactivate", [], ['X-Tenant' => 'mi-tenant'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'WAREHOUSE_HAS_STOCK');
});

it('deactivate del almacen default devuelve 409', function () {
    $wh = Warehouse::factory()->default()->ofBranch($this->defaultBranch)->create();

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/warehouses/{$wh->uuid}/deactivate", [], ['X-Tenant' => 'mi-tenant'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'WAREHOUSE_IS_DEFAULT');
});

it('deactivate con cajero responde 403', function () {
    $wh = Warehouse::factory()->ofBranch($this->defaultBranch)->create();

    Sanctum::actingAs($this->cashier);
    $this->postJson("/api/v1/warehouses/{$wh->uuid}/deactivate", [], ['X-Tenant' => 'mi-tenant'])
        ->assertStatus(403);
});
