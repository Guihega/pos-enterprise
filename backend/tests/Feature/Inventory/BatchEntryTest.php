<?php

declare(strict_types=1);

use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Inventory\Models\Batch;
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

    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id, 'code' => 'LOT']);
    $this->warehouse = Warehouse::factory()->default()->ofBranch($this->branch)->create();

    $unit = Unit::query()->where('code', 'PZA')->firstOrFail();

    $this->lotProduct = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $unit->id,
        'sku' => 'SKU-LOTE-1',
        'price' => 100,
        'track_inventory' => true,
        'tracks_lots' => true,
    ]);

    $this->plainProduct = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $unit->id,
        'sku' => 'SKU-PLANO-1',
        'price' => 50,
        'track_inventory' => true,
        'tracks_lots' => false,
    ]);

    $this->service = app(InventoryService::class);
});

function batchEntryCount(Company $tenant): int
{
    TenantContext::set($tenant);

    return Batch::query()->count();
}

it('una entrada con lote crea el batch con remanente igual a lo recibido', function (): void {
    $this->service->recordEntry(
        $this->lotProduct,
        $this->warehouse,
        10,
        25.5,
        batch: ['lot_number' => 'L-2026-01', 'expiration_date' => '2026-12-31'],
    );

    TenantContext::set($this->tenant);

    $batch = Batch::query()->firstOrFail();
    expect($batch->lot_number)->toBe('L-2026-01')
        ->and($batch->expiration_date->toDateString())->toBe('2026-12-31')
        ->and((float) $batch->received_quantity)->toBe(10.0)
        ->and((float) $batch->quantity)->toBe(10.0)
        ->and((float) $batch->cost)->toBe(25.5)
        ->and($batch->product_id)->toBe($this->lotProduct->id)
        ->and($batch->branch_id)->toBe($this->branch->id)
        ->and($batch->warehouse_id)->toBe($this->warehouse->id);
});

it('permite lote sin caducidad (RN-046)', function (): void {
    $this->service->recordEntry(
        $this->lotProduct,
        $this->warehouse,
        5,
        batch: ['lot_number' => 'L-SIN-CAD'],
    );

    TenantContext::set($this->tenant);

    $batch = Batch::query()->firstOrFail();
    expect($batch->expiration_date)->toBeNull()
        ->and($batch->lot_number)->toBe('L-SIN-CAD');
});

it('rechaza capturar caducidad en producto sin tracks_lots (RN-034)', function (): void {
    $this->service->recordEntry(
        $this->plainProduct,
        $this->warehouse,
        5,
        batch: ['expiration_date' => '2026-12-31'],
    );
})->throws(InvalidArgumentException::class);

it('una entrada sin datos de lote no crea batch aunque el producto maneje lotes', function (): void {
    $this->service->recordEntry($this->lotProduct, $this->warehouse, 8);

    expect(batchEntryCount($this->tenant))->toBe(0);
});

it('una entrada con lote en producto sin tracks_lots no crea batch', function (): void {
    $this->service->recordEntry(
        $this->plainProduct,
        $this->warehouse,
        5,
        batch: ['lot_number' => 'L-IGNORADO'],
    );

    expect(batchEntryCount($this->tenant))->toBe(0);
});

it('el stock y el movimiento se registran igual con o sin lote', function (): void {
    $movement = $this->service->recordEntry(
        $this->lotProduct,
        $this->warehouse,
        10,
        25.5,
        batch: ['lot_number' => 'L-STOCK'],
    );

    expect((float) $movement->quantity_after)->toBe(10.0);

    TenantContext::set($this->tenant);
    expect(batchEntryCount($this->tenant))->toBe(1);
});
