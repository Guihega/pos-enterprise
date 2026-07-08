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
//  CRUD basico + permisos
// ====================================================================

it('POST /branches con admin crea una sucursal activa', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        '/api/v1/branches',
        ['code' => 'SUC-02', 'name' => 'Sucursal Centro'],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertCreated()
        ->assertJsonPath('data.code', 'SUC-02')
        ->assertJsonPath('data.is_active', true);
});

it('POST /branches con cajero responde 403', function () {
    Sanctum::actingAs($this->cashier);
    $response = $this->postJson(
        '/api/v1/branches',
        ['code' => 'SUC-03', 'name' => 'X'],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(403);
});

it('POST /branches con code duplicado devuelve 422', function () {
    Sanctum::actingAs($this->admin);
    $this->postJson('/api/v1/branches', ['code' => 'SUC-09', 'name' => 'A'], ['X-Tenant' => 'mi-tenant'])->assertCreated();
    $this->postJson('/api/v1/branches', ['code' => 'SUC-09', 'name' => 'B'], ['X-Tenant' => 'mi-tenant'])
        ->assertStatus(422)->assertJsonValidationErrors(['code']);
});

it('PATCH /branches actualiza el nombre', function () {
    $branch = Branch::factory()->create(['company_id' => $this->tenant->id, 'is_default' => false]);

    Sanctum::actingAs($this->admin);
    $this->patchJson(
        "/api/v1/branches/{$branch->uuid}",
        ['name' => 'Nombre Actualizado'],
        ['X-Tenant' => 'mi-tenant']
    )->assertOk()->assertJsonPath('data.name', 'Nombre Actualizado');
});

it('GET /branches?active=false lista solo inactivas', function () {
    Branch::factory()->create(['company_id' => $this->tenant->id, 'is_default' => false, 'is_active' => false]);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/branches?active=false', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);
});

// ====================================================================
//  EX-180 / EX-181: desactivacion de sucursal
// ====================================================================

it('deactivate marca is_active=false sin stock (EX-180)', function () {
    $branch = Branch::factory()->create(['company_id' => $this->tenant->id, 'is_default' => false]);

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/branches/{$branch->uuid}/deactivate", [], ['X-Tenant' => 'mi-tenant'])
        ->assertOk()->assertJsonPath('data.is_active', false);
});

it('deactivate con stock pendiente devuelve 409 (EX-181)', function () {
    $branch = Branch::factory()->create(['company_id' => $this->tenant->id, 'is_default' => false]);
    $warehouse = Warehouse::factory()->default()->ofBranch($branch)->create();
    $product = Product::factory()->create(['company_id' => $this->tenant->id, 'unit_id' => $this->unit->id]);
    app(InventoryService::class)->recordEntry($product, $warehouse, 15);

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/branches/{$branch->uuid}/deactivate", [], ['X-Tenant' => 'mi-tenant'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BRANCH_HAS_STOCK');
});

it('deactivate de la sucursal default devuelve 409', function () {
    Sanctum::actingAs($this->admin);
    $this->postJson("/api/v1/branches/{$this->defaultBranch->uuid}/deactivate", [], ['X-Tenant' => 'mi-tenant'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BRANCH_IS_DEFAULT');
});
