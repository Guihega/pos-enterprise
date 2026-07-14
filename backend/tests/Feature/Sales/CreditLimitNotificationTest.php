<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Services\CashService;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Customer\Models\Customer;
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

/*
|--------------------------------------------------------------------------
| RN-198: cliente sobre el limite de credito notifica a cobranza
|--------------------------------------------------------------------------
|
| Post-transaccion del checkout (patron RN-196). RN-094 impide exceder el
| limite, asi que el caso base es quedar AL limite (balance == limit).
| El intento rechazado por RN-094 no notifica: la venta nunca se persiste.
|
*/

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
    $this->cobranza = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cobranza->assignRole(Roles::COBRANZA);

    $this->session = app(CashService::class)->openSession($this->register, $this->cajero, 1000);
    $this->service = app(SalesService::class);
});

function creditLimitMakeProduct(float $price): Product
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

function creditLimitMakeCustomer(float $limit, float $balance = 0.0): Customer
{
    $test = test();

    $customer = Customer::factory()->create([
        'company_id' => $test->tenant->id,
        'credit_limit' => $limit,
        'credit_balance' => $balance,
    ]);
    TenantContext::set($test->tenant);

    return $customer;
}

function creditLimitCheckout(Product $product, float $amount, string $method, ?Customer $customer = null): void
{
    $test = test();

    $payment = ['method' => $method, 'amount' => $amount];
    if ($method === 'cash') {
        $payment['tendered_amount'] = $amount;
    }

    $req = CheckoutRequest::fromArray([
        'cash_session_uuid' => $test->session->uuid,
        'warehouse_uuid' => $test->warehouse->uuid,
        'customer_uuid' => $customer?->uuid,
        'items' => [
            ['product_uuid' => $product->uuid, 'quantity' => 1],
        ],
        'payments' => [$payment],
        'series' => 'A',
    ]);

    $test->service->checkout($req, $test->cajero);
    TenantContext::set($test->tenant);
}

it('notifica a cobranza cuando la venta a credito deja al cliente al limite', function (): void {
    $customer = creditLimitMakeCustomer(1000);
    $product = creditLimitMakeProduct(1000);

    creditLimitCheckout($product, 1000, 'credit', $customer);

    $notifications = Notification::query()
        ->where('type', 'customer.credit_limit_exceeded')
        ->get();

    expect($notifications)->toHaveCount(1);
    expect($notifications->first()->notifiable_id)->toBe($this->cobranza->getKey());
    expect($notifications->first()->severity)->toBe(Notification::SEVERITY_WARNING);
    expect((float) $notifications->first()->data['credit_limit'])->toBe(1000.0);
    expect((float) $notifications->first()->data['credit_balance'])->toBe(1000.0);
    expect($notifications->first()->data['customer_uuid'])->toBe($customer->uuid);
});

it('no notifica cuando la venta a credito no agota el limite', function (): void {
    $customer = creditLimitMakeCustomer(1000);
    $product = creditLimitMakeProduct(400);

    creditLimitCheckout($product, 400, 'credit', $customer);

    expect(Notification::query()->where('type', 'customer.credit_limit_exceeded')->count())->toBe(0);
});

it('no notifica en ventas sin pago a credito', function (): void {
    $customer = creditLimitMakeCustomer(1000);
    $product = creditLimitMakeProduct(500);

    creditLimitCheckout($product, 500, 'cash', $customer);

    expect(Notification::query()->where('type', 'customer.credit_limit_exceeded')->count())->toBe(0);
});
