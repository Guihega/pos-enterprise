<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Models\CashSession;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'mi-tenant', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA-RNG']);
    $this->warehouse = Warehouse::factory()->create([
        'company_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'is_sellable' => true, 'is_active' => true,
    ]);

    $this->admin = User::factory()->create(['company_id' => $this->tenant->id, 'name' => 'Ana Admin']);
    $this->admin->assignRole(Roles::ADMIN);
    $this->cashier = User::factory()->create(['company_id' => $this->tenant->id, 'name' => 'Beto Cajero']);
    $this->cashier->assignRole(Roles::CAJERO);

    $this->session = CashSession::factory()->open()->create([
        'cash_register_id' => $this->register->id,
        'branch_id' => $this->branch->id,
        'opened_by' => $this->admin->id,
    ]);

    $unit = Unit::factory()->create(['company_id' => $this->tenant->id, 'code' => 'PZA-RNG']);
    $tax = Tax::factory()->create([
        'company_id' => $this->tenant->id,
        'code' => 'IVA-RNG', 'rate' => 0.16, 'is_inclusive' => false,
    ]);
    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $unit->id, 'tax_id' => $tax->id,
        'sku' => 'PROD-RNG-1', 'name' => 'Producto Uno',
        'price' => 100.00, 'status' => Product::STATUS_ACTIVE,
    ]);
    $this->product2 = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $unit->id, 'tax_id' => $tax->id,
        'sku' => 'PROD-RNG-2', 'name' => 'Producto Dos',
        'price' => 500.00, 'status' => Product::STATUS_ACTIVE,
    ]);
});

/**
 * Venta COMPLETED con un item. Nombre distinto de makeCompletedSale() de
 * SalesSummaryHttpTest: Pest carga todos los archivos en el mismo proceso y
 * dos funciones globales homonimas truenan con 'Cannot redeclare'.
 */
function makeRangeSale(string $completedAt, float $total, ?int $userId = null, ?int $branchId = null, ?Product $product = null, float $qty = 1.0): Sale
{
    $prod = $product ?? test()->product;

    $sale = Sale::factory()->create([
        'company_id' => test()->tenant->id,
        'branch_id' => $branchId ?? test()->branch->id,
        'cash_register_id' => test()->register->id,
        'cash_session_id' => test()->session->id,
        'warehouse_id' => test()->warehouse->id,
        'user_id' => $userId ?? test()->admin->id,
        'status' => Sale::STATUS_COMPLETED,
        'subtotal_amount' => $total,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => $total,
        'paid_amount' => $total,
        'completed_at' => $completedAt,
    ]);

    SaleItem::factory()->create([
        'company_id' => test()->tenant->id,
        'sale_id' => $sale->id,
        'product_id' => $prod->id,
        'product_sku' => $prod->sku,
        'product_name' => $prod->name,
        'quantity' => $qty,
        'line_subtotal' => $total,
        'tax_amount' => 0,
        'line_total' => $total,
    ]);

    return $sale;
}

// ====================================================================
//  GET /reports/sales-by-product
// ====================================================================

it('agrupa ventas por producto en el rango, ordenadas por monto', function () {
    TenantContext::set($this->tenant);
    makeRangeSale('2026-06-10 10:00:00', 300.00);
    makeRangeSale('2026-06-15 10:00:00', 100.00, null, null, null, 2.0);
    makeRangeSale('2026-06-20 10:00:00', 500.00, null, null, $this->product2);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/reports/sales-by-product?from=2026-06-01&to=2026-06-30', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonPath('data.from', '2026-06-01')
        ->assertJsonPath('data.to', '2026-06-30')
        ->assertJsonCount(2, 'data.rows')
        ->assertJsonPath('data.rows.0.sku', 'PROD-RNG-2')
        ->assertJsonPath('data.rows.0.amount', 500)
        ->assertJsonPath('data.rows.1.sku', 'PROD-RNG-1')
        ->assertJsonPath('data.rows.1.amount', 400)
        ->assertJsonPath('data.rows.1.sales_count', 2)
        ->assertJsonPath('data.rows.1.quantity', 3)
        ->assertJsonPath('data.totals.rows_count', 2)
        ->assertJsonPath('data.totals.amount', 900);
});

it('excluye ventas fuera del rango', function () {
    TenantContext::set($this->tenant);
    makeRangeSale('2026-06-10 10:00:00', 100.00);
    makeRangeSale('2026-07-05 10:00:00', 999.00);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/reports/sales-by-product?from=2026-06-01&to=2026-06-30', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonCount(1, 'data.rows')
        ->assertJsonPath('data.totals.amount', 100);
});

it('respeta limit', function () {
    TenantContext::set($this->tenant);
    makeRangeSale('2026-06-10 10:00:00', 300.00);
    makeRangeSale('2026-06-11 10:00:00', 500.00, null, null, $this->product2);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/reports/sales-by-product?from=2026-06-01&to=2026-06-30&limit=1', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonCount(1, 'data.rows')
        ->assertJsonPath('data.rows.0.sku', 'PROD-RNG-2');
});

it('sin ventas devuelve rows vacio y totales en cero', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/reports/sales-by-product?from=2026-06-01&to=2026-06-30', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonCount(0, 'data.rows')
        ->assertJsonPath('data.totals.rows_count', 0)
        ->assertJsonPath('data.totals.amount', 0);
});

// ====================================================================
//  GET /reports/sales-by-cashier
// ====================================================================

it('agrupa ventas por cajero con ticket promedio', function () {
    TenantContext::set($this->tenant);
    makeRangeSale('2026-06-10 10:00:00', 100.00, $this->admin->id);
    makeRangeSale('2026-06-11 10:00:00', 300.00, $this->admin->id);
    makeRangeSale('2026-06-12 10:00:00', 500.00, $this->cashier->id);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/reports/sales-by-cashier?from=2026-06-01&to=2026-06-30', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonCount(2, 'data.rows')
        ->assertJsonPath('data.rows.0.name', 'Beto Cajero')
        ->assertJsonPath('data.rows.0.amount', 500)
        ->assertJsonPath('data.rows.0.sales_count', 1)
        ->assertJsonPath('data.rows.0.average_ticket', 500)
        ->assertJsonPath('data.rows.1.name', 'Ana Admin')
        ->assertJsonPath('data.rows.1.amount', 400)
        ->assertJsonPath('data.rows.1.sales_count', 2)
        ->assertJsonPath('data.rows.1.average_ticket', 200)
        ->assertJsonPath('data.totals.amount', 900);
});

it('filtra por branch_uuid', function () {
    TenantContext::set($this->tenant);
    $otra = Branch::factory()->create(['company_id' => $this->tenant->id, 'code' => 'NRT-RNG']);
    makeRangeSale('2026-06-10 10:00:00', 100.00);
    makeRangeSale('2026-06-11 10:00:00', 999.00, null, $otra->id);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson("/api/v1/reports/sales-by-cashier?from=2026-06-01&to=2026-06-30&branch_uuid={$this->branch->uuid}", ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonPath('data.branch.uuid', $this->branch->uuid)
        ->assertJsonPath('data.totals.amount', 100);
});

// ====================================================================
//  Permisos y validacion
// ====================================================================

it('un cajero sin permiso de reportes recibe 403', function () {
    Sanctum::actingAs($this->cashier);
    $this->getJson('/api/v1/reports/sales-by-product?from=2026-06-01&to=2026-06-30', ['X-Tenant' => 'mi-tenant'])
        ->assertStatus(403);
});

it('from y to son obligatorios (422)', function () {
    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/reports/sales-by-product', ['X-Tenant' => 'mi-tenant'])
        ->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
});

it('to anterior a from devuelve 422', function () {
    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/reports/sales-by-cashier?from=2026-06-30&to=2026-06-01', ['X-Tenant' => 'mi-tenant'])
        ->assertStatus(422)->assertJsonValidationErrors(['to']);
});

// ====================================================================
//  GET /reports/products-without-sales
// ====================================================================

it('lista solo los productos sin ventas en el rango', function () {
    TenantContext::set($this->tenant);
    makeRangeSale('2026-06-10 10:00:00', 300.00);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/reports/products-without-sales?from=2026-06-01&to=2026-06-30', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonCount(1, 'data.rows')
        ->assertJsonPath('data.rows.0.sku', 'PROD-RNG-2')
        ->assertJsonPath('data.rows.0.name', 'Producto Dos')
        ->assertJsonPath('data.rows.0.price', 500)
        ->assertJsonPath('data.rows.0.amount', 0)
        ->assertJsonPath('data.rows.0.last_sold_at', null)
        ->assertJsonPath('data.totals.rows_count', 1)
        ->assertJsonPath('data.totals.amount', 0);
});

it('una venta fuera del rango deja al producto en la lista con last_sold_at', function () {
    TenantContext::set($this->tenant);
    makeRangeSale('2026-05-20 10:00:00', 100.00);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/reports/products-without-sales?from=2026-06-01&to=2026-06-30', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonCount(2, 'data.rows')
        ->assertJsonPath('data.rows.0.sku', 'PROD-RNG-1')
        ->assertJsonPath('data.rows.0.last_sold_at', '2026-05-20')
        ->assertJsonPath('data.rows.1.last_sold_at', null);
});

it('excluye productos no activos o no vendibles', function () {
    TenantContext::set($this->tenant);
    $this->product2->update(['status' => Product::STATUS_ARCHIVED]);

    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/reports/products-without-sales?from=2026-06-01&to=2026-06-30', ['X-Tenant' => 'mi-tenant'])
        ->assertOk()
        ->assertJsonCount(1, 'data.rows')
        ->assertJsonPath('data.rows.0.sku', 'PROD-RNG-1');
});

it('filtra por branch_uuid: vendido en otra sucursal sigue sin venta aqui', function () {
    TenantContext::set($this->tenant);
    $otra = Branch::factory()->create(['company_id' => $this->tenant->id, 'code' => 'NRT-PWS']);
    makeRangeSale('2026-06-10 10:00:00', 300.00, null, $otra->id);

    Sanctum::actingAs($this->admin);
    $this->getJson("/api/v1/reports/products-without-sales?from=2026-06-01&to=2026-06-30&branch_uuid={$this->branch->uuid}", ['X-Tenant' => 'mi-tenant'])
        ->assertOk()
        ->assertJsonCount(2, 'data.rows');
});

it('sin ventas devuelve todo el catalogo activo', function () {
    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/reports/products-without-sales?from=2026-06-01&to=2026-06-30', ['X-Tenant' => 'mi-tenant'])
        ->assertOk()
        ->assertJsonCount(2, 'data.rows')
        ->assertJsonPath('data.totals.amount', 0);
});

it('productos sin venta: un cajero sin permiso recibe 403', function () {
    Sanctum::actingAs($this->cashier);
    $this->getJson('/api/v1/reports/products-without-sales?from=2026-06-01&to=2026-06-30', ['X-Tenant' => 'mi-tenant'])
        ->assertStatus(403);
});
