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
use App\Domain\Sync\Models\SyncBatch;
use App\Domain\Sync\Models\SyncDevice;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Persistencia de sync_batches/sync_operations e idempotencia 38.3
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'mi-tenant', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(CatalogProvisioner::class)->provision($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->unit = Unit::query()->where('code', 'PZA')->firstOrFail();
    $this->branch = Branch::factory()->default()->create([
        'company_id' => $this->tenant->id,
        'code' => 'SBP',
    ]);
    $this->warehouse = Warehouse::factory()->default()->ofBranch($this->branch)->create();
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA01']);
    $this->cajero = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->session = app(CashService::class)->openSession($this->register, $this->cajero, 1000);
    $this->inventory = app(InventoryService::class);
});

const SBP_HEADERS = ['X-Tenant' => 'mi-tenant'];

function sbpSaleItem($test, Product $product, float $qty, float $total): array
{
    return [
        'client_uuid' => (string) Str::uuid(),
        'entity_type' => 'sale',
        'entity_uuid' => (string) Str::uuid(),
        'operation' => 'create',
        'client_timestamp' => now()->toIso8601String(),
        'payload' => [
            'cash_session_uuid' => $test->session->uuid,
            'warehouse_uuid' => $test->warehouse->uuid,
            'items' => [
                ['product_uuid' => $product->uuid, 'quantity' => $qty],
            ],
            'payments' => [
                ['method' => 'cash', 'amount' => $total, 'tendered_amount' => $total],
            ],
            'series' => 'A',
        ],
    ];
}

it('persiste el batch con operaciones y contadores', function (): void {
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'sku' => 'SKU-SBP-1',
        'price' => 100,
        'track_inventory' => true,
    ]);
    $this->inventory->recordEntry($product, $this->warehouse, 20, 40);
    TenantContext::set($this->tenant);

    $batchUuid = (string) Str::uuid();

    Sanctum::actingAs($this->cajero);
    $response = $this->postJson('/api/v1/sync/batch', [
        'batch_uuid' => $batchUuid,
        'items' => [
            sbpSaleItem($this, $product, 2, 200),
            [
                'client_uuid' => (string) Str::uuid(),
                'entity_type' => 'sale',
                'entity_uuid' => (string) Str::uuid(),
                'operation' => 'update',
                'client_timestamp' => now()->toIso8601String(),
                'payload' => ['x' => 1],
            ],
        ],
    ], SBP_HEADERS);

    $response->assertOk();

    TenantContext::set($this->tenant);
    $batch = SyncBatch::query()->where('uuid', $batchUuid)->firstOrFail();
    expect($batch->status)->toBe(SyncBatch::STATUS_COMPLETED);
    expect($batch->operations_count)->toBe(2);
    expect($batch->success_count)->toBe(1);
    expect($batch->error_count)->toBe(1);
    expect($batch->completed_at)->not->toBeNull();
    expect(SyncOperation::query()->where('batch_id', $batch->id)->count())->toBe(2);

    $op = SyncOperation::query()->where('batch_id', $batch->id)->where('status', 'success')->firstOrFail();
    expect($op->server_uuid)->not->toBeNull();
});

it('replay del mismo batch_uuid no reprocesa: la venta no se duplica', function (): void {
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'sku' => 'SKU-SBP-2',
        'price' => 100,
        'track_inventory' => true,
    ]);
    $this->inventory->recordEntry($product, $this->warehouse, 20, 40);
    TenantContext::set($this->tenant);

    $batchUuid = (string) Str::uuid();
    $payload = ['batch_uuid' => $batchUuid, 'items' => [sbpSaleItem($this, $product, 3, 300)]];

    Sanctum::actingAs($this->cajero);
    $first = $this->postJson('/api/v1/sync/batch', $payload, SBP_HEADERS);
    $first->assertOk();

    TenantContext::set($this->tenant);
    expect(Sale::query()->count())->toBe(1);

    $second = $this->postJson('/api/v1/sync/batch', $payload, SBP_HEADERS);
    $second->assertOk();

    // Equivalencia orden-insensible: jsonb no preserva el orden de
    // claves, asi que el replay devuelve las mismas claves/valores
    // en distinto orden. Para el cliente JSON son identicas.
    expect($second->json('results'))->toEqualCanonicalizing($first->json('results'));

    TenantContext::set($this->tenant);
    expect(Sale::query()->count())->toBe(1);
    expect(SyncBatch::query()->where('uuid', $batchUuid)->count())->toBe(1);
});

it('actualiza last_sync_at del device cuando el batch trae device_id', function (): void {
    $device = SyncDevice::factory()->ofBranch($this->branch)->create(['device_id' => 'dev-sbp-01']);
    $device->refresh();

    Sanctum::actingAs($this->cajero);
    $this->postJson('/api/v1/sync/batch', [
        'batch_uuid' => (string) Str::uuid(),
        'device_id' => 'dev-sbp-01',
        'items' => [[
            'client_uuid' => (string) Str::uuid(),
            'entity_type' => 'sale',
            'entity_uuid' => (string) Str::uuid(),
            'operation' => 'delete',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['x' => 1],
        ]],
    ], SBP_HEADERS)->assertOk();

    TenantContext::set($this->tenant);
    $fresh = SyncDevice::query()->find($device->id);
    expect($fresh->last_sync_at)->not->toBeNull();
});
