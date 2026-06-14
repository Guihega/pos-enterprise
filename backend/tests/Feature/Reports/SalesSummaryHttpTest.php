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
use App\Domain\Sales\Models\SalePayment;
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
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA-RPT']);
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

    $unit = Unit::factory()->create(['company_id' => $this->tenant->id, 'code' => 'PZA-RPT']);
    $tax = Tax::factory()->create([
        'company_id' => $this->tenant->id,
        'code' => 'IVA-16', 'rate' => 0.16, 'is_inclusive' => false,
    ]);
    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $unit->id, 'tax_id' => $tax->id,
        'sku' => 'PROD-RPT-1', 'name' => 'Producto Reporte',
        'price' => 100.00, 'status' => Product::STATUS_ACTIVE,
    ]);
});

/**
 * Crea una venta COMPLETED del tenant/branch en una fecha dada, con un item
 * y un pago. Pasa TODOS los FK explicitos (la SaleFactory los tiene como
 * *::factory() por defecto y evaluarlos crearia entidades cross-tenant).
 */
function makeCompletedSale(string $completedAt, float $subtotal, float $tax, string $method, ?array $itemOverrides = null): Sale
{
    $total = round($subtotal + $tax, 2);

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
        'tax_amount' => $tax,
        'total_amount' => $total,
        'paid_amount' => $total,
        'completed_at' => $completedAt,
    ]);

    SaleItem::factory()->create(array_merge([
        'company_id' => test()->tenant->id,
        'sale_id' => $sale->id,
        'product_id' => test()->product->id,
        'product_sku' => test()->product->sku,
        'product_name' => test()->product->name,
        'quantity' => 1,
        'line_subtotal' => $subtotal,
        'tax_amount' => $tax,
        'line_total' => $total,
    ], $itemOverrides ?? []));

    SalePayment::factory()->create([
        'company_id' => test()->tenant->id,
        'sale_id' => $sale->id,
        'method' => $method,
        'amount' => $total,
        'tendered_amount' => $total,
    ]);

    return $sale;
}

it('GET /reports/sales-summary devuelve totales del dia (status completed)', function () {
    TenantContext::set($this->tenant);
    makeCompletedSale('2026-06-10 10:00:00', 100.00, 16.00, SalePayment::METHOD_CASH);
    makeCompletedSale('2026-06-10 12:00:00', 200.00, 32.00, SalePayment::METHOD_CARD_DEBIT);
    makeCompletedSale('2026-06-09 10:00:00', 999.00, 0.00, SalePayment::METHOD_CASH);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/reports/sales-summary?date=2026-06-10', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonPath('data.date', '2026-06-10')
        ->assertJsonPath('data.totals.sales_count', 2)
        ->assertJsonPath('data.totals.gross_amount', 348)
        ->assertJsonPath('data.totals.tax_amount', 48)
        ->assertJsonPath('data.totals.average_ticket', 174);
});

it('desglosa pagos por metodo', function () {
    TenantContext::set($this->tenant);
    makeCompletedSale('2026-06-10 10:00:00', 100.00, 0.00, SalePayment::METHOD_CASH);
    makeCompletedSale('2026-06-10 11:00:00', 50.00, 0.00, SalePayment::METHOD_CASH);
    makeCompletedSale('2026-06-10 12:00:00', 200.00, 0.00, SalePayment::METHOD_CARD_DEBIT);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/reports/sales-summary?date=2026-06-10', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()->assertJsonCount(2, 'data.payments');
    $response->assertJsonPath('data.payments.0.method', 'card_debit')
        ->assertJsonPath('data.payments.0.amount', 200)
        ->assertJsonPath('data.payments.1.method', 'cash')
        ->assertJsonPath('data.payments.1.count', 2)
        ->assertJsonPath('data.payments.1.amount', 150);
});

it('lista top productos por monto vendido', function () {
    TenantContext::set($this->tenant);
    $otherProduct = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->product->unit_id, 'tax_id' => $this->product->tax_id,
        'sku' => 'PROD-RPT-2', 'name' => 'Segundo Producto', 'status' => Product::STATUS_ACTIVE,
    ]);
    makeCompletedSale('2026-06-10 10:00:00', 300.00, 0.00, SalePayment::METHOD_CASH);
    makeCompletedSale('2026-06-10 11:00:00', 100.00, 0.00, SalePayment::METHOD_CASH, [
        'product_id' => $otherProduct->id,
        'product_sku' => $otherProduct->sku,
        'product_name' => $otherProduct->name,
        'line_subtotal' => 100.00, 'line_total' => 100.00, 'tax_amount' => 0.00,
    ]);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/reports/sales-summary?date=2026-06-10', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()->assertJsonCount(2, 'data.top_products');
    $response->assertJsonPath('data.top_products.0.sku', 'PROD-RPT-1')
        ->assertJsonPath('data.top_products.0.amount', 300)
        ->assertJsonPath('data.top_products.1.sku', 'PROD-RPT-2');
});

it('sin ventas el dia devuelve totales en cero y listas vacias', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/reports/sales-summary?date=2026-06-10', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonPath('data.totals.sales_count', 0)
        ->assertJsonPath('data.totals.gross_amount', 0)
        ->assertJsonPath('data.totals.average_ticket', 0)
        ->assertJsonCount(0, 'data.payments')
        ->assertJsonCount(0, 'data.top_products');
});

it('filtra por branch_uuid', function () {
    TenantContext::set($this->tenant);
    $otherBranch = Branch::factory()->create(['company_id' => $this->tenant->id, 'code' => 'NRT-RPT']);
    makeCompletedSale('2026-06-10 10:00:00', 100.00, 0.00, SalePayment::METHOD_CASH);
    Sale::factory()->create([
        'company_id' => $this->tenant->id, 'branch_id' => $otherBranch->id,
        'cash_register_id' => $this->register->id, 'cash_session_id' => $this->session->id,
        'warehouse_id' => $this->warehouse->id, 'user_id' => $this->admin->id,
        'status' => Sale::STATUS_COMPLETED, 'total_amount' => 999.00,
        'subtotal_amount' => 999.00, 'paid_amount' => 999.00,
        'completed_at' => '2026-06-10 10:00:00',
    ]);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson("/api/v1/reports/sales-summary?date=2026-06-10&branch_uuid={$this->branch->uuid}", ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonPath('data.branch.uuid', $this->branch->uuid)
        ->assertJsonPath('data.totals.sales_count', 1)
        ->assertJsonPath('data.totals.gross_amount', 100);
});

it('un cajero sin permiso de reportes recibe 403', function () {
    Sanctum::actingAs($this->cashier);
    $response = $this->getJson('/api/v1/reports/sales-summary?date=2026-06-10', ['X-Tenant' => 'mi-tenant']);

    $response->assertStatus(403);
});

it('valida date con formato invalido (422)', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/reports/sales-summary?date=10-06-2026', ['X-Tenant' => 'mi-tenant']);

    $response->assertStatus(422)->assertJsonValidationErrors(['date']);
});
