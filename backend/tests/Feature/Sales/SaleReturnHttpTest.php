<?php

declare(strict_types=1);

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Cash\Models\CashMovement;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Services\CashService;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Stock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Devoluciones basicas (CU-CAJ-010, RN-085/086) via HTTP
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'ret-test', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA-R1']);
    $this->warehouse = Warehouse::factory()->create([
        'company_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'is_sellable' => true,
        'is_active' => true,
    ]);
    $this->supervisor = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->supervisor->assignRole(Roles::SUPERVISOR);
    $this->session = app(CashService::class)->openSession($this->register, $this->supervisor, 1000);
    $unit = Unit::factory()->create(['company_id' => $this->tenant->id, 'code' => 'PZA-RET']);
    $tax = Tax::factory()->create(['company_id' => $this->tenant->id, 'code' => 'IVA-R', 'rate' => 0.16, 'is_inclusive' => true]);
    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $unit->id,
        'tax_id' => $tax->id,
        'price' => 116.00, 'track_inventory' => true, 'is_sellable' => true,
        'status' => Product::STATUS_ACTIVE,
    ]);
    app(InventoryService::class)->recordEntry($this->product, $this->warehouse, 100, 50);
    TenantContext::set($this->tenant);
});

/** Venta completada de 3 piezas pagada en efectivo, via HTTP. */
function retCreateSale(): Sale
{
    $t = test();
    Sanctum::actingAs($t->supervisor);
    $resp = $t->postJson('/api/v1/sales', [
        'cash_session_uuid' => $t->session->uuid,
        'warehouse_uuid' => $t->warehouse->uuid,
        'items' => [['product_uuid' => $t->product->uuid, 'quantity' => 3]],
        'payments' => [['method' => 'cash', 'amount' => 348.00, 'tendered_amount' => 348.00]],
    ], ['X-Tenant' => 'ret-test']);
    $resp->assertStatus(201);
    TenantContext::set($t->tenant);

    return Sale::query()->where('uuid', $resp->json('data.uuid'))->firstOrFail();
}

function retItemUuid(Sale $sale): string
{
    return $sale->items()->firstOrFail()->uuid;
}

it('devolucion parcial reingresa stock, saca efectivo y conserva completed', function () {
    $sale = retCreateSale();

    $resp = $this->postJson("/api/v1/sales/{$sale->uuid}/returns", [
        'reason' => 'Producto defectuoso',
        'items' => [['sale_item_uuid' => retItemUuid($sale), 'quantity' => 1]],
    ], ['X-Tenant' => 'ret-test']);
    $resp->assertStatus(201);
    TenantContext::set($this->tenant);

    expect((float) $resp->json('data.total_amount'))->toBe(116.0)
        ->and((float) $resp->json('data.cash_refunded'))->toBe(116.0)
        ->and($resp->json('data.sale_status'))->toBe(Sale::STATUS_COMPLETED);

    $stock = Stock::query()->where('product_id', $this->product->id)->firstOrFail();
    expect((float) $stock->quantity_on_hand)->toBe(98.0);  // 100 - 3 + 1

    $refund = CashMovement::query()->where('type', CashMovement::TYPE_REFUND_CASH)->first();
    expect($refund)->not->toBeNull()->and((float) $refund->amount)->toBe(116.0);
});

it('devolucion total transiciona la venta a refunded', function () {
    $sale = retCreateSale();

    $this->postJson("/api/v1/sales/{$sale->uuid}/returns", [
        'reason' => 'Cliente arrepentido',
        'items' => [['sale_item_uuid' => retItemUuid($sale), 'quantity' => 3]],
    ], ['X-Tenant' => 'ret-test'])->assertStatus(201);
    TenantContext::set($this->tenant);

    expect($sale->fresh()->status)->toBe(Sale::STATUS_REFUNDED);
});

it('sobre-devolucion en dos pasos se rechaza', function () {
    $sale = retCreateSale();

    $this->postJson("/api/v1/sales/{$sale->uuid}/returns", [
        'reason' => 'Primera parcial',
        'items' => [['sale_item_uuid' => retItemUuid($sale), 'quantity' => 2]],
    ], ['X-Tenant' => 'ret-test'])->assertStatus(201);

    TenantContext::set($this->tenant);

    $resp = $this->postJson("/api/v1/sales/{$sale->uuid}/returns", [
        'reason' => 'Excede lo restante',
        'items' => [['sale_item_uuid' => retItemUuid($sale), 'quantity' => 2]],
    ], ['X-Tenant' => 'ret-test']);

    expect($resp->status())->toBeGreaterThanOrEqual(400);
    TenantContext::set($this->tenant);
    expect(SaleReturn::query()->count())->toBe(1);
});

it('devolucion fuera de ventana RN-085 se rechaza', function () {
    $sale = retCreateSale();
    $sale->timestamps = false;
    $sale->forceFill(['completed_at' => now()->subDays(45)])->save();

    $resp = $this->postJson("/api/v1/sales/{$sale->uuid}/returns", [
        'reason' => 'Muy tarde',
        'items' => [['sale_item_uuid' => retItemUuid($sale), 'quantity' => 1]],
    ], ['X-Tenant' => 'ret-test']);

    expect($resp->status())->toBeGreaterThanOrEqual(400);
    TenantContext::set($this->tenant);
    expect(SaleReturn::query()->count())->toBe(0);
});

it('cajero sin SALE_REFUND recibe 403', function () {
    $sale = retCreateSale();
    $cajero = User::factory()->create(['company_id' => $this->tenant->id]);
    $cajero->assignRole(Roles::CAJERO);
    Sanctum::actingAs($cajero);

    $this->postJson("/api/v1/sales/{$sale->uuid}/returns", [
        'reason' => 'Sin permiso',
        'items' => [['sale_item_uuid' => retItemUuid($sale), 'quantity' => 1]],
    ], ['X-Tenant' => 'ret-test'])->assertStatus(403);
});

it('payload sin items devuelve 422', function () {
    $sale = retCreateSale();

    $this->postJson("/api/v1/sales/{$sale->uuid}/returns", [
        'reason' => 'Sin renglones',
    ], ['X-Tenant' => 'ret-test'])->assertStatus(422);
});

it('la devolucion deja rastro sale.returned en activity_log', function () {
    $sale = retCreateSale();

    $this->postJson("/api/v1/sales/{$sale->uuid}/returns", [
        'reason' => 'Auditable',
        'items' => [['sale_item_uuid' => retItemUuid($sale), 'quantity' => 1]],
    ], ['X-Tenant' => 'ret-test'])->assertStatus(201);
    TenantContext::set($this->tenant);

    $log = ActivityLog::query()->where('event', 'sale.returned')->first();
    expect($log)->not->toBeNull()
        ->and($log->causer_id)->toBe($this->supervisor->id)
        ->and($log->properties['sale_number'])->toBe($sale->number);
});
