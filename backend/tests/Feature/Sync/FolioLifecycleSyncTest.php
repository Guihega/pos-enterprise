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
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleNumberRange;
use App\Domain\Sales\Services\FolioRangeService;
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
| ADR-0009 paso 3: ciclo de vida de folios de rango en sync
|--------------------------------------------------------------------------
|
| Venta sincronizada con folio del rango reservado del device lo conserva;
| folio invalido cae al generador central (EX-118: reasignacion implicita,
| el folio del servidor viaja en la respuesta y el cliente actualiza).
|
*/

beforeEach(function (): void {
    $this->tenant = Company::factory()->create();
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
        'sku' => 'PROD-'.uniqid(),
        'price' => 100,
        'track_inventory' => true,
    ]);
    app(InventoryService::class)->recordEntry($this->product, $this->warehouse, 100, 60);
    TenantContext::set($this->tenant);

    $this->service = app(SyncBatchService::class);
});

function folioLifecycleItem(int $numberValue): SyncBatchItem
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
            'number_value' => $numberValue,
            'items' => [[
                'product_uuid' => $test->product->uuid,
                'quantity' => 1,
            ]],
            'payments' => [[
                'method' => 'cash',
                'amount' => 116.00,
                'tendered_amount' => 116.00,
            ]],
        ],
    ]);
}

it('conserva el folio del cliente cuando cae en su rango reservado', function (): void {
    app(FolioRangeService::class)->reserve($this->register, 'A', 'device-001', 50);

    $results = $this->service->process(
        [folioLifecycleItem(5)],
        $this->cajero,
        (string) Str::uuid(),
        'device-001',
    );
    TenantContext::set($this->tenant);

    expect($results[0]['status'])->toBe('success');
    expect($results[0]['data']['folio'])->toBe('CTR-CAJA01-A-000005');

    $sale = Sale::query()->firstOrFail();
    expect((int) $sale->number_value)->toBe(5);
});

it('consume range_end via sync y marca el rango agotado', function (): void {
    app(FolioRangeService::class)->reserve($this->register, 'A', 'device-001', 50);

    $results = $this->service->process(
        [folioLifecycleItem(50)],
        $this->cajero,
        (string) Str::uuid(),
        'device-001',
    );
    TenantContext::set($this->tenant);

    expect($results[0]['status'])->toBe('success');
    $range = SaleNumberRange::query()->where('device_id', 'device-001')->first();
    expect($range->exhausted_at)->not->toBeNull();
});

it('folio fuera de rango cae al generador y devuelve el folio del servidor', function (): void {
    app(FolioRangeService::class)->reserve($this->register, 'A', 'device-001', 50);

    // 999 esta fuera del rango [1, 50] reservado.
    $results = $this->service->process(
        [folioLifecycleItem(999)],
        $this->cajero,
        (string) Str::uuid(),
        'device-001',
    );
    TenantContext::set($this->tenant);

    expect($results[0]['status'])->toBe('success');
    // El generador central arranca despues del techo repartido (50).
    expect($results[0]['data']['folio'])->toBe('CTR-CAJA01-A-000051');

    $sale = Sale::query()->firstOrFail();
    expect((int) $sale->number_value)->toBe(51);
});

it('sin device_id en el batch usa el generador central (contrato previo intacto)', function (): void {
    $results = $this->service->process(
        [folioLifecycleItem(5)],
        $this->cajero,
        (string) Str::uuid(),
        null,
    );
    TenantContext::set($this->tenant);

    expect($results[0]['status'])->toBe('success');
    expect($results[0]['data']['folio'])->toBe('CTR-CAJA01-A-000001');
});

it('number_value repetido en dos batches persiste conflicto DUPLICATE_FOLIO', function (): void {
    app(FolioRangeService::class)->reserve($this->register, 'A', 'device-001', 50);

    // Primer batch consume el folio 7 con exito.
    $first = $this->service->process(
        [folioLifecycleItem(7)],
        $this->cajero,
        (string) Str::uuid(),
        'device-001',
    );
    TenantContext::set($this->tenant);
    expect($first[0]['status'])->toBe('success');

    // Segundo batch (crash del cliente, folio local no actualizado)
    // repite el mismo number_value: consume() lo acepta de nuevo (no
    // trackea consumidos) y el unique (company_id, number) revienta.
    $second = $this->service->process(
        [folioLifecycleItem(7)],
        $this->cajero,
        (string) Str::uuid(),
        'device-001',
    );
    TenantContext::set($this->tenant);

    expect($second[0]['status'])->toBe('conflict');

    $conflict = SyncConflict::query()
        ->where('conflict_type', SyncConflict::TYPE_DUPLICATE_FOLIO)
        ->firstOrFail();
    expect($conflict->branch_id)->toBe($this->branch->id)
        ->and($conflict->resolved_at)->toBeNull()
        ->and($conflict->server_data['number_value'])->toBe(7)
        ->and(Sale::query()->count())->toBe(1);  // cero ventas duplicadas
});
