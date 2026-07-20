<?php

declare(strict_types=1);

use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Services\CashService;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
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
| 39.1 PRICE_MISMATCH: precio del cache offline difiere del vigente
|--------------------------------------------------------------------------
|
| La venta se ACEPTA con el precio del cliente (39.2 Sales: precio
| congelado); el conflicto es informativo (status success + registro
| en la cola para revision de gerente).
|
*/

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'pm-test', 'country_code' => 'MX']);
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
        'sku' => 'PMPROD-'.uniqid(),
        'price' => 100,
        'track_inventory' => true,
    ]);
    app(InventoryService::class)->recordEntry($this->product, $this->warehouse, 100, 60);
    TenantContext::set($this->tenant);
    $this->service = app(SyncBatchService::class);
});

function pmTestSaleItem(?float $unitPrice, float $payment): SyncBatchItem
{
    $test = test();
    $item = [
        'product_uuid' => $test->product->uuid,
        'quantity' => 1,
    ];
    if ($unitPrice !== null) {
        $item['unit_price'] = $unitPrice;
    }

    return SyncBatchItem::fromArray([
        'client_uuid' => (string) Str::uuid(),
        'entity_type' => 'sale',
        'entity_uuid' => (string) Str::uuid(),
        'operation' => 'create',
        'client_timestamp' => '2026-01-01T10:00:00Z',
        'payload' => [
            'cash_session_uuid' => $test->session->uuid,
            'warehouse_uuid' => $test->warehouse->uuid,
            'items' => [$item],
            'payments' => [[
                'method' => 'cash',
                'amount' => $payment,
                'tendered_amount' => $payment,
            ]],
        ],
    ]);
}

it('precio de cache distinto al vigente persiste PRICE_MISMATCH con venta aceptada', function (): void {
    // Cliente offline vendio a 90 (cache viejo); precio vigente es 100.
    // 90 + IVA 16% = 104.40
    $results = $this->service->process(
        [pmTestSaleItem(90.0, 104.40)],
        $this->cajero,
        (string) Str::uuid(),
        'device-001',
    );
    TenantContext::set($this->tenant);

    expect($results[0]['status'])->toBe('success')
        ->and($results[0]['data']['uuid'])->not->toBeNull();

    $conflict = SyncConflict::query()->firstOrFail();
    expect($conflict->conflict_type)->toBe(SyncConflict::TYPE_PRICE_MISMATCH)
        ->and($conflict->branch_id)->toBe($this->branch->id)
        ->and($conflict->device_id)->toBe('device-001')
        ->and($conflict->resolved_at)->toBeNull()
        ->and($conflict->client_data['items'][0]['unit_price'])->toBe(90)
        ->and($conflict->server_data['items'][0]['unit_price'])->toBe(100);
});

it('override igual al precio vigente no crea conflicto', function (): void {
    $results = $this->service->process(
        [pmTestSaleItem(100.0, 116.00)],
        $this->cajero,
        (string) Str::uuid(),
        'device-001',
    );
    TenantContext::set($this->tenant);

    expect($results[0]['status'])->toBe('success')
        ->and(SyncConflict::query()->count())->toBe(0);
});

it('venta sin override usa precio del servidor y no crea conflicto', function (): void {
    $results = $this->service->process(
        [pmTestSaleItem(null, 116.00)],
        $this->cajero,
        (string) Str::uuid(),
        'device-001',
    );
    TenantContext::set($this->tenant);

    expect($results[0]['status'])->toBe('success')
        ->and(SyncConflict::query()->count())->toBe(0);
});
