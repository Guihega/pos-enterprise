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

/*
 * GET /reports/margin-by-product (docs/DISENO_MARGEN.md).
 * Clon del beforeEach de SalesRangeReportsHttpTest con codigos propios
 * (-MG) y ventas con unit_cost explicito.
 */

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'mg-tenant', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA-MG']);
    $this->warehouse = Warehouse::factory()->create([
        'company_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'is_sellable' => true, 'is_active' => true,
    ]);

    $this->admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->admin->assignRole(Roles::ADMIN);
    $this->cashier = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cashier->assignRole(Roles::CAJERO);

    $this->session = CashSession::factory()->open()->create([
        'cash_register_id' => $this->register->id,
        'branch_id' => $this->branch->id,
        'opened_by' => $this->admin->id,
    ]);

    $unit = Unit::factory()->create(['company_id' => $this->tenant->id, 'code' => 'PZA-MG']);
    $tax = Tax::factory()->create([
        'company_id' => $this->tenant->id,
        'code' => 'IVA-MG', 'rate' => 0.16, 'is_inclusive' => false,
    ]);
    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $unit->id, 'tax_id' => $tax->id,
        'sku' => 'PROD-MG-1', 'name' => 'Margen Uno',
        'price' => 100.00, 'status' => Product::STATUS_ACTIVE,
    ]);
    $this->product2 = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $unit->id, 'tax_id' => $tax->id,
        'sku' => 'PROD-MG-2', 'name' => 'Margen Dos',
        'price' => 500.00, 'status' => Product::STATUS_ACTIVE,
    ]);
});

function mgH(): array
{
    return ['X-Tenant' => 'mg-tenant'];
}

/** Venta COMPLETED con un item; ingreso neto = qty * unitPrice, sin impuesto. */
function makeMarginSale(string $completedAt, float $qty, float $unitPrice, float $unitCost, ?Product $product = null): Sale
{
    $prod = $product ?? test()->product;
    $subtotal = round($qty * $unitPrice, 2);

    $sale = Sale::factory()->create([
        'company_id' => test()->tenant->id,
        'branch_id' => test()->branch->id,
        'cash_register_id' => test()->register->id,
        'cash_session_id' => test()->session->id,
        'warehouse_id' => test()->warehouse->id,
        'user_id' => test()->admin->id,
        'status' => Sale::STATUS_COMPLETED,
        'subtotal_amount' => $subtotal,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => $subtotal,
        'paid_amount' => $subtotal,
        'completed_at' => $completedAt,
    ]);

    SaleItem::factory()->create([
        'company_id' => test()->tenant->id,
        'sale_id' => $sale->id,
        'product_id' => $prod->id,
        'product_sku' => $prod->sku,
        'product_name' => $prod->name,
        'quantity' => $qty,
        'unit_price' => $unitPrice,
        'unit_cost' => $unitCost,
        'line_subtotal' => $subtotal,
        'tax_amount' => 0,
        'line_total' => $subtotal,
    ]);

    return $sale;
}

/**
 * Escenario base, derivado a mano:
 *   Uno: 2 x 100 (costo 60) -> revenue 200, cost 120
 *        1 x 100 (costo 70) -> revenue 100, cost  70
 *        total Uno: revenue 300, cost 190, margin 110, pct 110/300 = 0.3667
 *   Dos: 1 x 500 (costo 200) -> revenue 500, cost 200, margin 300, pct 0.6
 *   Totales: revenue 800, cost 390, margin 410, pct 410/800 = 0.5125
 *   Orden por margen desc: Dos (300), Uno (110).
 */
function seedMarginScenario(): void
{
    makeMarginSale('2026-06-05 10:00:00', 2, 100, 60);
    makeMarginSale('2026-06-10 10:00:00', 1, 100, 70);
    makeMarginSale('2026-06-15 10:00:00', 1, 500, 200, test()->product2);
}

it('calcula ingreso neto, costo real y margen por producto ordenado por margen', function () {
    seedMarginScenario();
    Sanctum::actingAs($this->admin);

    $data = $this->getJson('/api/v1/reports/margin-by-product?from=2026-06-01&to=2026-06-30', mgH())
        ->assertOk()->json('data');

    expect($data['rows'])->toHaveCount(2);

    $dos = $data['rows'][0];
    expect($dos['sku'])->toBe('PROD-MG-2')
        ->and($dos['quantity'])->toEqualWithDelta(1.0, 0.0001)
        ->and($dos['revenue'])->toEqualWithDelta(500.0, 0.001)
        ->and($dos['cost'])->toEqualWithDelta(200.0, 0.001)
        ->and($dos['margin'])->toEqualWithDelta(300.0, 0.001)
        ->and($dos['margin_pct'])->toEqualWithDelta(0.6, 0.0001);

    $uno = $data['rows'][1];
    expect($uno['sku'])->toBe('PROD-MG-1')
        ->and($uno['quantity'])->toEqualWithDelta(3.0, 0.0001)
        ->and($uno['revenue'])->toEqualWithDelta(300.0, 0.001)
        ->and($uno['cost'])->toEqualWithDelta(190.0, 0.001)
        ->and($uno['margin'])->toEqualWithDelta(110.0, 0.001)
        ->and($uno['margin_pct'])->toEqualWithDelta(0.3667, 0.0001);

    expect($data['totals']['rows_count'])->toBe(2)
        ->and($data['totals']['revenue'])->toEqualWithDelta(800.0, 0.001)
        ->and($data['totals']['cost'])->toEqualWithDelta(390.0, 0.001)
        ->and($data['totals']['margin'])->toEqualWithDelta(410.0, 0.001)
        ->and($data['totals']['margin_pct'])->toEqualWithDelta(0.5125, 0.0001);
});

it('excluye del margen las ventas fuera del rango', function () {
    seedMarginScenario();
    makeMarginSale('2026-05-20 10:00:00', 10, 100, 10); // mayo: margen 900, fuera
    Sanctum::actingAs($this->admin);

    $data = $this->getJson('/api/v1/reports/margin-by-product?from=2026-06-01&to=2026-06-30', mgH())
        ->assertOk()->json('data');

    expect($data['totals']['margin'])->toEqualWithDelta(410.0, 0.001);
});

it('margen: respeta limit y los totales son de las filas devueltas', function () {
    seedMarginScenario();
    Sanctum::actingAs($this->admin);

    $data = $this->getJson('/api/v1/reports/margin-by-product?from=2026-06-01&to=2026-06-30&limit=1', mgH())
        ->assertOk()->json('data');

    expect($data['rows'])->toHaveCount(1)
        ->and($data['rows'][0]['sku'])->toBe('PROD-MG-2')
        ->and($data['totals']['margin'])->toEqualWithDelta(300.0, 0.001);
});

it('margen: sin ventas devuelve rows vacio y totales en cero', function () {
    Sanctum::actingAs($this->admin);

    $data = $this->getJson('/api/v1/reports/margin-by-product?from=2026-06-01&to=2026-06-30', mgH())
        ->assertOk()->json('data');

    expect($data['rows'])->toBe([])
        ->and($data['totals']['rows_count'])->toBe(0)
        ->and($data['totals']['revenue'])->toEqualWithDelta(0.0, 0.001)
        ->and($data['totals']['margin_pct'])->toEqualWithDelta(0.0, 0.0001);
});

it('margen: un cajero sin permiso de finanzas recibe 403', function () {
    Sanctum::actingAs($this->cashier);
    $this->getJson('/api/v1/reports/margin-by-product?from=2026-06-01&to=2026-06-30', mgH())
        ->assertForbidden();
});

it('margen: from y to son obligatorios (422)', function () {
    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/reports/margin-by-product', mgH())
        ->assertUnprocessable();
});
