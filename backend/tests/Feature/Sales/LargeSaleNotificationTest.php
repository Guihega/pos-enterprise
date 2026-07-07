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
    $this->admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->admin->assignRole(Roles::ADMIN);

    $this->session = app(CashService::class)->openSession($this->register, $this->cajero, 1000);
    $this->service = app(SalesService::class);
});

function largeSaleMakeProduct(float $price): Product
{
    $test = test();

    $product = Product::factory()->create([
        'company_id' => $test->tenant->id,
        'unit_id' => $test->unit->id,
        'sku' => 'PROD-'.uniqid(),
        'price' => $price,
        'track_inventory' => true,
    ]);
    app(InventoryService::class)->recordEntry($product, $test->warehouse, 100, $price * 0.6);
    TenantContext::set($test->tenant);

    return $product;
}

function largeSaleCheckout(Product $product, float $price): void
{
    $test = test();

    $req = CheckoutRequest::fromArray([
        'cash_session_uuid' => $test->session->uuid,
        'warehouse_uuid' => $test->warehouse->uuid,
        'items' => [
            ['product_uuid' => $product->uuid, 'quantity' => 1],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => $price, 'tendered_amount' => $price],
        ],
        'series' => 'A',
    ]);

    $test->service->checkout($req, $test->cajero);
    TenantContext::set($test->tenant);
}

it('alerta al admin cuando una venta supera el umbral de monto', function (): void {
    $product = largeSaleMakeProduct(60000);
    largeSaleCheckout($product, 60000);

    $notifications = Notification::query()->where('type', 'sales.large_sale')->get();

    expect($notifications)->toHaveCount(1);
    expect($notifications->first()->notifiable_id)->toBe($this->admin->getKey());
    expect($notifications->first()->severity)->toBe(Notification::SEVERITY_CRITICAL);
    expect((float) $notifications->first()->data['total_amount'])->toBe(60000.0);
});

it('no alerta cuando la venta esta bajo el umbral', function (): void {
    $product = largeSaleMakeProduct(100);
    largeSaleCheckout($product, 100);

    TenantContext::set($this->tenant);
    expect(Notification::query()->where('type', 'sales.large_sale')->count())->toBe(0);
});
