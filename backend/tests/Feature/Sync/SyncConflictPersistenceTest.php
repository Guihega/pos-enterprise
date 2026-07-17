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
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\Services\SyncBatchService;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| RN-156 / sec. 39.3: conflicto duro persiste en sync_conflicts
|--------------------------------------------------------------------------
|
| Venta offline contra cash_session cerrada: antes caia en Throwable
| (error + retry infinito del cliente); ahora es conflict persistido
| en la cola humana, colgado del sync_operation.
|
*/

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'conflict-test', 'country_code' => 'MX']);
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

function conflictSaleItem(): SyncBatchItem
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

it('venta contra sesion cerrada persiste conflicto RN-156', function (): void {
    // Cerrar la sesion ANTES de sincronizar (otro dispositivo la cerro)
    app(CashService::class)->closeSession($this->session, $this->cajero, 1000);
    TenantContext::set($this->tenant);

    $results = $this->service->process(
        [conflictSaleItem()],
        $this->cajero,
        (string) Str::uuid(),
        'device-001',
    );
    TenantContext::set($this->tenant);

    expect($results[0]['status'])->toBe('conflict');

    $conflict = SyncConflict::query()->firstOrFail();
    expect($conflict->conflict_type)->toBe(SyncConflict::TYPE_CASH_SESSION_CLOSED)
        ->and($conflict->branch_id)->toBe($this->branch->id)
        ->and($conflict->device_id)->toBe('device-001')
        ->and($conflict->resolved_at)->toBeNull()
        ->and($conflict->server_data['session_status'])->toBe('closed')
        ->and($conflict->client_data['cash_session_uuid'])->toBe($this->session->uuid);

    // Colgado del operation correcto
    $operation = SyncOperation::query()->firstOrFail();
    expect($conflict->sync_operation_id)->toBe($operation->id)
        ->and($operation->status)->toBe('conflict');
});

it('venta exitosa no crea filas en sync_conflicts', function (): void {
    $results = $this->service->process(
        [conflictSaleItem()],
        $this->cajero,
        (string) Str::uuid(),
        'device-001',
    );
    TenantContext::set($this->tenant);

    expect($results[0]['status'])->toBe('success');
    expect(SyncConflict::query()->count())->toBe(0);
});

it('payment mismatch es conflict en response pero NO persiste (39.1)', function (): void {
    $item = SyncBatchItem::fromArray([
        'client_uuid' => (string) Str::uuid(),
        'entity_type' => 'sale',
        'entity_uuid' => (string) Str::uuid(),
        'operation' => 'create',
        'client_timestamp' => '2026-01-01T10:00:00Z',
        'payload' => [
            'cash_session_uuid' => $this->session->uuid,
            'warehouse_uuid' => $this->warehouse->uuid,
            'items' => [['product_uuid' => $this->product->uuid, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 50.00, 'tendered_amount' => 50.00]],
        ],
    ]);

    $results = $this->service->process([$item], $this->cajero, (string) Str::uuid(), null);
    TenantContext::set($this->tenant);

    expect($results[0]['status'])->toBe('conflict');
    expect(SyncConflict::query()->count())->toBe(0);
});
