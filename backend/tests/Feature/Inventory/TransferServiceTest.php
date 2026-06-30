<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\InvalidTransferTransitionException;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\Stock;
use App\Domain\Inventory\Models\Transfer;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Inventory\Services\TransferService;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;

beforeEach(function () {
    $this->tenant = Company::factory()->create();
    TenantContext::set($this->tenant);

    app(CatalogProvisioner::class)->provision($this->tenant);
    $this->unit = Unit::query()->where('code', 'PZA')->firstOrFail();

    // Dos sucursales: origen (A) y destino (B), cada una con su almacen default.
    $this->branchA = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->branchB = Branch::factory()->create(['company_id' => $this->tenant->id]);
    $this->warehouseA = Warehouse::factory()->default()->ofBranch($this->branchA)->create();
    $this->warehouseB = Warehouse::factory()->default()->ofBranch($this->branchB)->create();

    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);

    $this->inventory = app(InventoryService::class);
    $this->service = app(TransferService::class);

    // Stock inicial en el almacen origen.
    $this->inventory->recordEntry($this->product, $this->warehouseA, 100, 10);
});

function stockOf(int $warehouseId): float
{
    $stock = Stock::query()->where('warehouse_id', $warehouseId)->first();

    return $stock !== null ? (float) $stock->quantity_on_hand : 0.0;
}

// ====================================================================
//  create
// ====================================================================

it('create crea una transferencia en draft con sus lineas y sin tocar stock', function () {
    $transfer = $this->service->create(
        fromBranch: $this->branchA,
        toBranch: $this->branchB,
        lines: [['product' => $this->product, 'quantity' => 10, 'unit_cost' => 10]],
    );

    expect($transfer->status)->toBe(Transfer::STATUS_DRAFT)
        ->and($transfer->items)->toHaveCount(1)
        ->and((float) $transfer->items->first()->quantity_sent)->toBe(10.0)
        ->and($transfer->folio)->toStartWith('TR-');

    // El stock no debe haberse movido al crear.
    expect(stockOf($this->warehouseA->id))->toBe(100.0)
        ->and(stockOf($this->warehouseB->id))->toBe(0.0);
});

it('create con sucursales iguales lanza error', function () {
    expect(fn () => $this->service->create(
        fromBranch: $this->branchA,
        toBranch: $this->branchA,
        lines: [['product' => $this->product, 'quantity' => 5]],
    ))->toThrow(InvalidArgumentException::class);
});

it('create sin lineas lanza error', function () {
    expect(fn () => $this->service->create(
        fromBranch: $this->branchA,
        toBranch: $this->branchB,
        lines: [],
    ))->toThrow(InvalidArgumentException::class);
});

// ====================================================================
//  send
// ====================================================================

it('send descuenta stock del origen y deja la transferencia en sent', function () {
    $transfer = $this->service->create(
        fromBranch: $this->branchA,
        toBranch: $this->branchB,
        lines: [['product' => $this->product, 'quantity' => 30]],
    );

    $sent = $this->service->send($transfer);

    expect($sent->status)->toBe(Transfer::STATUS_SENT)
        ->and($sent->sent_at)->not->toBeNull();

    // Stock origen baja; destino aun no entra.
    expect(stockOf($this->warehouseA->id))->toBe(70.0)
        ->and(stockOf($this->warehouseB->id))->toBe(0.0);

    // Movimiento transfer_out registrado y ligado por transfer_id = uuid.
    $out = InventoryMovement::query()
        ->where('transfer_id', $transfer->uuid)
        ->where('type', InventoryMovement::TYPE_TRANSFER_OUT)
        ->firstOrFail();
    expect((float) $out->quantity_delta)->toBe(-30.0);
});

it('send con stock insuficiente lanza excepcion y no cambia estado', function () {
    $transfer = $this->service->create(
        fromBranch: $this->branchA,
        toBranch: $this->branchB,
        lines: [['product' => $this->product, 'quantity' => 999]],
    );

    expect(fn () => $this->service->send($transfer))
        ->toThrow(InsufficientStockException::class);
});

// ====================================================================
//  receive (completo y con merma)
// ====================================================================

it('receive completo ingresa todo el stock al destino', function () {
    $transfer = $this->service->create(
        fromBranch: $this->branchA,
        toBranch: $this->branchB,
        lines: [['product' => $this->product, 'quantity' => 40]],
    );
    $transfer = $this->service->send($transfer);

    $received = $this->service->receive($transfer);

    expect($received->status)->toBe(Transfer::STATUS_RECEIVED)
        ->and(stockOf($this->warehouseB->id))->toBe(40.0)
        ->and((float) $received->items->first()->quantity_received)->toBe(40.0);
});

it('receive con merma ingresa solo lo recibido y registra transfer_loss', function () {
    $transfer = $this->service->create(
        fromBranch: $this->branchA,
        toBranch: $this->branchB,
        lines: [['product' => $this->product, 'quantity' => 40]],
    );
    $transfer = $this->service->send($transfer);
    $itemId = $transfer->items->first()->id;

    // Llegaron solo 35 de 40 (merma de 5).
    $received = $this->service->receive($transfer, [$itemId => 35]);

    // RN-049: entra todo lo despachado (40) y la merma (5) se descuenta como
    // ajuste trazable transfer_loss. Stock neto destino = 35.
    $loss = InventoryMovement::query()
        ->where('warehouse_id', $this->warehouseB->id)
        ->where('type', InventoryMovement::TYPE_ADJUSTMENT)
        ->where('reason', 'transfer_loss')
        ->first();

    expect($received->status)->toBe(Transfer::STATUS_RECEIVED)
        ->and((float) $received->items->first()->quantity_received)->toBe(35.0)
        ->and(stockOf($this->warehouseB->id))->toBe(35.0)
        ->and($loss)->not->toBeNull()
        ->and((float) $loss->quantity_delta)->toBe(-5.0);
});

it('receive con cantidad mayor a la enviada lanza error', function () {
    $transfer = $this->service->create(
        fromBranch: $this->branchA,
        toBranch: $this->branchB,
        lines: [['product' => $this->product, 'quantity' => 10]],
    );
    $transfer = $this->service->send($transfer);
    $itemId = $transfer->items->first()->id;

    expect(fn () => $this->service->receive($transfer, [$itemId => 20]))
        ->toThrow(InvalidArgumentException::class);
});

// ====================================================================
//  returnToOrigin
// ====================================================================

it('returnToOrigin reingresa el stock al almacen origen', function () {
    $transfer = $this->service->create(
        fromBranch: $this->branchA,
        toBranch: $this->branchB,
        lines: [['product' => $this->product, 'quantity' => 25]],
    );
    $transfer = $this->service->send($transfer);
    // Tras enviar: origen 75.
    expect(stockOf($this->warehouseA->id))->toBe(75.0);

    $returned = $this->service->returnToOrigin($transfer);

    expect($returned->status)->toBe(Transfer::STATUS_RETURNED_TO_ORIGIN)
        // El stock vuelve completo al origen.
        ->and(stockOf($this->warehouseA->id))->toBe(100.0)
        ->and(stockOf($this->warehouseB->id))->toBe(0.0);
});

// ====================================================================
//  cancel
// ====================================================================

it('cancel desde draft marca cancelled sin tocar stock', function () {
    $transfer = $this->service->create(
        fromBranch: $this->branchA,
        toBranch: $this->branchB,
        lines: [['product' => $this->product, 'quantity' => 10]],
    );

    $cancelled = $this->service->cancel($transfer, 'Pedido erroneo');

    expect($cancelled->status)->toBe(Transfer::STATUS_CANCELLED)
        ->and($cancelled->cancellation_reason)->toBe('Pedido erroneo')
        ->and(stockOf($this->warehouseA->id))->toBe(100.0);
});

// ====================================================================
//  Transiciones invalidas (maquina de estados 14.5)
// ====================================================================

it('no permite recibir una transferencia en draft', function () {
    $transfer = $this->service->create(
        fromBranch: $this->branchA,
        toBranch: $this->branchB,
        lines: [['product' => $this->product, 'quantity' => 10]],
    );

    expect(fn () => $this->service->receive($transfer))
        ->toThrow(InvalidTransferTransitionException::class);
});

it('no permite cancelar una transferencia ya recibida (estado terminal)', function () {
    $transfer = $this->service->create(
        fromBranch: $this->branchA,
        toBranch: $this->branchB,
        lines: [['product' => $this->product, 'quantity' => 10]],
    );
    $transfer = $this->service->send($transfer);
    $transfer = $this->service->receive($transfer);

    expect(fn () => $this->service->cancel($transfer))
        ->toThrow(InvalidTransferTransitionException::class);
});

it('no permite enviar dos veces la misma transferencia', function () {
    $transfer = $this->service->create(
        fromBranch: $this->branchA,
        toBranch: $this->branchB,
        lines: [['product' => $this->product, 'quantity' => 10]],
    );
    $transfer = $this->service->send($transfer);

    expect(fn () => $this->service->send($transfer))
        ->toThrow(InvalidTransferTransitionException::class);
});
