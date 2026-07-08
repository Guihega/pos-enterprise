<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Services\CashService;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Sales\Dto\CheckoutRequest;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Services\SalesService;
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

    $this->unit = Unit::query()->where('code', 'PZA')->firstOrFail();
    $this->branch = Branch::factory()->default()->create([
        'company_id' => $this->tenant->id,
        'code' => 'CTR',
    ]);
    $this->warehouse = Warehouse::factory()->default()->ofBranch($this->branch)->create();
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA01']);

    $this->cajero = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->auditor = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->auditor->assignRole(Roles::AUDITOR);

    $this->session = app(CashService::class)->openSession($this->register, $this->cajero, 1000);

    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'sku' => 'PROD-001',
        'name' => 'Producto Test',
        'price' => 100,
        'track_inventory' => true,
    ]);
    app(InventoryService::class)->recordEntry($this->product, $this->warehouse, 500, 60);

    $this->service = app(SalesService::class);
});

function makeCheckoutReq(): CheckoutRequest
{
    $test = test();

    return CheckoutRequest::fromArray([
        'cash_session_uuid' => $test->session->uuid,
        'warehouse_uuid' => $test->warehouse->uuid,
        'items' => [
            ['product_uuid' => $test->product->uuid, 'quantity' => 1],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 100, 'tendered_amount' => 100],
        ],
        'series' => 'A',
    ]);
}

function prefabVoided(int $cashierId, int $count): void
{
    $test = test();

    Sale::factory()->voided()->count($count)->create([
        'company_id' => $test->tenant->id,
        'branch_id' => $test->branch->id,
        'cash_register_id' => $test->register->id,
        'cash_session_id' => $test->session->id,
        'warehouse_id' => $test->warehouse->id,
        'user_id' => $cashierId,
        'voided_by' => $cashierId,
    ]);
}

function massCancellationCount(Company $tenant): int
{
    TenantContext::set($tenant);

    return Notification::query()->where('type', 'sales.mass_cancellation')->count();
}

it('alerta al auditor cuando el cajero cruza el umbral de cancelaciones', function (): void {
    // 9 cancelaciones previas del cajero hoy + 1 cancelacion real = 10 (umbral).
    prefabVoided($this->cajero->id, 9);

    $sale = $this->service->checkout(makeCheckoutReq(), $this->cajero);
    $this->service->cancel($sale, $this->cajero, 'Prueba');

    TenantContext::set($this->tenant);
    $notifications = Notification::query()->where('type', 'sales.mass_cancellation')->get();

    expect($notifications)->toHaveCount(1);
    expect($notifications->first()->notifiable_id)->toBe($this->auditor->getKey());
    expect($notifications->first()->severity)->toBe(Notification::SEVERITY_CRITICAL);
    expect($notifications->first()->data['voided_count'])->toBe(Sale::query()->where('voided_by', $this->cajero->id)->where('status', Sale::STATUS_VOIDED)->count());
});

it('no alerta cuando el cajero esta por debajo del umbral', function (): void {
    prefabVoided($this->cajero->id, 3);

    $sale = $this->service->checkout(makeCheckoutReq(), $this->cajero);
    $this->service->cancel($sale, $this->cajero, 'Prueba');

    expect(massCancellationCount($this->tenant))->toBe(0);
});

it('no repite la alerta en cancelaciones por encima del umbral', function (): void {
    // Ya hay 10 voided (en el umbral). Una cancelacion real lleva a 11.
    prefabVoided($this->cajero->id, 10);

    $sale = $this->service->checkout(makeCheckoutReq(), $this->cajero);
    $this->service->cancel($sale, $this->cajero, 'Prueba');

    expect(massCancellationCount($this->tenant))->toBe(0);
});
