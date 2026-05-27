<?php

declare(strict_types=1);

use App\Domain\Cash\Models\CashMovement;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Services\CashService;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\Stock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Sales\Dto\CheckoutRequest;
use App\Domain\Sales\Exceptions\InsufficientCreditException;
use App\Domain\Sales\Exceptions\PaymentMismatchException;
use App\Domain\Sales\Exceptions\SaleNotCancellableException;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Services\SalesService;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;

/*
|--------------------------------------------------------------------------
| Convención fiscal de este test suite
|--------------------------------------------------------------------------
|
| El CatalogProvisioner siembra los impuestos MX (IVA-16, IVA-8, IVA-0,
| EXENTO) como is_inclusive=true. Esto refleja el flujo POS B2C estándar
| en México: el precio capturado en products.price es el PRECIO AL PÚBLICO
| que ya incluye IVA. El calculator extrae el IVA del precio para fines
| de desglose, no lo suma encima.
|
| Cálculo de referencia (price=100, IVA 16% inclusive):
|   line_subtotal_bruto = qty × 100         (con IVA dentro)
|   base_neta           = bruto / 1.16
|   tax_amount          = bruto - base_neta
|   line_total          = bruto             (no se suma IVA encima)
|
|   1 unidad: bruto=100 → base=86.21, tax=13.79, total=100
|   2 unidades: bruto=200 → base=172.41, tax=27.59, total=200
|   3 unidades: bruto=300 → base=258.62, tax=41.38, total=300
|   5 unidades: bruto=500 → base=431.03, tax=68.97, total=500
|
| Para el caso tax-exclusive (precio neto + IVA), ver el test
| "checkout con tax_exclusive calcula correctamente" que crea un Tax con
| is_inclusive=false explícito.
|
*/

beforeEach(function () {
    $this->tenant = Company::factory()->create();
    TenantContext::set($this->tenant);

    app(CatalogProvisioner::class)->provision($this->tenant);
    $this->unit = Unit::query()->where('code', 'PZA')->firstOrFail();
    $this->iva16 = Tax::query()->where('code', 'IVA-16')->firstOrFail();

    $this->branch = Branch::factory()->default()->create([
        'company_id' => $this->tenant->id,
        'code' => 'CTR',
    ]);
    $this->warehouse = Warehouse::factory()->default()->ofBranch($this->branch)->create();
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create([
        'code' => 'CAJA01',
    ]);
    $this->user = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->session = app(CashService::class)->openSession($this->register, $this->user, 1000);

    // Producto con stock para vender. Precio bruto $100 (IVA dentro).
    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'tax_id' => $this->iva16->id,
        'sku' => 'PROD-001',
        'name' => 'Producto Test',
        'price' => 100,
        'track_inventory' => true,
    ]);
    app(InventoryService::class)->recordEntry(
        $this->product, $this->warehouse, 50, 60
    );

    $this->service = app(SalesService::class);
});

// ========================================================================
//  Helper: construir CheckoutRequest mínimo
// ========================================================================

function makeCheckoutRequest(array $items = [], array $paymentsData = [], array $extras = []): CheckoutRequest
{
    $test = test();

    return CheckoutRequest::fromArray(array_merge([
        'cash_session_uuid' => $test->session->uuid,
        'warehouse_uuid' => $test->warehouse->uuid,
        'items' => $items,
        'payments' => $paymentsData,
        'series' => 'A',
    ], $extras));
}

// ========================================================================
//  Checkout: caso feliz
// ========================================================================

it('checkout simple: 1 producto en efectivo descuenta stock y registra venta', function () {
    // Producto $100 inclusive × 2 = bruto $200 (IVA dentro)
    // base=172.41, tax=27.59, total=200
    $req = makeCheckoutRequest(
        items: [['product_uuid' => $this->product->uuid, 'quantity' => 2]],
        paymentsData: [['method' => 'cash', 'amount' => 200, 'tendered_amount' => 250]],
    );

    $sale = $this->service->checkout($req, $this->user);

    expect($sale->status)->toBe(Sale::STATUS_COMPLETED)
        ->and((float) $sale->subtotal_amount)->toBe(172.41)
        ->and((float) $sale->tax_amount)->toBe(27.59)
        ->and((float) $sale->total_amount)->toBe(200.0)
        ->and((float) $sale->paid_amount)->toBe(200.0)
        ->and((float) $sale->change_amount)->toBe(50.0);

    // Verificar folio
    expect($sale->number)->toBe('CTR-CAJA01-A-000001');

    // Stock descontado
    $stock = Stock::query()
        ->where('product_id', $this->product->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->firstOrFail();
    expect((float) $stock->quantity_on_hand)->toBe(48.0);

    // Movimiento de inventario tipo exit
    $invMov = InventoryMovement::query()
        ->where('source_type', Sale::class)
        ->where('source_id', $sale->id)
        ->first();
    expect($invMov)->not->toBeNull()
        ->and($invMov->type)->toBe(InventoryMovement::TYPE_EXIT);

    // Movimiento de caja tipo sale_cash
    $cashMov = CashMovement::query()
        ->where('cash_session_id', $this->session->id)
        ->where('type', CashMovement::TYPE_SALE_CASH)
        ->first();
    expect($cashMov)->not->toBeNull()
        ->and((float) $cashMov->amount)->toBe(200.0);
});

it('checkout incremental genera folios consecutivos', function () {
    // Producto $100 inclusive × 1 = bruto $100 (IVA dentro)
    $items = [['product_uuid' => $this->product->uuid, 'quantity' => 1]];
    $payments = [['method' => 'cash', 'amount' => 100]];

    $sale1 = $this->service->checkout(makeCheckoutRequest($items, $payments), $this->user);
    $sale2 = $this->service->checkout(makeCheckoutRequest($items, $payments), $this->user);
    $sale3 = $this->service->checkout(makeCheckoutRequest($items, $payments), $this->user);

    expect($sale1->number)->toBe('CTR-CAJA01-A-000001')
        ->and($sale2->number)->toBe('CTR-CAJA01-A-000002')
        ->and($sale3->number)->toBe('CTR-CAJA01-A-000003');
});

it('checkout con tax_exclusive calcula correctamente', function () {
    // Producto con IVA exclusive (precio neto + IVA encima).
    // Caso espejo del default: probar la otra rama del calculator.
    $iva16Exc = Tax::query()->create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'company_id' => $this->tenant->id,
        'code' => 'IVA-16-EXC',
        'name' => 'IVA 16% exclusive',
        'rate' => 0.16,
        'type' => Tax::TYPE_VAT,
        'is_inclusive' => false,
        'is_active' => true,
        'is_default' => false,
    ]);
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'tax_id' => $iva16Exc->id,
        'price' => 100,  // precio neto, IVA se suma encima
    ]);
    app(InventoryService::class)->recordEntry($product, $this->warehouse, 10, 50);

    $req = makeCheckoutRequest(
        items: [['product_uuid' => $product->uuid, 'quantity' => 1]],
        paymentsData: [['method' => 'cash', 'amount' => 116]],
    );

    $sale = $this->service->checkout($req, $this->user);

    // Tax exclusive: precio 100 neto + IVA 16 = total 116
    expect((float) $sale->total_amount)->toBe(116.0)
        ->and((float) $sale->subtotal_amount)->toBe(100.0)
        ->and((float) $sale->tax_amount)->toBe(16.0);
});

it('checkout con descuento por línea calcula correctamente', function () {
    // Producto $100 inclusive, qty=1, descuento 10% sobre el bruto.
    // line_subtotal_bruto = 100, discount = 10, after = 90 (bruto)
    // base_neta = 90 / 1.16 = 77.59, tax = 12.41, total = 90
    $req = makeCheckoutRequest(
        items: [[
            'product_uuid' => $this->product->uuid,
            'quantity' => 1,
            'discount_percent' => 10,
        ]],
        paymentsData: [['method' => 'cash', 'amount' => 90]],
    );

    $sale = $this->service->checkout($req, $this->user);

    expect((float) $sale->discount_amount)->toBe(10.0)
        ->and((float) $sale->subtotal_amount)->toBe(77.59)
        ->and((float) $sale->tax_amount)->toBe(12.41)
        ->and((float) $sale->total_amount)->toBe(90.0);
});

it('checkout con propina (tip) la suma al total', function () {
    // Producto $100 inclusive × 1 = bruto $100. Tip $20. Total = 100 + 20 = 120.
    $req = makeCheckoutRequest(
        items: [['product_uuid' => $this->product->uuid, 'quantity' => 1]],
        paymentsData: [['method' => 'cash', 'amount' => 120]],
        extras: ['tip_amount' => 20],
    );

    $sale = $this->service->checkout($req, $this->user);

    expect((float) $sale->tip_amount)->toBe(20.0)
        ->and((float) $sale->total_amount)->toBe(120.0);
});

it('checkout multi-payment: efectivo + tarjeta', function () {
    // Producto $100 inclusive × 5 = bruto $500. Pagar 200 cash + 300 tarjeta.
    $req = makeCheckoutRequest(
        items: [['product_uuid' => $this->product->uuid, 'quantity' => 5]],
        paymentsData: [
            ['method' => 'cash', 'amount' => 200],
            ['method' => 'card_credit', 'amount' => 300, 'card_brand' => 'visa', 'card_last4' => '4242'],
        ],
    );

    $sale = $this->service->checkout($req, $this->user);

    expect((float) $sale->total_amount)->toBe(500.0)
        ->and((float) $sale->paid_amount)->toBe(500.0)
        ->and($sale->payments()->count())->toBe(2);

    // Solo el cash genera cash_sale; tarjeta genera sale_other (informativo)
    $cashMovs = CashMovement::query()
        ->where('cash_session_id', $this->session->id)
        ->whereIn('type', [CashMovement::TYPE_SALE_CASH, CashMovement::TYPE_SALE_OTHER])
        ->get();
    expect($cashMovs->count())->toBe(2);
});

it('checkout con cliente denormaliza datos', function () {
    $customer = Customer::factory()->business()->create([
        'name' => 'ACME', 'tax_id' => 'ACM010101AA1',
    ]);

    $req = makeCheckoutRequest(
        items: [['product_uuid' => $this->product->uuid, 'quantity' => 1]],
        paymentsData: [['method' => 'cash', 'amount' => 100]],
        extras: ['customer_uuid' => $customer->uuid],
    );

    $sale = $this->service->checkout($req, $this->user);

    expect($sale->customer_id)->toBe($customer->id)
        ->and($sale->customer_name)->toBe('ACME')
        ->and($sale->customer_tax_id)->toBe('ACM010101AA1');
});

it('checkout con producto track_inventory=false NO descuenta stock', function () {
    // Servicio $500 inclusive × 1 = bruto $500. No tiene stock asociado.
    $servicio = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'tax_id' => $this->iva16->id,
        'price' => 500,
        'track_inventory' => false,
    ]);

    $req = makeCheckoutRequest(
        items: [['product_uuid' => $servicio->uuid, 'quantity' => 1]],
        paymentsData: [['method' => 'cash', 'amount' => 500]],
    );

    $sale = $this->service->checkout($req, $this->user);

    // No debe haber stock para el servicio
    $stock = Stock::query()->where('product_id', $servicio->id)->first();
    expect($stock)->toBeNull();

    // Tampoco movimiento de inventario
    $invMov = InventoryMovement::query()
        ->where('source_type', Sale::class)
        ->where('source_id', $sale->id)
        ->count();
    expect($invMov)->toBe(0);
});

// ========================================================================
//  Checkout: validaciones
// ========================================================================

it('checkout sin pagos lanza PaymentMismatchException', function () {
    $req = makeCheckoutRequest(
        items: [['product_uuid' => $this->product->uuid, 'quantity' => 1]],
        paymentsData: [],
    );

    expect(fn () => $this->service->checkout($req, $this->user))
        ->toThrow(PaymentMismatchException::class);
});

it('checkout con underpayment lanza PaymentMismatchException', function () {
    // Total esperado 100 (inclusive), paga 50.
    $req = makeCheckoutRequest(
        items: [['product_uuid' => $this->product->uuid, 'quantity' => 1]],
        paymentsData: [['method' => 'cash', 'amount' => 50]],
    );

    expect(fn () => $this->service->checkout($req, $this->user))
        ->toThrow(PaymentMismatchException::class);
});

it('checkout con sobrepago en tarjeta lanza PaymentMismatchException', function () {
    // Total esperado 100 (inclusive), paga 200 con tarjeta → no se permite cambio en non-cash.
    $req = makeCheckoutRequest(
        items: [['product_uuid' => $this->product->uuid, 'quantity' => 1]],
        paymentsData: [['method' => 'card_credit', 'amount' => 200]],
    );

    expect(fn () => $this->service->checkout($req, $this->user))
        ->toThrow(PaymentMismatchException::class);
});

it('checkout con stock insuficiente lanza InsufficientStockException', function () {
    // Producto $100 inclusive × 100 = bruto $10000. Tengo 50 en stock.
    $req = makeCheckoutRequest(
        items: [['product_uuid' => $this->product->uuid, 'quantity' => 100]],
        paymentsData: [['method' => 'cash', 'amount' => 10000]],
    );

    expect(fn () => $this->service->checkout($req, $this->user))
        ->toThrow(\App\Domain\Inventory\Exceptions\InsufficientStockException::class);

    // Verificar que TODA la transacción se revirtió: no hay sale, no hay stock movement
    expect(Sale::query()->count())->toBe(0);
    $stock = Stock::query()->where('product_id', $this->product->id)->firstOrFail();
    expect((float) $stock->quantity_on_hand)->toBe(50.0);  // intacto
});

it('checkout con sesión cerrada lanza CashSessionNotOpenException', function () {
    app(CashService::class)->closeSession($this->session, $this->user, 1000);

    $req = makeCheckoutRequest(
        items: [['product_uuid' => $this->product->uuid, 'quantity' => 1]],
        paymentsData: [['method' => 'cash', 'amount' => 100]],
    );

    expect(fn () => $this->service->checkout($req, $this->user))
        ->toThrow(\App\Domain\Cash\Exceptions\CashSessionNotOpenException::class);
});

// ========================================================================
//  Crédito
// ========================================================================

it('checkout con method=credit aumenta credit_balance del cliente', function () {
    $customer = Customer::factory()->withCredit(10000)->create();

    // Total inclusive = 100, todo a crédito.
    $req = makeCheckoutRequest(
        items: [['product_uuid' => $this->product->uuid, 'quantity' => 1]],
        paymentsData: [['method' => 'credit', 'amount' => 100]],
        extras: ['customer_uuid' => $customer->uuid],
    );

    $sale = $this->service->checkout($req, $this->user);

    $customer->refresh();
    expect((float) $customer->credit_balance)->toBe(100.0);
});

it('checkout con method=credit sin cliente lanza error', function () {
    $req = makeCheckoutRequest(
        items: [['product_uuid' => $this->product->uuid, 'quantity' => 1]],
        paymentsData: [['method' => 'credit', 'amount' => 100]],
    );

    expect(fn () => $this->service->checkout($req, $this->user))
        ->toThrow(InvalidArgumentException::class);
});

it('checkout con credit excediendo límite lanza InsufficientCreditException', function () {
    // Límite 50, intenta cargar 100 a crédito.
    $customer = Customer::factory()->withCredit(50)->create();

    $req = makeCheckoutRequest(
        items: [['product_uuid' => $this->product->uuid, 'quantity' => 1]],
        paymentsData: [['method' => 'credit', 'amount' => 100]],
        extras: ['customer_uuid' => $customer->uuid],
    );

    expect(fn () => $this->service->checkout($req, $this->user))
        ->toThrow(InsufficientCreditException::class);
});

// ========================================================================
//  Cancel
// ========================================================================

it('cancel revierte stock y registra refund_cash', function () {
    // Producto $100 inclusive × 3 = bruto $300, total $300.
    $sale = $this->service->checkout(
        makeCheckoutRequest(
            items: [['product_uuid' => $this->product->uuid, 'quantity' => 3]],
            paymentsData: [['method' => 'cash', 'amount' => 300]],
        ),
        $this->user
    );

    $stockBefore = Stock::query()->where('product_id', $this->product->id)->firstOrFail();
    expect((float) $stockBefore->quantity_on_hand)->toBe(47.0);  // 50 - 3

    // Cancelar
    $cancelled = $this->service->cancel($sale, $this->user, 'Cliente arrepentido');

    expect($cancelled->status)->toBe(Sale::STATUS_VOIDED)
        ->and($cancelled->voided_by)->toBe($this->user->id)
        ->and($cancelled->void_reason)->toBe('Cliente arrepentido');

    // Stock revertido
    $stockAfter = Stock::query()->where('product_id', $this->product->id)->firstOrFail();
    expect((float) $stockAfter->quantity_on_hand)->toBe(50.0);

    // Refund cash en la sesión
    $refund = CashMovement::query()
        ->where('cash_session_id', $this->session->id)
        ->where('type', CashMovement::TYPE_REFUND_CASH)
        ->first();
    expect($refund)->not->toBeNull()
        ->and((float) $refund->amount)->toBe(300.0);
});

it('cancel de venta voided lanza SaleNotCancellableException', function () {
    $sale = $this->service->checkout(
        makeCheckoutRequest(
            items: [['product_uuid' => $this->product->uuid, 'quantity' => 1]],
            paymentsData: [['method' => 'cash', 'amount' => 100]],
        ),
        $this->user
    );

    $this->service->cancel($sale, $this->user, 'X');

    expect(fn () => $this->service->cancel($sale->fresh(), $this->user, 'Y'))
        ->toThrow(SaleNotCancellableException::class);
});

it('cancel de venta a crédito devuelve credit_balance al cliente', function () {
    $customer = Customer::factory()->withCredit(10000)->create();
    $sale = $this->service->checkout(
        makeCheckoutRequest(
            items: [['product_uuid' => $this->product->uuid, 'quantity' => 1]],
            paymentsData: [['method' => 'credit', 'amount' => 100]],
            extras: ['customer_uuid' => $customer->uuid],
        ),
        $this->user
    );

    $customer->refresh();
    expect((float) $customer->credit_balance)->toBe(100.0);

    $this->service->cancel($sale, $this->user, 'Cancelada');

    $customer->refresh();
    expect((float) $customer->credit_balance)->toBe(0.0);
});
