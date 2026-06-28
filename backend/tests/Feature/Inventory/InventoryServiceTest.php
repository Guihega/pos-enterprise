<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\Stock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;

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

    $this->service = app(InventoryService::class);
});

// ====================================================================
//  Entries
// ====================================================================

it('recordEntry crea stock si no existe y registra movimiento', function () {
    $movement = $this->service->recordEntry(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: 10,
        unitCost: 50,
    );

    expect($movement->type)->toBe(InventoryMovement::TYPE_ENTRY)
        ->and((float) $movement->quantity_delta)->toBe(10.0)
        ->and((float) $movement->quantity_after)->toBe(10.0)
        ->and((float) $movement->unit_cost)->toBe(50.0)
        ->and((float) $movement->total_cost)->toBe(500.0);

    $stock = Stock::query()
        ->where('product_id', $this->product->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->firstOrFail();

    expect((float) $stock->quantity_on_hand)->toBe(10.0)
        ->and((float) $stock->average_cost)->toBe(50.0);
});

it('recordEntry suma a stock existente', function () {
    $this->service->recordEntry($this->product, $this->warehouse, 10, 50);
    $this->service->recordEntry($this->product, $this->warehouse, 5, 60);

    $stock = Stock::query()
        ->where('product_id', $this->product->id)
        ->firstOrFail();
    expect((float) $stock->quantity_on_hand)->toBe(15.0);
});

it('costo promedio ponderado se recalcula correctamente', function () {
    // Compra 1: 10 unidades a $50 → avg = 50
    $this->service->recordEntry($this->product, $this->warehouse, 10, 50);
    // Compra 2: 10 unidades a $70 → avg = (10*50 + 10*70) / 20 = 60
    $this->service->recordEntry($this->product, $this->warehouse, 10, 70);

    $stock = Stock::query()->where('product_id', $this->product->id)->firstOrFail();
    expect((float) $stock->average_cost)->toBe(60.0);
});

it('recordEntry con cantidad 0 o negativa lanza error', function () {
    expect(fn () => $this->service->recordEntry($this->product, $this->warehouse, 0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->service->recordEntry($this->product, $this->warehouse, -5))
        ->toThrow(InvalidArgumentException::class);
});

// ====================================================================
//  Exits
// ====================================================================

it('recordExit descuenta stock y registra movimiento', function () {
    $this->service->recordEntry($this->product, $this->warehouse, 20, 50);

    $movement = $this->service->recordExit($this->product, $this->warehouse, 8);

    expect((float) $movement->quantity_delta)->toBe(-8.0)
        ->and((float) $movement->quantity_after)->toBe(12.0);

    $stock = Stock::query()->where('product_id', $this->product->id)->firstOrFail();
    expect((float) $stock->quantity_on_hand)->toBe(12.0);
});

it('recordExit sin stock suficiente lanza InsufficientStockException', function () {
    $this->service->recordEntry($this->product, $this->warehouse, 5, 50);

    expect(fn () => $this->service->recordExit($this->product, $this->warehouse, 10))
        ->toThrow(InsufficientStockException::class);
});

it('recordExit usa costo promedio actual como unit_cost', function () {
    $this->service->recordEntry($this->product, $this->warehouse, 10, 50);
    $this->service->recordEntry($this->product, $this->warehouse, 10, 70);
    // avg = 60

    $movement = $this->service->recordExit($this->product, $this->warehouse, 5);

    expect((float) $movement->unit_cost)->toBe(60.0)
        ->and((float) $movement->total_cost)->toBe(300.0);
});

// ====================================================================
//  Adjustments
// ====================================================================

it('adjust positivo aumenta stock con tipo adjustment y reason', function () {
    $this->service->recordEntry($this->product, $this->warehouse, 10);

    $movement = $this->service->adjust(
        $this->product, $this->warehouse, 5, 'Conteo físico encontró 5 unidades extra'
    );

    expect($movement->type)->toBe(InventoryMovement::TYPE_ADJUSTMENT)
        ->and((float) $movement->quantity_delta)->toBe(5.0)
        ->and($movement->reason)->toContain('Conteo físico');

    $stock = Stock::query()->where('product_id', $this->product->id)->firstOrFail();
    expect((float) $stock->quantity_on_hand)->toBe(15.0);
});

it('adjust negativo disminuye stock', function () {
    $this->service->recordEntry($this->product, $this->warehouse, 20);

    $movement = $this->service->adjust(
        $this->product, $this->warehouse, -3, 'Merma'
    );

    expect((float) $movement->quantity_delta)->toBe(-3.0);

    $stock = Stock::query()->where('product_id', $this->product->id)->firstOrFail();
    expect((float) $stock->quantity_on_hand)->toBe(17.0);
});

it('adjust con delta=0 lanza error', function () {
    expect(fn () => $this->service->adjust($this->product, $this->warehouse, 0, 'X'))
        ->toThrow(InvalidArgumentException::class);
});

it('adjust sin reason lanza error', function () {
    expect(fn () => $this->service->adjust($this->product, $this->warehouse, 5, '   '))
        ->toThrow(InvalidArgumentException::class);
});

// ====================================================================
//  Transfers
// ====================================================================

it('transfer mueve stock entre dos warehouses con transfer_id compartido', function () {
    $whB = Warehouse::factory()->ofBranch($this->branch)->storage()->create();
    $this->service->recordEntry($this->product, $this->warehouse, 20, 50);

    $result = $this->service->transfer($this->product, $this->warehouse, $whB, 7);

    expect($result['transfer_id'])->toBeUuid()
        ->and($result['out']->transfer_id)->toBe($result['transfer_id'])
        ->and($result['in']->transfer_id)->toBe($result['transfer_id'])
        ->and($result['out']->type)->toBe(InventoryMovement::TYPE_TRANSFER_OUT)
        ->and($result['in']->type)->toBe(InventoryMovement::TYPE_TRANSFER_IN);

    $stockA = Stock::query()->where('warehouse_id', $this->warehouse->id)->firstOrFail();
    $stockB = Stock::query()->where('warehouse_id', $whB->id)->firstOrFail();

    expect((float) $stockA->quantity_on_hand)->toBe(13.0)
        ->and((float) $stockB->quantity_on_hand)->toBe(7.0);
});

it('transfer al mismo warehouse lanza error', function () {
    expect(fn () => $this->service->transfer($this->product, $this->warehouse, $this->warehouse, 5))
        ->toThrow(InvalidArgumentException::class);
});

it('transfer con stock insuficiente revierte la transacción completa', function () {
    $whB = Warehouse::factory()->ofBranch($this->branch)->create();
    $this->service->recordEntry($this->product, $this->warehouse, 5);

    expect(fn () => $this->service->transfer($this->product, $this->warehouse, $whB, 10))
        ->toThrow(InsufficientStockException::class);

    // El stock del origen NO debe haber cambiado
    $stockA = Stock::query()->where('warehouse_id', $this->warehouse->id)->firstOrFail();
    expect((float) $stockA->quantity_on_hand)->toBe(5.0);

    // No debe haber stock en el destino (no se llegó a crear)
    $stockB = Stock::query()->where('warehouse_id', $whB->id)->first();
    expect($stockB)->toBeNull();
});

// ====================================================================
//  Inmutabilidad del kardex
// ====================================================================

it('kardex es inmutable: UPDATE bloqueado por trigger BD', function () {
    $movement = $this->service->recordEntry($this->product, $this->warehouse, 5, 50);

    expectQueryException(function () use ($movement) {
        DB::table('inventory_movements')
            ->where('id', $movement->id)
            ->update(['quantity_delta' => 9999]);
    });
});

it('kardex es inmutable: DELETE bloqueado por trigger BD', function () {
    $movement = $this->service->recordEntry($this->product, $this->warehouse, 5, 50);

    expectQueryException(function () use ($movement) {
        DB::table('inventory_movements')
            ->where('id', $movement->id)
            ->delete();
    });
});

// ====================================================================
//  Integridad: invariante "suma de deltas = quantity_on_hand"
// ====================================================================

it('invariante: suma de deltas de un producto/almacén = quantity_on_hand', function () {
    $this->service->recordEntry($this->product, $this->warehouse, 100, 10);
    $this->service->recordExit($this->product, $this->warehouse, 30);
    $this->service->adjust($this->product, $this->warehouse, -5, 'Merma');
    $this->service->recordEntry($this->product, $this->warehouse, 20, 12);

    $sumDeltas = (float) InventoryMovement::query()
        ->where('product_id', $this->product->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->sum('quantity_delta');

    $stock = Stock::query()->where('product_id', $this->product->id)->firstOrFail();

    expect((float) $stock->quantity_on_hand)->toBe($sumDeltas);
    // 100 - 30 - 5 + 20 = 85
    expect((float) $stock->quantity_on_hand)->toBe(85.0);
});
