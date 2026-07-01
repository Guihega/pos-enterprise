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
use App\Domain\Reports\Services\ConsolidatedReportService;
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
    $this->product = Product::factory()->create(['company_id' => $this->tenant->id, 'unit_id' => $this->unit->id]);

    app(InventoryService::class)->recordEntry($this->product, $this->warehouse, 30);

    $this->admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->admin->assignRole(Roles::ADMIN);
    $this->cashier = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cashier->assignRole(Roles::CAJERO);

    app(ConsolidatedReportService::class)->refreshAll();
});

it('GET /reports/consolidated/inventory con admin devuelve stock consolidado', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/reports/consolidated/inventory', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()->assertJsonStructure(['data' => [['product_id', 'total_on_hand', 'warehouse_count']]]);
    // JSON serializa el decimal exacto 30.0000 como 30.
    expect($response->json('data.0.total_on_hand'))->toBe(30);
});

it('GET /reports/consolidated/inventory con cajero responde 403', function () {
    Sanctum::actingAs($this->cashier);
    $response = $this->getJson('/api/v1/reports/consolidated/inventory', ['X-Tenant' => 'mi-tenant']);
    $response->assertStatus(403);
});

it('GET /reports/consolidated/branch-comparison responde estructura correcta', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/reports/consolidated/branch-comparison', ['X-Tenant' => 'mi-tenant']);
    $response->assertOk()->assertJsonStructure(['data']);
});

it('GET /reports/consolidated/sales-daily responde estructura correcta', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/reports/consolidated/sales-daily', ['X-Tenant' => 'mi-tenant']);
    $response->assertOk()->assertJsonStructure(['data']);
});

it('aislamiento: reporte de inventario no expone stock de otro tenant', function () {
    // Tenant B con stock distinto.
    $tenantB = Company::factory()->create(['slug' => 'tenant-b']);
    app(RoleProvisioner::class)->provisionDefaultRoles($tenantB);
    app(CatalogProvisioner::class)->provision($tenantB);
    TenantContext::set($tenantB);
    $unitB = Unit::query()->where('code', 'PZA')->firstOrFail();
    $branchB = Branch::factory()->default()->create(['company_id' => $tenantB->id]);
    $whB = Warehouse::factory()->default()->ofBranch($branchB)->create();
    $productB = Product::factory()->create(['company_id' => $tenantB->id, 'unit_id' => $unitB->id]);
    app(InventoryService::class)->recordEntry($productB, $whB, 777);

    $adminB = User::factory()->create(['company_id' => $tenantB->id]);
    $adminB->assignRole(Roles::ADMIN);

    app(ConsolidatedReportService::class)->refreshAll();

    Sanctum::actingAs($adminB);
    $response = $this->getJson('/api/v1/reports/consolidated/inventory', ['X-Tenant' => 'tenant-b']);

    $response->assertOk();
    // B solo ve su producto (777), nunca el de A (30).
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.total_on_hand'))->toBe(777);
});
