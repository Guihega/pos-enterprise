<?php

declare(strict_types=1);

use App\Domain\Authorization\Permissions;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierInvoice;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'pa-tenant']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->supplier = Supplier::factory()->create(['code' => 'PROV-PA']);
});

function paActor(array $permisos): User
{
    $u = User::factory()->create(['company_id' => test()->tenant->id]);
    $u->givePermissionTo($permisos);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Sanctum::actingAs($u);

    return $u;
}

function paHeaders(): array
{
    return ['X-Tenant' => 'pa-tenant'];
}

/**
 * Factura via HTTP para que total/paid_amount/status los derive el
 * servicio real. issue_date siempre 30 dias antes del vencimiento.
 */
function paFactura(Carbon $vence, float $subtotal = 100, ?Supplier $proveedor = null): SupplierInvoice
{
    $resp = test()->postJson('/api/v1/supplier-invoices', [
        'supplier_uuid' => ($proveedor ?? test()->supplier)->uuid,
        'folio' => 'F-'.fake()->unique()->numberBetween(1000, 9999),
        'issue_date' => $vence->copy()->subDays(30)->toDateString(),
        'due_date' => $vence->toDateString(),
        'subtotal' => $subtotal,
        'tax_total' => 0,
    ], paHeaders());
    TenantContext::set(test()->tenant);

    return SupplierInvoice::query()->where('uuid', $resp->json('data.uuid'))->firstOrFail();
}

function paPagar(SupplierInvoice $factura, float $monto): void
{
    test()->postJson("/api/v1/supplier-invoices/{$factura->uuid}/pay", [
        'amount' => $monto,
        'payment_date' => now()->toDateString(),
        'method' => 'transferencia',
    ], paHeaders())->assertOk();
    TenantContext::set(test()->tenant);
}

it('rechaza el reporte de antiguedad de cuentas por pagar sin permiso financiero', function (): void {
    paActor([Permissions::SUPPLIER_INVOICE_VIEW]);

    $this->getJson('/api/v1/reports/payables-aging', paHeaders())->assertForbidden();
});

it('clasifica las facturas vivas en las cinco cubetas de antiguedad', function (): void {
    paActor([Permissions::SUPPLIER_INVOICE_CREATE, Permissions::REPORT_FINANCE]);

    // Derivacion a mano (hoy = dia 0), leccion 36:
    // vence hoy+10   -> no vencida       -> current  = 100
    // vencio hace 15 -> 1..30 dias       -> d1_30    = 200
    // vencio hace 45 -> 31..60 dias      -> d31_60   = 300
    // vencio hace 75 -> 61..90 dias      -> d61_90   = 400
    // vencio hace 120 -> mas de 90 dias  -> d90_plus = 500
    // balance = 100+200+300+400+500 = 1500; invoices_count = 5
    paFactura(now()->addDays(10), 100);
    paFactura(now()->subDays(15), 200);
    paFactura(now()->subDays(45), 300);
    paFactura(now()->subDays(75), 400);
    paFactura(now()->subDays(120), 500);

    $resp = $this->getJson('/api/v1/reports/payables-aging', paHeaders());

    $resp->assertOk();
    $fila = $resp->json('data.rows.0');
    expect($resp->json('data.rows'))->toHaveCount(1)
        ->and($fila['supplier_uuid'])->toBe($this->supplier->uuid)
        ->and($fila['invoices_count'])->toBe(5)
        ->and((float) $fila['current'])->toEqualWithDelta(100.0, 0.01)
        ->and((float) $fila['d1_30'])->toEqualWithDelta(200.0, 0.01)
        ->and((float) $fila['d31_60'])->toEqualWithDelta(300.0, 0.01)
        ->and((float) $fila['d61_90'])->toEqualWithDelta(400.0, 0.01)
        ->and((float) $fila['d90_plus'])->toEqualWithDelta(500.0, 0.01)
        ->and((float) $fila['balance'])->toEqualWithDelta(1500.0, 0.01);
});

it('excluye pagadas y canceladas y netea el pago parcial en la antiguedad', function (): void {
    paActor([Permissions::SUPPLIER_INVOICE_CREATE, Permissions::SUPPLIER_INVOICE_PAY, Permissions::REPORT_FINANCE]);

    // Derivacion a mano: viva parcial 100 - 40 pagados = 60 de saldo.
    // La pagada (200 con pago 200) y la cancelada (300) quedan fuera.
    $parcial = paFactura(now()->subDays(5), 100);
    paPagar($parcial, 40);

    $pagada = paFactura(now()->subDays(5), 200);
    paPagar($pagada, 200);

    $cancelada = paFactura(now()->subDays(5), 300);
    // No existe endpoint de cancelacion: se fuerza el estado directo
    // (status lo mueve solo el servicio y esta fuera de fillable).
    $cancelada->forceFill(['status' => SupplierInvoice::STATUS_CANCELLED])->save();

    $resp = $this->getJson('/api/v1/reports/payables-aging', paHeaders());

    $resp->assertOk();
    expect($resp->json('data.rows'))->toHaveCount(1)
        ->and($resp->json('data.rows.0.invoices_count'))->toBe(1)
        ->and((float) $resp->json('data.rows.0.balance'))->toEqualWithDelta(60.0, 0.01)
        ->and((float) $resp->json('data.totals.balance'))->toEqualWithDelta(60.0, 0.01);
});

it('ordena proveedores por saldo descendente y acumula totales por cubeta', function (): void {
    paActor([Permissions::SUPPLIER_INVOICE_CREATE, Permissions::REPORT_FINANCE]);

    // Derivacion a mano: PROV-PA con 100 (vencida hace 15 -> d1_30);
    // PROV-PA2 con 500 (no vencida -> current). Orden: PA2 antes que PA.
    // totals: current 500, d1_30 100, balance 600.
    paFactura(now()->subDays(15), 100);

    $otro = Supplier::factory()->create(['code' => 'PROV-PA2']);
    paFactura(now()->addDays(10), 500, $otro);

    $resp = $this->getJson('/api/v1/reports/payables-aging', paHeaders());

    $resp->assertOk();
    expect($resp->json('data.rows.0.supplier_uuid'))->toBe($otro->uuid)
        ->and($resp->json('data.rows.1.supplier_uuid'))->toBe($this->supplier->uuid)
        ->and((float) $resp->json('data.totals.current'))->toEqualWithDelta(500.0, 0.01)
        ->and((float) $resp->json('data.totals.d1_30'))->toEqualWithDelta(100.0, 0.01)
        ->and((float) $resp->json('data.totals.balance'))->toEqualWithDelta(600.0, 0.01);
});
