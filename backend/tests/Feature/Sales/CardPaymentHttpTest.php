<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Cash\Models\CashMovement;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Services\CashService;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Sales\Models\SalePayment;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
 * Cobro con terminal bancaria independiente (docs/DISENO_TERMINAL_A.md,
 * modelo A). Clon del beforeEach de GranelHttpTest con codigos -CD.
 * Producto de 100.00 con IVA incluido: 2 piezas = 200.00 exactos.
 */

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'cd-test', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA-CD']);
    $this->warehouse = Warehouse::factory()->create([
        'company_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'is_sellable' => true,
        'is_active' => true,
    ]);
    $this->supervisor = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->supervisor->assignRole(Roles::SUPERVISOR);
    $this->session = app(CashService::class)->openSession($this->register, $this->supervisor, 1000);
    $unit = Unit::factory()->create(['company_id' => $this->tenant->id, 'code' => 'PZA-CD']);
    $tax = Tax::factory()->create(['company_id' => $this->tenant->id, 'code' => 'IVA-CD', 'rate' => 0.16, 'is_inclusive' => true]);
    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $unit->id, 'tax_id' => $tax->id,
        'sku' => 'CD-PIEZA', 'price' => 100.00,
        'track_inventory' => true, 'is_sellable' => true, 'status' => Product::STATUS_ACTIVE,
    ]);
    app(InventoryService::class)->recordEntry($this->product, $this->warehouse, 100, 50);
    TenantContext::set($this->tenant);
});

/** Venta de 2 piezas (200.00) con el pago indicado. */
function cdCheckout(array $payment)
{
    Sanctum::actingAs(test()->supervisor);
    $resp = test()->postJson('/api/v1/sales', [
        'cash_session_uuid' => test()->session->uuid,
        'warehouse_uuid' => test()->warehouse->uuid,
        'items' => [['product_uuid' => test()->product->uuid, 'quantity' => 2]],
        'payments' => [$payment + ['amount' => 200.00]],
    ], ['X-Tenant' => 'cd-test']);
    TenantContext::set(test()->tenant);

    return $resp;
}

it('tarjeta sin numero de autorizacion devuelve 422', function () {
    cdCheckout(['method' => SalePayment::METHOD_CARD_CREDIT])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payments.0.authorization_code']);
    expect(SalePayment::query()->count())->toBe(0);
});

it('tarjeta de debito sin autorizacion tambien devuelve 422', function () {
    cdCheckout(['method' => SalePayment::METHOD_CARD_DEBIT])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payments.0.authorization_code']);
});

it('tarjeta con autorizacion vende y persiste los datos del voucher', function () {
    cdCheckout([
        'method' => SalePayment::METHOD_CARD_CREDIT,
        'authorization_code' => 'A12345',
        'reference' => 'REF-778',
        'card_brand' => 'visa',
        'card_last4' => '4242',
    ])->assertCreated();

    $pago = SalePayment::query()->firstOrFail();
    expect($pago->method)->toBe(SalePayment::METHOD_CARD_CREDIT)
        ->and($pago->authorization_code)->toBe('A12345')
        ->and($pago->reference)->toBe('REF-778')
        ->and($pago->card_brand)->toBe('visa')
        ->and($pago->card_last4)->toBe('4242')
        ->and((float) $pago->amount)->toEqualWithDelta(200.0, 0.001);
});

it('tarjeta con solo autorizacion basta: marca y ultimos 4 son opcionales (D1 = a)', function () {
    cdCheckout(['method' => SalePayment::METHOD_CARD_DEBIT, 'authorization_code' => 'B99'])
        ->assertCreated();
    expect(SalePayment::query()->firstOrFail()->card_last4)->toBeNull();
});

it('efectivo no exige autorizacion', function () {
    cdCheckout(['method' => SalePayment::METHOD_CASH, 'tendered_amount' => 200.00])
        ->assertCreated();
});

it('un cobro con tarjeta no genera movimiento sale_cash en la sesion de caja', function () {
    cdCheckout(['method' => SalePayment::METHOD_CARD_CREDIT, 'authorization_code' => 'C1'])
        ->assertCreated();
    expect(CashMovement::query()->where('cash_session_id', $this->session->id)->where('type', CashMovement::TYPE_SALE_CASH)->count())->toBe(0);

    cdCheckout(['method' => SalePayment::METHOD_CASH, 'tendered_amount' => 200.00])
        ->assertCreated();
    expect(CashMovement::query()->where('cash_session_id', $this->session->id)->where('type', CashMovement::TYPE_SALE_CASH)->count())->toBe(1);
});
