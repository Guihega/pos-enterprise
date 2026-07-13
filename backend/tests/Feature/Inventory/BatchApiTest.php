<?php

declare(strict_types=1);

use App\Domain\Authorization\Permissions;
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
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Tests HTTP de API de lotes (doc maestro 29.6)
|--------------------------------------------------------------------------
|
| Estandares adoptados (documentados en BatchController):
| - Lecturas con inventory.view; quarantine/release con inventory.adjust.
| - RN-233: sin inventory.view.cross-branch solo lotes de sucursales
|   propias; show ajeno devuelve 404.
| - Matriz de permisos via givePermissionTo directo (determinista, sin
|   depender de defaultMatrix de roles).
|
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
        'code' => 'BAP',
    ]);
    $this->warehouse = Warehouse::factory()->default()->ofBranch($this->branch)->create();

    $this->inventory = app(InventoryService::class);

    // Matriz de usuarios por permiso
    $this->nobody = User::factory()->create(['company_id' => $this->tenant->id]);

    $this->viewer = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->viewer->givePermissionTo(Permissions::INVENTORY_VIEW);
    $this->viewer->syncBranches([$this->branch]);

    $this->viewerCross = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->viewerCross->givePermissionTo([
        Permissions::INVENTORY_VIEW,
        Permissions::INVENTORY_VIEW_CROSS_BRANCH,
    ]);

    $this->adjuster = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->adjuster->givePermissionTo(Permissions::INVENTORY_ADJUST);
});

function bapiProduct($test, string $sku): Product
{
    return Product::factory()->create([
        'company_id' => $test->tenant->id,
        'unit_id' => $test->unit->id,
        'sku' => $sku,
        'price' => 100,
        'track_inventory' => true,
        'tracks_lots' => true,
    ]);
}

function bapiEntry($test, Product $product, float $qty, string $lot, ?string $exp): Batch
{
    $test->inventory->recordEntry($product, $test->warehouse, $qty, 40,
        batch: ['lot_number' => $lot, 'expiration_date' => $exp]);

    return Batch::query()->where('lot_number', $lot)->firstOrFail();
}

const BAPI_HEADERS = ['X-Tenant' => 'mi-tenant'];

// ====================================================================
//  PERMISOS
// ====================================================================

it('GET /batches devuelve 403 sin inventory.view', function (): void {
    Sanctum::actingAs($this->nobody);
    $this->getJson('/api/v1/inventory/batches', BAPI_HEADERS)->assertForbidden();
});

it('POST /quarantine devuelve 403 sin inventory.adjust', function (): void {
    $product = bapiProduct($this, 'SKU-BAPI-P1');
    $batch = bapiEntry($this, $product, 5, 'L-BAPI-PERM', null);

    Sanctum::actingAs($this->viewerCross);
    $this->postJson("/api/v1/inventory/batches/{$batch->uuid}/quarantine", [], BAPI_HEADERS)
        ->assertForbidden();
});

// ====================================================================
//  INDEX / SHOW
// ====================================================================

it('GET /batches lista paginado con filtro de status', function (): void {
    $product = bapiProduct($this, 'SKU-BAPI-IDX');
    bapiEntry($this, $product, 5, 'L-BAPI-A', null);
    $b = bapiEntry($this, $product, 5, 'L-BAPI-B', null);
    $b->update(['status' => Batch::STATUS_QUARANTINED]);

    Sanctum::actingAs($this->viewerCross);

    $all = $this->getJson('/api/v1/inventory/batches', BAPI_HEADERS);
    $all->assertOk()->assertJsonStructure([
        'data' => [['uuid', 'lot_number', 'status', 'quantities', 'is_quarantined']],
        'meta',
    ]);
    expect($all->json('meta.total'))->toBe(2);

    $q = $this->getJson('/api/v1/inventory/batches?status=quarantined', BAPI_HEADERS);
    expect($q->json('meta.total'))->toBe(1);
    expect($q->json('data.0.lot_number'))->toBe('L-BAPI-B');
});

it('GET /batches/{uuid} muestra detalle', function (): void {
    $product = bapiProduct($this, 'SKU-BAPI-SHOW');
    $batch = bapiEntry($this, $product, 7, 'L-BAPI-SHOW', now()->addWeek()->toDateString());

    Sanctum::actingAs($this->viewer);
    $this->getJson("/api/v1/inventory/batches/{$batch->uuid}", BAPI_HEADERS)
        ->assertOk()
        ->assertJsonPath('data.lot_number', 'L-BAPI-SHOW')
        ->assertJsonPath('data.quantities.remaining', 7);
});

it('RN-233: sin cross-branch no ve lotes de otra sucursal y show ajeno da 404', function (): void {
    $product = bapiProduct($this, 'SKU-BAPI-233');
    bapiEntry($this, $product, 5, 'L-BAPI-PROPIO', null);

    // Sin ->default(): constraint branches_one_default_per_company
    // permite un solo branch default por tenant (el de beforeEach).
    $branch2 = Branch::factory()->create([
        'company_id' => $this->tenant->id,
        'code' => 'BA2',
    ]);
    $wh2 = Warehouse::factory()->default()->ofBranch($branch2)->create();
    $this->inventory->recordEntry($product, $wh2, 5, 40,
        batch: ['lot_number' => 'L-BAPI-AJENO', 'expiration_date' => null]);
    $ajeno = Batch::query()->where('lot_number', 'L-BAPI-AJENO')->firstOrFail();

    Sanctum::actingAs($this->viewer);

    $index = $this->getJson('/api/v1/inventory/batches', BAPI_HEADERS);
    expect($index->json('meta.total'))->toBe(1);
    expect($index->json('data.0.lot_number'))->toBe('L-BAPI-PROPIO');

    $this->getJson("/api/v1/inventory/batches/{$ajeno->uuid}", BAPI_HEADERS)->assertNotFound();
});

// ====================================================================
//  QUARANTINE / RELEASE
// ====================================================================

it('quarantine y release con transiciones invalidas en 409', function (): void {
    $product = bapiProduct($this, 'SKU-BAPI-QR');
    $batch = bapiEntry($this, $product, 5, 'L-BAPI-QR', null);

    Sanctum::actingAs($this->adjuster);

    // release sin estar en cuarentena -> 409
    $this->postJson("/api/v1/inventory/batches/{$batch->uuid}/release", [], BAPI_HEADERS)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BATCH_NOT_QUARANTINED');

    $this->postJson("/api/v1/inventory/batches/{$batch->uuid}/quarantine", [], BAPI_HEADERS)
        ->assertOk()
        ->assertJsonPath('data.status', Batch::STATUS_QUARANTINED);

    // doble quarantine -> 409
    $this->postJson("/api/v1/inventory/batches/{$batch->uuid}/quarantine", [], BAPI_HEADERS)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BATCH_ALREADY_QUARANTINED');

    $this->postJson("/api/v1/inventory/batches/{$batch->uuid}/release", [], BAPI_HEADERS)
        ->assertOk()
        ->assertJsonPath('data.status', Batch::STATUS_AVAILABLE);

    TenantContext::set($this->tenant);
    expect(Batch::query()->find($batch->id)->status)->toBe(Batch::STATUS_AVAILABLE);
});

// ====================================================================
//  EXPIRATIONS
// ====================================================================

it('GET /expirations filtra por dias y excluye quarantined y sin caducidad', function (): void {
    $product = bapiProduct($this, 'SKU-BAPI-EXP');
    bapiEntry($this, $product, 5, 'L-BAPI-CERCA', now()->addDays(7)->toDateString());
    bapiEntry($this, $product, 5, 'L-BAPI-LEJOS', now()->addYear()->toDateString());
    bapiEntry($this, $product, 5, 'L-BAPI-SINEXP', null);
    $qNear = bapiEntry($this, $product, 5, 'L-BAPI-CERCA-Q', now()->addDays(7)->toDateString());
    $qNear->update(['status' => Batch::STATUS_QUARANTINED]);

    Sanctum::actingAs($this->viewerCross);

    $r = $this->getJson('/api/v1/inventory/expirations?days=30', BAPI_HEADERS);
    $r->assertOk();
    expect($r->json('meta.total'))->toBe(1);
    expect($r->json('data.0.lot_number'))->toBe('L-BAPI-CERCA');

    $wide = $this->getJson('/api/v1/inventory/expirations?days=365', BAPI_HEADERS);
    expect($wide->json('meta.total'))->toBe(2);
});

// ====================================================================
//  INTEGRACION FEFO + CUARENTENA
// ====================================================================

it('el FEFO de venta no consume un lote en cuarentena', function (): void {
    $product = bapiProduct($this, 'SKU-BAPI-FEFO');
    $near = bapiEntry($this, $product, 10, 'L-BAPI-F-CERCANO', now()->addWeek()->toDateString());
    $far = bapiEntry($this, $product, 10, 'L-BAPI-F-LEJANO', now()->addYear()->toDateString());

    // Bloquear via API el lote que el FEFO tomaria primero
    Sanctum::actingAs($this->adjuster);
    $this->postJson("/api/v1/inventory/batches/{$near->uuid}/quarantine", [], BAPI_HEADERS)
        ->assertOk();

    // Setup de venta (patron SaleBatchIntegrationTest)
    TenantContext::set($this->tenant);
    $register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA01']);
    $cajero = User::factory()->create(['company_id' => $this->tenant->id]);
    $session = app(CashService::class)->openSession($register, $cajero, 1000);

    $req = CheckoutRequest::fromArray([
        'cash_session_uuid' => $session->uuid,
        'warehouse_uuid' => $this->warehouse->uuid,
        'items' => [
            ['product_uuid' => $product->uuid, 'quantity' => 5],
        ],
        'payments' => [
            ['method' => 'cash', 'amount' => 500, 'tendered_amount' => 500],
        ],
        'series' => 'A',
    ]);
    app(SalesService::class)->checkout($req, $cajero);
    TenantContext::set($this->tenant);

    $saleItem = SaleItem::query()->where('product_id', $product->id)->firstOrFail();
    $rows = SaleItemBatch::query()->where('sale_item_id', $saleItem->id)->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->batch_id)->toBe($far->id);
    expect((float) Batch::query()->find($near->id)->quantity)->toBe(10.0);
    expect((float) Batch::query()->find($far->id)->quantity)->toBe(5.0);
});
