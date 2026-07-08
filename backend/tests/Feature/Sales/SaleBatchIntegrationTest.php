<?php

declare(strict_types=1);

use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Services\CashService;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Sales\Dto\CheckoutRequest;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Models\SaleItemBatch;
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
        'code' => 'SIB',
    ]);
    $this->warehouse = Warehouse::factory()->default()->ofBranch($this->branch)->create();
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA01']);

    $this->cajero = User::factory()->create(['company_id' => $this->tenant->id]);

    $this->session = app(CashService::class)->openSession($this->register, $this->cajero, 1000);
    $this->service = app(SalesService::class);
    $this->inventory = app(InventoryService::class);
});

function sibCheckout(Product $product, float $quantity, float $total): void
{
    $test = test();

    $req = CheckoutRequest::fromArray([
        'cash_session_uuid' => $test->session->uuid,
        'warehouse_uuid' => $test->warehouse->uuid,
        'items' => [
            ['product_uuid' => $product->uuid, 'quantity' => $quantity],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => $total, 'tendered_amount' => $total],
        ],
        'series' => 'A',
    ]);

    $test->service->checkout($req, $test->cajero);
    TenantContext::set($test->tenant);
}

it('el checkout de producto con lotes crea sale_item_batches FEFO', function (): void {
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'sku' => 'SKU-SIB-1',
        'price' => 100,
        'track_inventory' => true,
        'tracks_lots' => true,
    ]);

    $this->inventory->recordEntry($product, $this->warehouse, 3, 40,
        batch: ['lot_number' => 'L-CERCANO', 'expiration_date' => now()->addWeek()->toDateString()]);
    $this->inventory->recordEntry($product, $this->warehouse, 10, 45,
        batch: ['lot_number' => 'L-LEJANO', 'expiration_date' => now()->addYear()->toDateString()]);
    TenantContext::set($this->tenant);

    sibCheckout($product, 5, 500);

    $saleItem = SaleItem::query()->where('product_id', $product->id)->firstOrFail();
    $rows = SaleItemBatch::query()
        ->where('sale_item_id', $saleItem->id)
        ->orderBy('id')
        ->get();

    $near = Batch::query()->where('lot_number', 'L-CERCANO')->firstOrFail();
    $far = Batch::query()->where('lot_number', 'L-LEJANO')->firstOrFail();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->batch_id)->toBe($near->id)
        ->and((float) $rows[0]->quantity)->toBe(3.0)
        ->and((float) $rows[0]->unit_cost)->toBe(40.0)
        ->and($rows[1]->batch_id)->toBe($far->id)
        ->and((float) $rows[1]->quantity)->toBe(2.0)
        ->and((float) $rows[1]->unit_cost)->toBe(45.0);

    expect((float) $near->quantity)->toBe(0.0)
        ->and((float) $far->quantity)->toBe(8.0);
});

it('el checkout de producto sin lotes no crea sale_item_batches', function (): void {
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'sku' => 'SKU-SIB-2',
        'price' => 50,
        'track_inventory' => true,
        'tracks_lots' => false,
    ]);

    $this->inventory->recordEntry($product, $this->warehouse, 10, 20);
    TenantContext::set($this->tenant);

    sibCheckout($product, 2, 100);

    expect(SaleItemBatch::query()->count())->toBe(0);
});
