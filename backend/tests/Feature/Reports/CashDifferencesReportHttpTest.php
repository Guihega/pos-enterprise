<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Models\CashSession;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'cdr-tenant', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA-CDR']);

    $this->admin = User::factory()->create(['company_id' => $this->tenant->id, 'name' => 'Ana Admin']);
    $this->admin->assignRole(Roles::ADMIN);
    $this->cashier = User::factory()->create(['company_id' => $this->tenant->id, 'name' => 'Beto Cajero']);
    $this->cashier->assignRole(Roles::CAJERO);
});

/**
 * Sesion CERRADA con arqueo. closed_at explicito: el factory pone now() y
 * entonces el filtro por rango no probaria nada.
 *
 * Prefijo cdr* para no colisionar: los helpers de un archivo de test son
 * funciones GLOBALES y Pest carga todo en el mismo proceso.
 */
function cdrSession(string $closedAt, float $expected, float $counted, ?int $branchId = null): CashSession
{
    return CashSession::factory()->closed($expected, $counted)->create([
        'company_id' => test()->tenant->id,
        'cash_register_id' => test()->register->id,
        'branch_id' => $branchId ?? test()->branch->id,
        'opened_by' => test()->admin->id,
        'closed_by' => test()->cashier->id,
        'closed_at' => $closedAt,
    ]);
}

it('lista diferencias de sesiones cerradas con totales de faltante y sobrante', function () {
    TenantContext::set($this->tenant);
    cdrSession('2026-06-10 20:00:00', 1000.00, 950.00);
    cdrSession('2026-06-15 20:00:00', 1000.00, 1030.00);
    cdrSession('2026-06-20 20:00:00', 1000.00, 1000.00);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/reports/cash-differences?from=2026-06-01&to=2026-06-30', ['X-Tenant' => 'cdr-tenant']);

    $response->assertOk()
        ->assertJsonPath('data.from', '2026-06-01')
        ->assertJsonCount(3, 'data.rows')
        ->assertJsonPath('data.totals.sessions_count', 3)
        ->assertJsonPath('data.totals.net_difference', -20)
        ->assertJsonPath('data.totals.shortage_amount', -50)
        ->assertJsonPath('data.totals.overage_amount', 30)
        ->assertJsonPath('data.totals.balanced_count', 1)
        ->assertJsonPath('data.rows.0.difference', -50)
        ->assertJsonPath('data.rows.0.register_code', 'CAJA-CDR')
        ->assertJsonPath('data.rows.0.closed_by_name', 'Beto Cajero');
});

it('excluye sesiones abiertas y anuladas', function () {
    TenantContext::set($this->tenant);
    cdrSession('2026-06-10 20:00:00', 1000.00, 900.00);

    $otro = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA-CDR2']);
    CashSession::factory()->open()->create([
        'company_id' => $this->tenant->id,
        'cash_register_id' => $otro->id,
        'branch_id' => $this->branch->id,
        'opened_by' => $this->admin->id,
    ]);
    CashSession::factory()->closed(500.0, 400.0)->create([
        'company_id' => $this->tenant->id,
        'cash_register_id' => $otro->id,
        'branch_id' => $this->branch->id,
        'opened_by' => $this->admin->id,
        'status' => CashSession::STATUS_VOIDED,
        'closed_at' => '2026-06-12 20:00:00',
    ]);

    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/reports/cash-differences?from=2026-06-01&to=2026-06-30', ['X-Tenant' => 'cdr-tenant'])
        ->assertOk()
        ->assertJsonCount(1, 'data.rows')
        ->assertJsonPath('data.totals.net_difference', -100);
});

it('excluye sesiones fuera del rango por closed_at', function () {
    TenantContext::set($this->tenant);
    cdrSession('2026-06-10 20:00:00', 1000.00, 950.00);
    cdrSession('2026-07-05 20:00:00', 1000.00, 500.00);

    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/reports/cash-differences?from=2026-06-01&to=2026-06-30', ['X-Tenant' => 'cdr-tenant'])
        ->assertOk()
        ->assertJsonCount(1, 'data.rows')
        ->assertJsonPath('data.totals.net_difference', -50);
});

it('filtra por branch_uuid', function () {
    TenantContext::set($this->tenant);
    $otra = Branch::factory()->create(['company_id' => $this->tenant->id, 'code' => 'NRT-CDR']);
    cdrSession('2026-06-10 20:00:00', 1000.00, 950.00);
    cdrSession('2026-06-11 20:00:00', 1000.00, 100.00, $otra->id);

    Sanctum::actingAs($this->admin);
    $this->getJson("/api/v1/reports/cash-differences?from=2026-06-01&to=2026-06-30&branch_uuid={$this->branch->uuid}", ['X-Tenant' => 'cdr-tenant'])
        ->assertOk()
        ->assertJsonPath('data.branch.uuid', $this->branch->uuid)
        ->assertJsonCount(1, 'data.rows');
});

it('sin sesiones cerradas devuelve rows vacio y totales en cero', function () {
    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/reports/cash-differences?from=2026-06-01&to=2026-06-30', ['X-Tenant' => 'cdr-tenant'])
        ->assertOk()
        ->assertJsonCount(0, 'data.rows')
        ->assertJsonPath('data.totals.sessions_count', 0)
        ->assertJsonPath('data.totals.net_difference', 0);
});

it('un cajero sin permiso de reportes recibe 403', function () {
    Sanctum::actingAs($this->cashier);
    $this->getJson('/api/v1/reports/cash-differences?from=2026-06-01&to=2026-06-30', ['X-Tenant' => 'cdr-tenant'])
        ->assertStatus(403);
});

it('from y to son obligatorios (422)', function () {
    Sanctum::actingAs($this->admin);
    $this->getJson('/api/v1/reports/cash-differences', ['X-Tenant' => 'cdr-tenant'])
        ->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
});
