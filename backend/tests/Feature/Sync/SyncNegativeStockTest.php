<?php

declare(strict_types=1);

use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Services\CashService;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\Stock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Sales\Dto\CheckoutRequest;
use App\Domain\Sales\Services\SalesService;
use App\Domain\Sync\Dto\SyncBatchItem;
use App\Domain\Sync\Models\SyncConflict;
use App\Domain\Sync\Services\SyncBatchService;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| 39.1 stock insuficiente en sync: acepta venta, permite negativo
|--------------------------------------------------------------------------
|
| La venta offline es historica: se acepta aunque el stock quede
| negativo. Alerta de reabastecimiento via RN-058/RN-190 (canal
| existente); conflicto informativo NEGATIVE_STOCK en la cola 39.3.
| Fuera de sync, checkout sigue lanzando InsufficientStockException.
|
*/

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'ns-test', 'country_code' => 'MX']);
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
    $this->session = app(CashService::class)->openSession($this->register, $this->cajero, 1000);
    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'sku' => 'NSPROD-'.uniqid(),
        'price' => 100,
        'track_inventory' => true,
    ]);
    // Stock inicial 5: la venta de 10 lo deja en -5.
    app(InventoryService::class)->recordEntry($this->product, $this->warehouse, 5, 60);
    TenantContext::set($this->tenant);
    $this->service = app(SyncBatchService::class);
});

function nsTestSaleItem(float $qty, float $payment): SyncBatchItem
{
    $test = test();

    return SyncBatchItem::fromArray([
        'client_uuid' => (string) Str::uuid(),
        'entity_type' => 'sale',
        'entity_uuid' => (string) Str::uuid(),
        'operation' => 'create',
        'client_timestamp' => '2026-01-01T10:00:00Z',
        'payload' => [
            'cash_session_uuid' => $test->session->uuid,
            'warehouse_uuid' => $test->warehouse->uuid,
            'items' => [[
                'product_uuid' => $test->product->uuid,
                'quantity' => $qty,
            ]],
            'payments' => [[
                'method' => 'cash',
                'amount' => $payment,
                'tendered_amount' => $payment,
            ]],
        ],
    ]);
}

it('venta sync con stock insuficiente se acepta y persiste NEGATIVE_STOCK', function (): void {
    // 10 x 100 con IVA 16% = 1160.00; stock inicial 5 => queda -5.
    $results = $this->service->process(
        [nsTestSaleItem(10, 1160.00)],
        $this->cajero,
        (string) Str::uuid(),
        'device-001',
    );
    TenantContext::set($this->tenant);

    expect($results[0]['status'])->toBe('success');

    $stock = Stock::query()
        ->where('product_id', $this->product->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->firstOrFail();
    expect((float) $stock->quantity_on_hand)->toBe(-5.0);

    $conflict = SyncConflict::query()->firstOrFail();
    expect($conflict->conflict_type)->toBe(SyncConflict::TYPE_NEGATIVE_STOCK)
        ->and($conflict->branch_id)->toBe($this->branch->id)
        ->and($conflict->resolved_at)->toBeNull()
        ->and((float) $conflict->server_data['items'][0]['quantity_on_hand'])->toBe(-5.0);
});

it('venta sync con stock suficiente no crea conflicto', function (): void {
    $results = $this->service->process(
        [nsTestSaleItem(3, 348.00)],
        $this->cajero,
        (string) Str::uuid(),
        'device-001',
    );
    TenantContext::set($this->tenant);

    expect($results[0]['status'])->toBe('success')
        ->and(SyncConflict::query()->count())->toBe(0);
});

it('checkout directo sin flag sigue lanzando InsufficientStock', function (): void {
    $dto = CheckoutRequest::fromArray([
        'cash_session_uuid' => $this->session->uuid,
        'warehouse_uuid' => $this->warehouse->uuid,
        'items' => [[
            'product_uuid' => $this->product->uuid,
            'quantity' => 10,
        ]],
        'payments' => [[
            'method' => 'cash',
            'amount' => 1160.00,
            'tendered_amount' => 1160.00,
        ]],
    ]);

    app(SalesService::class)->checkout($dto, $this->cajero);
})->throws(InsufficientStockException::class);
