<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Stock;
use App\Domain\Inventory\Models\Warehouse as InventoryWarehouse;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->tenant = Company::factory()->create();
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(CatalogProvisioner::class)->provision($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id, 'code' => 'SC1']);
    $this->warehouse = InventoryWarehouse::factory()->default()->ofBranch($this->branch)->create();

    $unit = Unit::query()->where('code', 'PZA')->firstOrFail();
    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $unit->id,
        'sku' => 'SKU-CONSIST-1',
        'price' => 100,
        'track_inventory' => true,
    ]);

    $this->admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->admin->assignRole(Roles::ADMIN);
});

function stockConsistencyMakeStock(float $onHand, float $reserved): Stock
{
    $test = test();

    return Stock::query()->create([
        'company_id' => $test->tenant->id,
        'product_id' => $test->product->id,
        'warehouse_id' => $test->warehouse->id,
        'quantity_on_hand' => $onHand,
        'quantity_reserved' => $reserved,
        'average_cost' => 0,
    ]);
}

function stockConsistencyAlertCount(Company $tenant): int
{
    TenantContext::set($tenant);

    return Notification::query()->where('type', 'stock.reserved_inconsistency')->count();
}

function stockConsistencyRun(): void
{
    test()->artisan('stock:check-consistency')->assertSuccessful();
}

it('alerta al admin sobre stock con reservado mayor que existencia', function (): void {
    stockConsistencyMakeStock(5, 10);

    stockConsistencyRun();

    expect(stockConsistencyAlertCount($this->tenant))->toBe(1);

    $notification = Notification::query()->where('type', 'stock.reserved_inconsistency')->firstOrFail();
    expect($notification->notifiable_id)->toBe($this->admin->id)
        ->and($notification->severity)->toBe(Notification::SEVERITY_CRITICAL)
        ->and((float) $notification->data['quantity_reserved'])->toBe(10.0)
        ->and((float) $notification->data['quantity_on_hand'])->toBe(5.0);
});

it('no alerta sobre stock sano', function (): void {
    stockConsistencyMakeStock(10, 3);

    stockConsistencyRun();

    expect(stockConsistencyAlertCount($this->tenant))->toBe(0);
});

it('no alerta cuando reservado es igual a existencia', function (): void {
    stockConsistencyMakeStock(7, 7);

    stockConsistencyRun();

    expect(stockConsistencyAlertCount($this->tenant))->toBe(0);
});

it('re-alerta en corridas posteriores mientras persista la inconsistencia', function (): void {
    stockConsistencyMakeStock(5, 10);

    stockConsistencyRun();
    stockConsistencyRun();

    expect(stockConsistencyAlertCount($this->tenant))->toBe(2);
});

it('aisla la deteccion por tenant', function (): void {
    stockConsistencyMakeStock(5, 10);

    $otherTenant = Company::factory()->create();
    TenantContext::runAs($otherTenant, function () use ($otherTenant): void {
        app(RoleProvisioner::class)->provisionDefaultRoles($otherTenant);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $otherAdmin = User::factory()->create(['company_id' => $otherTenant->id]);
        $otherAdmin->assignRole(Roles::ADMIN);
    });

    TenantContext::set($this->tenant);
    stockConsistencyRun();

    expect(stockConsistencyAlertCount($this->tenant))->toBe(1);
    expect(stockConsistencyAlertCount($otherTenant))->toBe(0);
});
