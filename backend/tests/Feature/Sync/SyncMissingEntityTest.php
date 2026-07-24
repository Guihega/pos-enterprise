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
| 39.1 detecciones: producto eliminado y cliente eliminado
|--------------------------------------------------------------------------
| La venta offline referencia entidades que ya no existen en el server
| (borradas mientras el dispositivo estaba offline). Antes: catch
| Throwable => status error con retry infinito del cliente. Ahora:
| conflicto persistido en la cola humana 39.3.
*/

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'me-test', 'country_code' => 'MX']);
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
        'sku' => 'MEPROD-'.uniqid(),
        'price' => 100,
        'track_inventory' => true,
    ]);
    app(InventoryService::class)->recordEntry($this->product, $this->warehouse, 50, 60);
    TenantContext::set($this->tenant);
    $this->service = app(SyncBatchService::class);
});

function meTestSaleItem(?string $customerUuid = null): SyncBatchItem
{
    $test = test();

    $payload = [
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
    ];
    if ($customerUuid !== null) {
        $payload['customer_uuid'] = $customerUuid;
    }

    return SyncBatchItem::fromArray([
        'client_uuid' => (string) Str::uuid(),
        'entity_type' => 'sale',
        'entity_uuid' => (string) Str::uuid(),
        'operation' => 'create',
        'client_timestamp' => '2026-01-01T10:00:00Z',
        'payload' => $payload,
    ]);
}

it('venta con producto soft-borrado persiste conflicto PRODUCT_NOT_FOUND', function (): void {
    $productUuid = $this->product->uuid;
    $this->product->delete();  // soft delete: el scope lo excluye del checkout

    $results = $this->service->process(
        [meTestSaleItem()],
        $this->cajero,
        (string) Str::uuid(),
        'device-001',
    );
    TenantContext::set($this->tenant);

    expect($results[0]['status'])->toBe('conflict');

    $conflict = SyncConflict::query()->firstOrFail();
    expect($conflict->conflict_type)->toBe(SyncConflict::TYPE_PRODUCT_NOT_FOUND)
        ->and($conflict->branch_id)->toBe($this->branch->id)
        ->and($conflict->resolved_at)->toBeNull()
        ->and($conflict->server_data['product_uuid'])->toBe($productUuid);
});

it('venta con cliente inexistente persiste conflicto CUSTOMER_NOT_FOUND', function (): void {
    $customerUuid = (string) Str::uuid();  // nunca existio / borrado

    $results = $this->service->process(
        [meTestSaleItem($customerUuid)],
        $this->cajero,
        (string) Str::uuid(),
        'device-001',
    );
    TenantContext::set($this->tenant);

    expect($results[0]['status'])->toBe('conflict');

    $conflict = SyncConflict::query()->firstOrFail();
    expect($conflict->conflict_type)->toBe(SyncConflict::TYPE_CUSTOMER_NOT_FOUND)
        ->and($conflict->branch_id)->toBe($this->branch->id)
        ->and($conflict->resolved_at)->toBeNull()
        ->and($conflict->server_data['customer_uuid'])->toBe($customerUuid);
});
