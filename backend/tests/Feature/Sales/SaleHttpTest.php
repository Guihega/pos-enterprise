<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Services\CashService;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Stock;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Sales\Models\Sale;
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
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA-01']);
    $this->warehouse = Warehouse::factory()->create([
        'company_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'is_sellable' => true,
        'is_active' => true,
    ]);

    $this->cashier = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cashier->assignRole(Roles::CAJERO);

    // Sesión de caja abierta para el cajero.
    $this->session = app(CashService::class)->openSession($this->register, $this->cashier, 1000);

    // Producto con IVA 16% inclusivo y stock inicial.
    // IMPORTANTE: Unit y Tax se crean explícitamente en el tenant en contexto.
    // Si se dejaran a la cadena de factories (unit_id => Unit::factory()), la
    // unidad nacería en un tenant nuevo y el trait BelongsToTenant abortaría la
    // creación con CrossTenantAccessException.
    $unit = Unit::factory()->create([
        'company_id' => $this->tenant->id,
        'code' => 'PZA-TEST',
    ]);
    $tax = Tax::factory()->create([
        'company_id' => $this->tenant->id,
        'code' => 'IVA-16', 'rate' => 0.16, 'is_inclusive' => true,
    ]);
    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $unit->id,
        'tax_id' => $tax->id,
        'price' => 116.00, 'track_inventory' => true, 'is_sellable' => true,
        'status' => Product::STATUS_ACTIVE,
    ]);
    app(InventoryService::class)->recordEntry($this->product, $this->warehouse, 100, 50);
});

/** Helper: payload base de un checkout válido. */
function checkoutPayload(array $overrides = []): array
{
    return array_merge([
        'cash_session_uuid' => test()->session->uuid,
        'warehouse_uuid' => test()->warehouse->uuid,
        'items' => [[
            'product_uuid' => test()->product->uuid,
            'quantity' => 2,
        ]],
        'payments' => [[
            'method' => 'cash',
            'amount' => 232.00,
            'tendered_amount' => 250.00,
        ]],
    ], $overrides);
}

// ====================================================================
//  Checkout exitoso
// ====================================================================

it('POST /sales registra una venta completa y devuelve 201', function () {
    Sanctum::actingAs($this->cashier);
    $response = $this->postJson('/api/v1/sales', checkoutPayload(), ['X-Tenant' => 'mi-tenant']);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.totals.total', 232)  // JSON encoder emite 232.0 como 232
        ->assertJsonPath('data.totals.change', 18);

    // El middleware terminate() llamó TenantContext::forget() tras el HTTP.
    // Re-establecemos el contexto para consultar la BD.
    TenantContext::set($this->tenant);
    expect(Sale::query()->count())->toBe(1);
});

it('descuenta stock tras la venta', function () {
    Sanctum::actingAs($this->cashier);
    $this->postJson('/api/v1/sales', checkoutPayload(), ['X-Tenant' => 'mi-tenant'])->assertCreated();

    // Re-establecer el contexto tras el HTTP (el middleware hizo forget()).
    TenantContext::set($this->tenant);
    $stock = Stock::query()
        ->where('product_id', $this->product->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->first();
    expect((float) $stock->quantity_on_hand)->toBe(98.0); // 100 - 2
});

it('genera folio con el formato de la caja', function () {
    Sanctum::actingAs($this->cashier);
    $response = $this->postJson('/api/v1/sales', checkoutPayload(), ['X-Tenant' => 'mi-tenant']);

    $response->assertCreated();
    expect($response->json('data.number'))->toContain('CAJA-01');
});

// ====================================================================
//  Validación y reglas de negocio
// ====================================================================

it('rechaza pago insuficiente con 422 PAYMENT_MISMATCH', function () {
    Sanctum::actingAs($this->cashier);
    $response = $this->postJson('/api/v1/sales', checkoutPayload([
        'payments' => [['method' => 'cash', 'amount' => 100.00]],
    ]), ['X-Tenant' => 'mi-tenant']);

    $response->assertStatus(422)->assertJsonPath('error.code', 'PAYMENT_MISMATCH');
});

it('rechaza sobrepago con método no-efectivo (422 PAYMENT_MISMATCH)', function () {
    Sanctum::actingAs($this->cashier);
    $response = $this->postJson('/api/v1/sales', checkoutPayload([
        'payments' => [['method' => 'card_debit', 'amount' => 300.00]],
    ]), ['X-Tenant' => 'mi-tenant']);

    $response->assertStatus(422)->assertJsonPath('error.code', 'PAYMENT_MISMATCH');
});

it('rechaza crédito sin cupo con 402 INSUFFICIENT_CREDIT', function () {
    $customer = Customer::factory()->create([
        'company_id' => $this->tenant->id,
        'credit_limit' => 50.00, 'credit_balance' => 0.00,
        'is_active' => true, 'is_blocked' => false,
    ]);

    Sanctum::actingAs($this->cashier);
    $response = $this->postJson('/api/v1/sales', checkoutPayload([
        'customer_uuid' => $customer->uuid,
        'payments' => [['method' => 'credit', 'amount' => 232.00]],
    ]), ['X-Tenant' => 'mi-tenant']);

    $response->assertStatus(402)->assertJsonPath('error.code', 'INSUFFICIENT_CREDIT');
});

it('rechaza venta sobre sesión de caja cerrada con 409', function () {
    app(CashService::class)->closeSession($this->session, $this->cashier, 1232);

    Sanctum::actingAs($this->cashier);
    $response = $this->postJson('/api/v1/sales', checkoutPayload(), ['X-Tenant' => 'mi-tenant']);

    $response->assertStatus(409)->assertJsonPath('error.code', 'SESSION_NOT_OPEN');
});

it('rechaza payload sin items con 422 (validación nativa)', function () {
    Sanctum::actingAs($this->cashier);
    $response = $this->postJson('/api/v1/sales', checkoutPayload(['items' => []]), ['X-Tenant' => 'mi-tenant']);

    $response->assertStatus(422)->assertJsonValidationErrors(['items']);
});

// ====================================================================
//  Autorización
// ====================================================================

it('un auditor (solo lectura) no puede crear ventas: 403', function () {
    $auditor = User::factory()->create(['company_id' => $this->tenant->id]);
    $auditor->assignRole(Roles::AUDITOR);

    Sanctum::actingAs($auditor);
    $response = $this->postJson('/api/v1/sales', checkoutPayload(), ['X-Tenant' => 'mi-tenant']);

    $response->assertStatus(403);
});

// ====================================================================
//  Listado, detalle y cancelación
// ====================================================================

it('GET /sales lista las ventas del tenant', function () {
    Sanctum::actingAs($this->cashier);
    $this->postJson('/api/v1/sales', checkoutPayload(), ['X-Tenant' => 'mi-tenant'])->assertCreated();

    $response = $this->getJson('/api/v1/sales', ['X-Tenant' => 'mi-tenant']);
    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);
});

it('GET /sales/{uuid} devuelve el detalle con items y pagos', function () {
    Sanctum::actingAs($this->cashier);
    $created = $this->postJson('/api/v1/sales', checkoutPayload(), ['X-Tenant' => 'mi-tenant']);
    $uuid = $created->json('data.uuid');

    $response = $this->getJson("/api/v1/sales/{$uuid}", ['X-Tenant' => 'mi-tenant']);
    $response->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonCount(1, 'data.payments');
});

it('POST /sales/{uuid}/cancel anula la venta y repone stock (supervisor)', function () {
    $supervisor = User::factory()->create(['company_id' => $this->tenant->id]);
    $supervisor->assignRole(Roles::SUPERVISOR);

    Sanctum::actingAs($this->cashier);
    $uuid = $this->postJson('/api/v1/sales', checkoutPayload(), ['X-Tenant' => 'mi-tenant'])->json('data.uuid');

    Sanctum::actingAs($supervisor);
    $response = $this->postJson("/api/v1/sales/{$uuid}/cancel",
        ['reason' => 'Cliente se arrepintió'], ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()->assertJsonPath('data.status', 'voided');

    // Re-establecer el contexto tras el HTTP (el middleware hizo forget()).
    TenantContext::set($this->tenant);
    $stock = Stock::query()
        ->where('product_id', $this->product->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->first();
    expect((float) $stock->quantity_on_hand)->toBe(100.0); // repuesto
});

it('un cajero no puede cancelar ventas: 403', function () {
    Sanctum::actingAs($this->cashier);
    $uuid = $this->postJson('/api/v1/sales', checkoutPayload(), ['X-Tenant' => 'mi-tenant'])->json('data.uuid');

    $response = $this->postJson("/api/v1/sales/{$uuid}/cancel",
        ['reason' => 'intento no autorizado'], ['X-Tenant' => 'mi-tenant']);

    $response->assertStatus(403);
});

// ====================================================================
//  Aislamiento entre tenants
// ====================================================================

it('una venta de otro tenant no es visible (404)', function () {
    Sanctum::actingAs($this->cashier);
    $uuid = $this->postJson('/api/v1/sales', checkoutPayload(), ['X-Tenant' => 'mi-tenant'])->json('data.uuid');

    // Segundo tenant con su propio cajero.
    $other = Company::factory()->create(['slug' => 'otro-tenant', 'country_code' => 'MX']);
    TenantContext::set($other);
    app(RoleProvisioner::class)->provisionDefaultRoles($other);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $otherCashier = User::factory()->create(['company_id' => $other->id]);
    $otherCashier->assignRole(Roles::CAJERO);

    Sanctum::actingAs($otherCashier);
    $response = $this->getJson("/api/v1/sales/{$uuid}", ['X-Tenant' => 'otro-tenant']);

    $response->assertStatus(404);
});
