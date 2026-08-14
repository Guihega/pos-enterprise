<?php

declare(strict_types=1);

use App\Domain\Authorization\Permissions;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierInvoice;
use App\Domain\Purchasing\Models\SupplierPayment;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'si-tenant']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->supplier = Supplier::factory()->create(['code' => 'PROV-SI']);
});

function siActor(array $permisos): User
{
    $u = User::factory()->create(['company_id' => test()->tenant->id]);
    $u->givePermissionTo($permisos);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Sanctum::actingAs($u);

    return $u;
}

function siHeaders(): array
{
    return ['X-Tenant' => 'si-tenant'];
}

function siProduct(float $rate = 0.16): Product
{
    $tax = Tax::factory()->rate($rate)->create(['company_id' => test()->tenant->id]);
    $unit = Unit::factory()->create(['company_id' => test()->tenant->id]);

    return Product::factory()->withTax($tax)->create(['unit_id' => $unit->id]);
}

function siWarehouse(): Warehouse
{
    return Warehouse::factory()->create([
        'company_id' => test()->tenant->id,
        'branch_id' => test()->branch->id,
        'is_active' => true,
    ]);
}

/**
 * OC recibida por completo via HTTP, para que los totales y quantity_received
 * los calcule el servicio real y no el test.
 */
function siOrdenRecibida(Product $product, float $qty = 10, float $cost = 25): PurchaseOrder
{
    $resp = test()->postJson('/api/v1/purchase-orders', [
        'supplier_uuid' => test()->supplier->uuid,
        'branch_uuid' => test()->branch->uuid,
        'items' => [['product_uuid' => $product->uuid, 'quantity' => $qty, 'unit_cost' => $cost]],
    ], siHeaders());
    TenantContext::set(test()->tenant);

    $orden = PurchaseOrder::query()->where('uuid', $resp->json('data.uuid'))->firstOrFail();
    $orden->update(['status' => PurchaseOrder::STATUS_APPROVED, 'approved_at' => now()]);

    $almacen = siWarehouse();
    test()->postJson("/api/v1/purchase-orders/{$orden->uuid}/receive", [
        'warehouse_uuid' => $almacen->uuid,
        'items' => [['product_uuid' => $product->uuid, 'quantity' => $qty]],
    ], siHeaders());
    TenantContext::set(test()->tenant);

    return $orden->fresh(['items']);
}

function siFactura(float $subtotal = 250, float $tax = 40): SupplierInvoice
{
    $resp = test()->postJson('/api/v1/supplier-invoices', [
        'supplier_uuid' => test()->supplier->uuid,
        'folio' => 'F-'.fake()->unique()->numberBetween(1000, 9999),
        'issue_date' => '2026-08-01',
        'due_date' => '2026-09-01',
        'subtotal' => $subtotal,
        'tax_total' => $tax,
    ], siHeaders());
    TenantContext::set(test()->tenant);

    return SupplierInvoice::query()->where('uuid', $resp->json('data.uuid'))->firstOrFail();
}

it('crea una factura de proveedor y calcula el total como subtotal mas impuesto', function (): void {
    siActor([Permissions::SUPPLIER_INVOICE_CREATE]);

    $resp = $this->postJson('/api/v1/supplier-invoices', [
        'supplier_uuid' => $this->supplier->uuid,
        'folio' => 'A-001',
        'issue_date' => '2026-08-01',
        'due_date' => '2026-09-01',
        'subtotal' => 250,
        'tax_total' => 40,
    ], siHeaders());

    $resp->assertCreated();
    expect((float) $resp->json('data.total'))->toBe(290.0)
        ->and((float) $resp->json('data.paid_amount'))->toBe(0.0)
        ->and((float) $resp->json('data.balance'))->toBe(290.0)
        ->and($resp->json('data.status'))->toBe(SupplierInvoice::STATUS_PENDING);
});

it('rechaza el alta de factura sin el permiso de creacion', function (): void {
    siActor([Permissions::SUPPLIER_INVOICE_VIEW]);

    $this->postJson('/api/v1/supplier-invoices', [
        'supplier_uuid' => $this->supplier->uuid,
        'folio' => 'A-002',
        'issue_date' => '2026-08-01',
        'due_date' => '2026-09-01',
        'subtotal' => 100,
        'tax_total' => 0,
    ], siHeaders())->assertForbidden();
});

it('impide capturar dos veces el mismo folio del mismo proveedor', function (): void {
    siActor([Permissions::SUPPLIER_INVOICE_CREATE]);

    $payload = [
        'supplier_uuid' => $this->supplier->uuid,
        'folio' => 'DUP-1',
        'issue_date' => '2026-08-01',
        'due_date' => '2026-09-01',
        'subtotal' => 100,
        'tax_total' => 0,
    ];

    $this->postJson('/api/v1/supplier-invoices', $payload, siHeaders())->assertCreated();
    TenantContext::set($this->tenant);

    $this->postJson('/api/v1/supplier-invoices', $payload, siHeaders())
        ->assertStatus(422);
});

it('concilia la factura contra una orden recibida cuando el importe no excede', function (): void {
    siActor([Permissions::SUPPLIER_INVOICE_CREATE, Permissions::PURCHASE_ORDER_CREATE, Permissions::PURCHASE_ORDER_RECEIVE]);

    $producto = siProduct();
    $orden = siOrdenRecibida($producto, 10, 25);
    $factura = siFactura(250, 40);

    $resp = $this->postJson("/api/v1/supplier-invoices/{$factura->uuid}/match", [
        'purchase_order_uuid' => $orden->uuid,
    ], siHeaders());

    $resp->assertOk();
    expect($resp->json('data.purchase_order_uuid'))->toBe($orden->uuid);
});

it('rechaza conciliar cuando lo facturado excede el valor recibido', function (): void {
    siActor([Permissions::SUPPLIER_INVOICE_CREATE, Permissions::PURCHASE_ORDER_CREATE, Permissions::PURCHASE_ORDER_RECEIVE]);

    $producto = siProduct();
    $orden = siOrdenRecibida($producto, 10, 25);
    $factura = siFactura(500, 80);

    $this->postJson("/api/v1/supplier-invoices/{$factura->uuid}/match", [
        'purchase_order_uuid' => $orden->uuid,
    ], siHeaders())->assertStatus(422);
});

it('rechaza conciliar una factura que ya fue conciliada', function (): void {
    siActor([Permissions::SUPPLIER_INVOICE_CREATE, Permissions::PURCHASE_ORDER_CREATE, Permissions::PURCHASE_ORDER_RECEIVE]);

    $producto = siProduct();
    $orden = siOrdenRecibida($producto, 10, 25);
    $factura = siFactura(250, 40);

    $this->postJson("/api/v1/supplier-invoices/{$factura->uuid}/match", [
        'purchase_order_uuid' => $orden->uuid,
    ], siHeaders())->assertOk();
    TenantContext::set($this->tenant);

    $this->postJson("/api/v1/supplier-invoices/{$factura->uuid}/match", [
        'purchase_order_uuid' => $orden->uuid,
    ], siHeaders())->assertStatus(409);
});

it('registra un pago parcial y deja la factura en partial', function (): void {
    siActor([Permissions::SUPPLIER_INVOICE_CREATE, Permissions::SUPPLIER_INVOICE_PAY]);

    $factura = siFactura(250, 40);

    $resp = $this->postJson("/api/v1/supplier-invoices/{$factura->uuid}/pay", [
        'amount' => 100,
        'payment_date' => '2026-08-10',
        'method' => 'transferencia',
    ], siHeaders());

    $resp->assertOk();
    expect($resp->json('data.status'))->toBe(SupplierInvoice::STATUS_PARTIAL)
        ->and((float) $resp->json('data.paid_amount'))->toBe(100.0)
        ->and((float) $resp->json('data.balance'))->toBe(190.0);
});

it('marca la factura como paid cuando el pago cubre el total', function (): void {
    siActor([Permissions::SUPPLIER_INVOICE_CREATE, Permissions::SUPPLIER_INVOICE_PAY]);

    $factura = siFactura(250, 40);

    $resp = $this->postJson("/api/v1/supplier-invoices/{$factura->uuid}/pay", [
        'amount' => 290,
        'payment_date' => '2026-08-10',
        'method' => 'efectivo',
    ], siHeaders());

    $resp->assertOk();
    expect($resp->json('data.status'))->toBe(SupplierInvoice::STATUS_PAID)
        ->and((float) $resp->json('data.balance'))->toBe(0.0);
});

it('rechaza un pago que excede el saldo pendiente', function (): void {
    siActor([Permissions::SUPPLIER_INVOICE_CREATE, Permissions::SUPPLIER_INVOICE_PAY]);

    $factura = siFactura(250, 40);

    $this->postJson("/api/v1/supplier-invoices/{$factura->uuid}/pay", [
        'amount' => 500,
        'payment_date' => '2026-08-10',
        'method' => 'efectivo',
    ], siHeaders())->assertStatus(422);
});

it('rechaza pagar una factura ya saldada', function (): void {
    siActor([Permissions::SUPPLIER_INVOICE_CREATE, Permissions::SUPPLIER_INVOICE_PAY]);

    $factura = siFactura(250, 40);

    $this->postJson("/api/v1/supplier-invoices/{$factura->uuid}/pay", [
        'amount' => 290,
        'payment_date' => '2026-08-10',
        'method' => 'efectivo',
    ], siHeaders())->assertOk();
    TenantContext::set($this->tenant);

    $this->postJson("/api/v1/supplier-invoices/{$factura->uuid}/pay", [
        'amount' => 10,
        'payment_date' => '2026-08-11',
        'method' => 'efectivo',
    ], siHeaders())->assertStatus(409);
});

it('genera folios consecutivos de pago por tenant', function (): void {
    siActor([Permissions::SUPPLIER_INVOICE_CREATE, Permissions::SUPPLIER_INVOICE_PAY]);

    $a = siFactura(100, 0);
    $this->postJson("/api/v1/supplier-invoices/{$a->uuid}/pay", [
        'amount' => 50, 'payment_date' => '2026-08-10', 'method' => 'efectivo',
    ], siHeaders())->assertOk();
    TenantContext::set($this->tenant);

    $b = siFactura(100, 0);
    $this->postJson("/api/v1/supplier-invoices/{$b->uuid}/pay", [
        'amount' => 50, 'payment_date' => '2026-08-10', 'method' => 'efectivo',
    ], siHeaders())->assertOk();
    TenantContext::set($this->tenant);

    $folios = SupplierPayment::query()->orderBy('id')->pluck('folio')->all();
    expect($folios)->toBe(['PAY-000001', 'PAY-000002']);
});

it('devuelve el saldo agregado del proveedor', function (): void {
    siActor([Permissions::SUPPLIER_INVOICE_CREATE, Permissions::SUPPLIER_INVOICE_PAY, Permissions::SUPPLIER_INVOICE_VIEW]);

    $a = siFactura(200, 0);
    siFactura(100, 0);

    $this->postJson("/api/v1/supplier-invoices/{$a->uuid}/pay", [
        'amount' => 50, 'payment_date' => '2026-08-10', 'method' => 'efectivo',
    ], siHeaders())->assertOk();
    TenantContext::set($this->tenant);

    $resp = $this->getJson("/api/v1/suppliers/{$this->supplier->uuid}/balance", siHeaders());

    $resp->assertOk();
    expect($resp->json('data.invoices_count'))->toBe(2)
        ->and((float) $resp->json('data.total'))->toBe(300.0)
        ->and((float) $resp->json('data.paid_amount'))->toBe(50.0)
        ->and((float) $resp->json('data.balance'))->toBe(250.0);
});
