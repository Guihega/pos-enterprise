<?php

declare(strict_types=1);

use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Inventory\Exceptions\ExpiredBatchException;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
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

    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id, 'code' => 'FEF']);
    $this->warehouse = Warehouse::factory()->default()->ofBranch($this->branch)->create();

    $unit = Unit::query()->where('code', 'PZA')->firstOrFail();
    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $unit->id,
        'sku' => 'SKU-FEFO-1',
        'price' => 100,
        'track_inventory' => true,
        'tracks_lots' => true,
    ]);

    $this->service = app(InventoryService::class);
});

function fefoEntry(string $lot, ?string $expiration, float $qty): void
{
    $test = test();

    $test->service->recordEntry(
        $test->product,
        $test->warehouse,
        $qty,
        batch: ['lot_number' => $lot, 'expiration_date' => $expiration],
    );
}

function fefoBatch(string $lot): Batch
{
    TenantContext::set(test()->tenant);

    return Batch::query()->where('lot_number', $lot)->firstOrFail();
}

it('consume primero el lote con caducidad mas proxima (RN-045)', function (): void {
    fefoEntry('L-LEJANO', now()->addYear()->toDateString(), 10);
    fefoEntry('L-PROXIMO', now()->addWeek()->toDateString(), 10);

    $consumption = null;
    $this->service->recordExit(
        $this->product,
        $this->warehouse,
        4,
        batchConsumption: $consumption,
    );

    expect((float) fefoBatch('L-PROXIMO')->quantity)->toBe(6.0)
        ->and((float) fefoBatch('L-LEJANO')->quantity)->toBe(10.0);

    expect($consumption)->toHaveCount(1)
        ->and($consumption[0]['batch_id'])->toBe(fefoBatch('L-PROXIMO')->id)
        ->and($consumption[0]['quantity'])->toBe(4.0);
});

it('cruza lotes cuando el primero no alcanza y respeta el orden', function (): void {
    fefoEntry('L-A', now()->addWeek()->toDateString(), 3);
    fefoEntry('L-B', now()->addMonth()->toDateString(), 10);

    $consumption = null;
    $this->service->recordExit(
        $this->product,
        $this->warehouse,
        5,
        batchConsumption: $consumption,
    );

    expect((float) fefoBatch('L-A')->quantity)->toBe(0.0)
        ->and((float) fefoBatch('L-B')->quantity)->toBe(8.0)
        ->and($consumption)->toHaveCount(2)
        ->and($consumption[0]['quantity'])->toBe(3.0)
        ->and($consumption[1]['quantity'])->toBe(2.0);
});

it('los lotes sin caducidad se consumen al final (RN-046 + FEFO)', function (): void {
    fefoEntry('L-SIN-CAD', null, 10);
    fefoEntry('L-CON-CAD', now()->addMonth()->toDateString(), 10);

    $this->service->recordExit($this->product, $this->warehouse, 4);

    expect((float) fefoBatch('L-CON-CAD')->quantity)->toBe(6.0)
        ->and((float) fefoBatch('L-SIN-CAD')->quantity)->toBe(10.0);
});

it('la venta se bloquea si el lote FEFO esta vencido (EX-041)', function (): void {
    fefoEntry('L-VENCIDO', now()->subDay()->toDateString(), 10);

    $this->service->recordExit(
        $this->product,
        $this->warehouse,
        2,
        type: InventoryMovement::TYPE_EXIT,
    );
})->throws(ExpiredBatchException::class);

it('la merma por ajuste SI puede sacar lote vencido (EX-049)', function (): void {
    fefoEntry('L-VENCIDO-2', now()->subDay()->toDateString(), 10);

    $this->service->adjust($this->product, $this->warehouse, -10, 'merma por caducidad');

    expect((float) fefoBatch('L-VENCIDO-2')->quantity)->toBe(0.0);
});

it('mantiene el invariante: suma de lotes igual al stock tras entradas y salidas', function (): void {
    fefoEntry('L-INV-1', now()->addWeek()->toDateString(), 7);
    fefoEntry('L-INV-2', now()->addMonth()->toDateString(), 5);

    $this->service->recordExit($this->product, $this->warehouse, 9);

    TenantContext::set($this->tenant);
    $batchSum = (float) Batch::query()->sum('quantity');

    expect($batchSum)->toBe(3.0);
});

it('una salida de producto sin lotes no toca product_batches ni consume', function (): void {
    $plain = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => Unit::query()->where('code', 'PZA')->firstOrFail()->id,
        'sku' => 'SKU-PLANO-2',
        'price' => 10,
        'track_inventory' => true,
        'tracks_lots' => false,
    ]);
    $this->service->recordEntry($plain, $this->warehouse, 10);

    $consumption = null;
    $this->service->recordExit($plain, $this->warehouse, 5, batchConsumption: $consumption);

    expect($consumption)->toBeNull();
    expect(Batch::query()->count())->toBe(0);
});
