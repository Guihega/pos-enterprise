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
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->tenant = Company::factory()->create();
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(CatalogProvisioner::class)->provision($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->unit = Unit::query()->where('code', 'PZA')->firstOrFail();
    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->warehouse = Warehouse::factory()->default()->ofBranch($this->branch)->create();

    $this->almacen = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->almacen->assignRole(Roles::ALMACEN);
    $this->almacen->syncBranches([$this->branch]);
});

function actAs(User $user, Company $tenant): void
{
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant', $tenant->slug);
}

it('lista solo las notificaciones propias no leidas', function (): void {
    $svc = app(NotificationService::class);
    $svc->notify($this->almacen, 'stock.low', ['x' => 1], Notification::SEVERITY_WARNING);
    $svc->notify($this->almacen, 'stock.low', ['x' => 2], Notification::SEVERITY_WARNING);

    $otro = User::factory()->create(['company_id' => $this->tenant->id]);
    $svc->notify($otro, 'stock.low', ['x' => 3], Notification::SEVERITY_WARNING);

    actAs($this->almacen, $this->tenant);
    $response = $this->getJson('/api/v1/notifications');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(2);
});

it('no expone notificaciones de otro tenant', function (): void {
    app(NotificationService::class)->notify($this->almacen, 'stock.low', ['x' => 1], Notification::SEVERITY_WARNING);

    $otroTenant = Company::factory()->create();
    TenantContext::set($otroTenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($otroTenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $intruso = User::factory()->create(['company_id' => $otroTenant->id]);

    actAs($intruso, $otroTenant);
    $response = $this->getJson('/api/v1/notifications');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(0);
});

it('dispara RN-190 cuando una salida deja el stock bajo el minimo', function (): void {
    $gerente = User::factory()->create(['company_id' => $this->tenant->id]);
    $gerente->assignRole(Roles::GERENTE);
    $gerente->syncBranches([$this->branch]);

    $product = Product::factory()->create(['company_id' => $this->tenant->id, 'unit_id' => $this->unit->id]);

    $inventory = app(InventoryService::class);
    $inventory->recordEntry($product, $this->warehouse, 20, 10);
    TenantContext::set($this->tenant);

    $stock = Stock::query()
        ->where('product_id', $product->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->firstOrFail();
    $stock->stock_min = 5;
    $stock->save();

    $inventory->recordExit($product, $this->warehouse, 16, InventoryMovement::TYPE_EXIT);
    TenantContext::set($this->tenant);

    // El almacenista ve su alerta via HTTP.
    actAs($this->almacen, $this->tenant);
    $response = $this->getJson('/api/v1/notifications');
    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.type'))->toBe('stock.low');
    expect($response->json('data.0.severity'))->toBe(Notification::SEVERITY_WARNING);

    // El gerente tambien la recibe.
    TenantContext::set($this->tenant);
    actAs($gerente, $this->tenant);
    expect($this->getJson('/api/v1/notifications')->json('meta.total'))->toBe(1);
});

it('marca una notificacion como leida y desaparece de no leidas', function (): void {
    $n = app(NotificationService::class)->notify($this->almacen, 'stock.low', ['x' => 1], Notification::SEVERITY_WARNING);

    actAs($this->almacen, $this->tenant);
    $this->postJson("/api/v1/notifications/{$n->uuid}/read")->assertOk();

    TenantContext::set($this->tenant);
    actAs($this->almacen, $this->tenant);
    expect($this->getJson('/api/v1/notifications')->json('meta.total'))->toBe(0);
});

it('rechaza marcar como leida una notificacion ajena', function (): void {
    $n = app(NotificationService::class)->notify($this->almacen, 'stock.low', ['x' => 1], Notification::SEVERITY_WARNING);

    $otro = User::factory()->create(['company_id' => $this->tenant->id]);
    actAs($otro, $this->tenant);

    $this->postJson("/api/v1/notifications/{$n->uuid}/read")->assertForbidden();
});
