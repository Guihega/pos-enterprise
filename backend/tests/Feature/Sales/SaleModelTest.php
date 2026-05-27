<?php

declare(strict_types=1);

use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Services\CashService;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Models\SalePayment;
use App\Domain\Sales\Models\SaleTax;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;

beforeEach(function () {
    $this->tenant = Company::factory()->create();
    TenantContext::set($this->tenant);

    // Catálogo: necesario para que Product::factory() encuentre Unit
    app(CatalogProvisioner::class)->provision($this->tenant);
    $this->unit = Unit::query()->where('code', 'PZA')->firstOrFail();

    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->warehouse = Warehouse::factory()->default()->ofBranch($this->branch)->create();
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create();
    $this->user = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->session = app(CashService::class)->openSession($this->register, $this->user, 0);

    // Product reusable para sale_items (evita Product::factory() encadenado
    // que invocaría Unit::factory() y crearía tenant nuevo).
    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);
});

// Helpers para minimizar boilerplate

if (! function_exists('makeSale')) {
    /**
     * Crea un Sale con todos los FKs prellenados desde el beforeEach.
     *
     * @param  array<string, mixed>  $overrides
     */
    function makeSale(array $overrides = []): Sale
    {
        $test = test();

        return Sale::factory()->create(array_merge([
            'branch_id' => $test->branch->id,
            'cash_register_id' => $test->register->id,
            'cash_session_id' => $test->session->id,
            'warehouse_id' => $test->warehouse->id,
            'user_id' => $test->user->id,
        ], $overrides));
    }
}

if (! function_exists('makeSaleItem')) {
    /**
     * Crea un SaleItem con product_id del beforeEach (evita encadenar
     * Product::factory() que crearía tenant nuevo).
     *
     * @param  array<string, mixed>  $overrides
     */
    function makeSaleItem(int $saleId, array $overrides = []): SaleItem
    {
        $test = test();

        return SaleItem::factory()->create(array_merge([
            'sale_id' => $saleId,
            'product_id' => $test->product->id,
        ], $overrides));
    }
}

// ====================================================================
//  Sale (encabezado)
// ====================================================================

it('crea una venta con UUID y respeta tenant', function () {
    $sale = makeSale(['number' => 'CTR-CAJA01-A-000001']);

    expect($sale->uuid)->toBeUuid()
        ->and($sale->company_id)->toBe($this->tenant->id)
        ->and($sale->status)->toBe(Sale::STATUS_DRAFT);
});

it('aplica TenantScope al listar ventas', function () {
    makeSale();
    makeSale();

    $tenantB = Company::factory()->create();
    app(CatalogProvisioner::class)->provision($tenantB);
    TenantContext::set($tenantB);
    $branchB = Branch::factory()->default()->create(['company_id' => $tenantB->id]);
    $whB = Warehouse::factory()->default()->ofBranch($branchB)->create();
    $regB = CashRegister::factory()->ofBranch($branchB)->create();
    $userB = User::factory()->create(['company_id' => $tenantB->id]);
    $sessB = app(CashService::class)->openSession($regB, $userB, 0);
    Sale::factory()->count(5)->create([
        'branch_id' => $branchB->id,
        'cash_register_id' => $regB->id,
        'cash_session_id' => $sessB->id,
        'warehouse_id' => $whB->id,
        'user_id' => $userB->id,
    ]);

    expect(Sale::query()->count())->toBe(5);

    TenantContext::set($this->tenant);
    expect(Sale::query()->count())->toBe(2);
});

it('rechaza folio (number) duplicado en mismo tenant', function () {
    makeSale(['number' => 'CTR-CAJA01-A-000001']);

    expectQueryException(function () {
        makeSale(['number' => 'CTR-CAJA01-A-000001']);
    });
});

it('rechaza totales negativos (check constraint)', function () {
    expectQueryException(function () {
        makeSale(['total_amount' => -10]);
    });
});

it('balanceDue calcula total - paid', function () {
    $sale = makeSale(['total_amount' => 500, 'paid_amount' => 350]);

    expect($sale->balanceDue())->toBe(150.0)
        ->and($sale->isFullyPaid())->toBeFalse();
});

it('isFullyPaid devuelve true cuando paid_amount >= total_amount', function () {
    $sale = makeSale(['total_amount' => 100, 'paid_amount' => 100]);

    expect($sale->isFullyPaid())->toBeTrue()
        ->and($sale->balanceDue())->toBe(0.0);
});

it('scope completed filtra solo ventas completed', function () {
    Sale::factory()->completed(100)->create([
        'branch_id' => $this->branch->id,
        'cash_register_id' => $this->register->id,
        'cash_session_id' => $this->session->id,
        'warehouse_id' => $this->warehouse->id,
        'user_id' => $this->user->id,
        'number' => 'A-001',
    ]);
    Sale::factory()->voided()->create([
        'branch_id' => $this->branch->id,
        'cash_register_id' => $this->register->id,
        'cash_session_id' => $this->session->id,
        'warehouse_id' => $this->warehouse->id,
        'user_id' => $this->user->id,
        'number' => 'A-002',
    ]);
    makeSale(['number' => 'A-003']);  // draft

    expect(Sale::query()->completed()->count())->toBe(1);
});

it('schema sentinel: sales tiene status enum con draft/completed/voided/refunded', function () {
    $sql = "
        SELECT pg_get_constraintdef(c.oid) AS def
        FROM pg_constraint c
        JOIN pg_class t ON t.oid = c.conrelid
        WHERE t.relname = 'sales' AND c.contype = 'c'
          AND pg_get_constraintdef(c.oid) ILIKE '%status%'
    ";
    $rows = \DB::select($sql);
    $combined = implode(' ', array_map(fn ($r) => $r->def, $rows));

    expect($combined)->toContain('draft')
        ->and($combined)->toContain('completed')
        ->and($combined)->toContain('voided')
        ->and($combined)->toContain('refunded');
});

// ====================================================================
//  SaleItem
// ====================================================================

it('crea sale_items asociados a una venta', function () {
    $sale = makeSale();

    makeSaleItem($sale->id);
    makeSaleItem($sale->id);
    makeSaleItem($sale->id);

    expect($sale->items()->count())->toBe(3);
});

it('rechaza sale_item con quantity <= 0 (check)', function () {
    $sale = makeSale();

    expectQueryException(function () use ($sale) {
        makeSaleItem($sale->id, ['quantity' => 0]);
    });
});

it('rechaza sale_item con discount_percent > 100', function () {
    $sale = makeSale();

    expectQueryException(function () use ($sale) {
        makeSaleItem($sale->id, ['discount_percent' => 150]);
    });
});

it('borrar sale borra sus items en cascada', function () {
    $sale = makeSale();
    makeSaleItem($sale->id);
    makeSaleItem($sale->id);

    $sale->delete();

    expect(SaleItem::query()->where('sale_id', $sale->id)->count())->toBe(0);
});

// ====================================================================
//  SalePayment
// ====================================================================

it('soporta multi-payment: efectivo + tarjeta en misma venta', function () {
    $sale = makeSale();

    SalePayment::factory()->cash(500)->create(['sale_id' => $sale->id]);
    SalePayment::factory()->card(500)->create(['sale_id' => $sale->id]);

    expect($sale->payments()->count())->toBe(2)
        ->and((float) $sale->payments()->sum('amount'))->toBe(1000.0);
});

it('rechaza payment con amount <= 0 (check)', function () {
    $sale = makeSale();

    expectQueryException(function () use ($sale) {
        SalePayment::factory()->create([
            'sale_id' => $sale->id,
            'amount' => 0,
        ]);
    });
});

it('SalePayment::isCash detecta correctamente', function () {
    // Usamos make() (no persiste) y forzamos sale_id=1 para evitar que la
    // factory resuelva Sale::factory() encadenado, que dispararía la cadena
    // Branch::factory() → Company::factory() → conflicto cross-tenant.
    $cash = SalePayment::factory()->cash(100)->make(['sale_id' => 1]);
    $card = SalePayment::factory()->card(100)->make(['sale_id' => 1]);

    expect($cash->isCash())->toBeTrue()
        ->and($cash->affectsCash())->toBeTrue()
        ->and($card->isCash())->toBeFalse()
        ->and($card->affectsCash())->toBeFalse();
});

// ====================================================================
//  SaleTax
// ====================================================================

it('crea sale_taxes con desglose por código', function () {
    $sale = makeSale();

    SaleTax::factory()->create([
        'sale_id' => $sale->id,
        'code' => 'IVA-16', 'rate' => 0.16,
        'taxable_base' => 850, 'amount' => 136,
    ]);
    SaleTax::factory()->create([
        'sale_id' => $sale->id,
        'code' => 'IVA-8', 'rate' => 0.08,
        'taxable_base' => 200, 'amount' => 16,
    ]);

    expect($sale->taxes()->count())->toBe(2);
});

it('rechaza dos sale_taxes con mismo code en misma venta', function () {
    $sale = makeSale();

    SaleTax::factory()->create(['sale_id' => $sale->id, 'code' => 'IVA-16']);

    expectQueryException(function () use ($sale) {
        SaleTax::factory()->create(['sale_id' => $sale->id, 'code' => 'IVA-16']);
    });
});

// ====================================================================
//  Customer relation
// ====================================================================

it('venta puede tener customer_id null (público en general)', function () {
    $sale = makeSale(['customer_id' => null]);

    expect($sale->customer_id)->toBeNull()
        ->and($sale->customer)->toBeNull();
});

it('venta con customer denormaliza nombre y tax_id', function () {
    $customer = Customer::factory()->business()->create();

    $sale = makeSale([
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'customer_tax_id' => $customer->tax_id,
    ]);

    expect($sale->customer_name)->toBe($customer->name)
        ->and($sale->customer_tax_id)->toBe($customer->tax_id);
});
