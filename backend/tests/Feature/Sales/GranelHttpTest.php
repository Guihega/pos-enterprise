<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Services\CashService;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
 * Venta a granel: gate de fraccion/allow_decimals, quantity_source y captura
 * manual con permiso (docs/DISENO_GRANEL.md, sec. 4). Clon del beforeEach
 * de SaleReturnHttpTest con codigos -GR. Precio 100 con IVA incluido para
 * que total = qty x precio exacto: 1.25 kg = 125.00; 2 piezas = 200.00.
 */

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'gr-test', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA-GR']);
    $this->warehouse = Warehouse::factory()->create([
        'company_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'is_sellable' => true,
        'is_active' => true,
    ]);
    $this->supervisor = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->supervisor->assignRole(Roles::SUPERVISOR);
    $this->session = app(CashService::class)->openSession($this->register, $this->supervisor, 1000);
    $unit = Unit::factory()->create(['company_id' => $this->tenant->id, 'code' => 'PZA-GR']);
    $kg = Unit::factory()->create(['company_id' => $this->tenant->id, 'code' => 'KG-GR']);
    $tax = Tax::factory()->create(['company_id' => $this->tenant->id, 'code' => 'IVA-GR', 'rate' => 0.16, 'is_inclusive' => true]);
    $this->pieza = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $unit->id, 'tax_id' => $tax->id,
        'sku' => 'GR-PIEZA', 'price' => 100.00, 'allow_decimals' => false,
        'track_inventory' => true, 'is_sellable' => true, 'status' => Product::STATUS_ACTIVE,
    ]);
    $this->kilo = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $kg->id, 'tax_id' => $tax->id,
        'sku' => 'GR-KILO', 'price' => 100.00, 'allow_decimals' => true,
        'track_inventory' => true, 'is_sellable' => true, 'status' => Product::STATUS_ACTIVE,
    ]);
    app(InventoryService::class)->recordEntry($this->pieza, $this->warehouse, 100, 50);
    app(InventoryService::class)->recordEntry($this->kilo, $this->warehouse, 100, 40);
    TenantContext::set($this->tenant);
});

function grH(): array
{
    return ['X-Tenant' => 'gr-test'];
}

/** Checkout de UNA linea pagada exacta en efectivo (total = qty x 100). */
function grCheckout(User $user, Product $product, float $qty, ?string $source = null)
{
    $item = ['product_uuid' => $product->uuid, 'quantity' => $qty];
    if ($source !== null) {
        $item['quantity_source'] = $source;
    }
    $total = round($qty * 100, 2);

    Sanctum::actingAs($user);
    $resp = test()->postJson('/api/v1/sales', [
        'cash_session_uuid' => test()->session->uuid,
        'warehouse_uuid' => test()->warehouse->uuid,
        'items' => [$item],
        'payments' => [['method' => 'cash', 'amount' => $total, 'tendered_amount' => $total]],
    ], grH());
    TenantContext::set(test()->tenant);

    return $resp;
}

it('producto por peso con quantity_source scale vende 1.25 kg', function () {
    $resp = grCheckout($this->supervisor, $this->kilo, 1.25, 'scale');
    $resp->assertCreated();

    $item = SaleItem::query()->where('product_id', $this->kilo->id)->firstOrFail();
    expect((float) $item->quantity)->toEqualWithDelta(1.25, 0.0001)
        ->and($item->quantity_source)->toBe(SaleItem::QUANTITY_SOURCE_SCALE)
        ->and((float) $item->line_total)->toEqualWithDelta(125.0, 0.001);
});

it('producto por pieza rechaza cantidad fraccionaria (422)', function () {
    grCheckout($this->supervisor, $this->pieza, 0.5)->assertUnprocessable();
    expect(SaleItem::query()->count())->toBe(0);
});

it('producto por pieza con cantidad entera vende normal y sin quantity_source', function () {
    grCheckout($this->supervisor, $this->pieza, 2)->assertCreated();

    $item = SaleItem::query()->where('product_id', $this->pieza->id)->firstOrFail();
    expect($item->quantity_source)->toBeNull()
        ->and((float) $item->line_total)->toEqualWithDelta(200.0, 0.001);
});

it('producto por peso sin quantity_source devuelve 422', function () {
    grCheckout($this->supervisor, $this->kilo, 1.25)->assertUnprocessable();
    expect(SaleItem::query()->count())->toBe(0);
});

it('quantity_source fuera de scale|manual devuelve 422 de forma', function () {
    grCheckout($this->supervisor, $this->kilo, 1.25, 'guess')
        ->assertUnprocessable()->assertJsonValidationErrors(['items.0.quantity_source']);
});

it('captura manual de peso con supervisor (D1 = a) persiste manual', function () {
    grCheckout($this->supervisor, $this->kilo, 0.5, 'manual')->assertCreated();

    $item = SaleItem::query()->where('product_id', $this->kilo->id)->firstOrFail();
    expect($item->quantity_source)->toBe(SaleItem::QUANTITY_SOURCE_MANUAL)
        ->and((float) $item->line_total)->toEqualWithDelta(50.0, 0.001);
});

it('captura manual de peso con cajero sin sale.weight.manual devuelve 403', function () {
    $cajero = User::factory()->create(['company_id' => $this->tenant->id]);
    $cajero->assignRole(Roles::CAJERO);

    grCheckout($cajero, $this->kilo, 0.5, 'manual')->assertForbidden();
    expect(SaleItem::query()->count())->toBe(0);

    // El mismo cajero SI vende ese peso cuando viene de la bascula.
    grCheckout($cajero, $this->kilo, 0.5, 'scale')->assertCreated();
});

it('devolucion fraccionaria de un producto por pieza devuelve 422', function () {
    $saleUuid = grCheckout($this->supervisor, $this->pieza, 3)->assertCreated()->json('data.uuid');
    $itemUuid = SaleItem::query()->where('product_id', $this->pieza->id)->firstOrFail()->uuid;

    Sanctum::actingAs($this->supervisor);
    $this->postJson("/api/v1/sales/{$saleUuid}/returns", [
        'reason' => 'Pieza rota',
        'items' => [['sale_item_uuid' => $itemUuid, 'quantity' => 1.5]],
    ], grH())->assertUnprocessable();
    TenantContext::set($this->tenant);

    $this->postJson("/api/v1/sales/{$saleUuid}/returns", [
        'reason' => 'Pieza rota',
        'items' => [['sale_item_uuid' => $itemUuid, 'quantity' => 1]],
    ], grH())->assertCreated();
});
