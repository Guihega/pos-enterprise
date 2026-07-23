<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Inventory\Models\Stock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;

beforeEach(function () {
    $this->tenant = Company::factory()->create();
    TenantContext::set($this->tenant);

    app(CatalogProvisioner::class)->provision($this->tenant);
    $this->unit = Unit::query()->where('code', 'PZA')->firstOrFail();

    $this->branch = Branch::factory()->default()->create([
        'company_id' => $this->tenant->id,
    ]);
    $this->warehouse = Warehouse::factory()->default()->ofBranch($this->branch)->create();
    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);
});

it('crea un registro de stock', function () {
    $stock = Stock::factory()->ofProduct($this->product, $this->warehouse)->withQuantity(50)->create();

    expect($stock->company_id)->toBe($this->tenant->id)
        ->and((float) $stock->quantity_on_hand)->toBe(50.0);
});

it('rechaza dos stocks para el mismo (product, warehouse)', function () {
    Stock::factory()->ofProduct($this->product, $this->warehouse)->create();

    expectQueryException(function () {
        Stock::factory()->ofProduct($this->product, $this->warehouse)->create();
    });
});

it('permite stock del mismo producto en almacenes distintos', function () {
    $w2 = Warehouse::factory()->ofBranch($this->branch)->storage()->create();

    Stock::factory()->ofProduct($this->product, $this->warehouse)->withQuantity(10)->create();
    Stock::factory()->ofProduct($this->product, $w2)->withQuantity(50)->create();

    expect(Stock::query()->where('product_id', $this->product->id)->count())->toBe(2);
});

it('acepta quantity_on_hand negativo (39.1: venta offline con stock insuficiente)', function () {
    // Migracion 000042 relajo el CHECK: el faltante por ventas sync es
    // senal real de inventario, no un error (ver SyncNegativeStockTest).
    $stock = Stock::factory()->ofProduct($this->product, $this->warehouse)
        ->withQuantity(-5)
        ->create();

    expect((float) $stock->refresh()->quantity_on_hand)->toBe(-5.0);
});

it('rechaza quantity_reserved negativo (check constraint vigente)', function () {
    expectQueryException(function () {
        Stock::factory()->ofProduct($this->product, $this->warehouse)
            ->withQuantity(10, -5)
            ->create();
    });
});

it('quantity_available calcula on_hand - reserved', function () {
    $stock = Stock::factory()->ofProduct($this->product, $this->warehouse)
        ->withQuantity(100, 30)
        ->create();

    expect($stock->quantity_available)->toBe(70.0);
});

it('quantity_available no devuelve negativo si reserved > on_hand', function () {
    // Caso límite: reservas excedieron temporalmente el inventario.
    // El check constraint impide que sea negativo en BD, pero lo respetamos a nivel modelo.
    $stock = Stock::factory()->ofProduct($this->product, $this->warehouse)->create();
    $stock->quantity_on_hand = 5;
    $stock->quantity_reserved = 10;

    expect($stock->quantity_available)->toBe(0.0);
});

it('isLowStock detecta cuando on_hand <= stock_min', function () {
    $stock = Stock::factory()->ofProduct($this->product, $this->warehouse)->create([
        'quantity_on_hand' => 5,
        'stock_min' => 10,
    ]);

    expect($stock->isLowStock())->toBeTrue();

    $stock->quantity_on_hand = 15;
    expect($stock->isLowStock())->toBeFalse();
});

it('isOverstock detecta cuando on_hand >= stock_max', function () {
    $stock = Stock::factory()->ofProduct($this->product, $this->warehouse)->create([
        'quantity_on_hand' => 200,
        'stock_max' => 150,
    ]);

    expect($stock->isOverstock())->toBeTrue();
});

it('aísla stocks entre tenants', function () {
    Stock::factory()->ofProduct($this->product, $this->warehouse)->create();

    $tenantB = Company::factory()->create();
    app(CatalogProvisioner::class)->provision($tenantB);
    TenantContext::set($tenantB);
    $unitB = Unit::query()->where('code', 'PZA')->firstOrFail();
    $branchB = Branch::factory()->default()->create(['company_id' => $tenantB->id]);
    $whB = Warehouse::factory()->default()->ofBranch($branchB)->create();

    // Dos productos distintos para tenant B (cada uno con su propio stock en whB).
    // No usamos count(2) sobre el mismo product+warehouse porque viola el
    // unique compuesto (product_id, warehouse_id).
    $productB1 = Product::factory()->create(['company_id' => $tenantB->id, 'unit_id' => $unitB->id]);
    $productB2 = Product::factory()->create(['company_id' => $tenantB->id, 'unit_id' => $unitB->id]);
    Stock::factory()->ofProduct($productB1, $whB)->create();
    Stock::factory()->ofProduct($productB2, $whB)->create();

    expect(Stock::query()->count())->toBe(2);

    TenantContext::set($this->tenant);
    expect(Stock::query()->count())->toBe(1);
});

it('cascadeOnDelete: borrar producto borra sus stocks', function () {
    Stock::factory()->ofProduct($this->product, $this->warehouse)->create();
    expect(Stock::query()->count())->toBe(1);

    $this->product->forceDelete();

    expect(Stock::query()->count())->toBe(0);
});
