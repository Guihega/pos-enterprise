<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Cash\Models\CashMovement;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Services\CashService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'mi-tenant']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA-01']);

    $this->admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->admin->assignRole(Roles::ADMIN);

    $this->cashier = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cashier->assignRole(Roles::CAJERO);
});

it('GET sessions/{uuid}/report de sesion recien abierta (corte X vacio)', function () {
    $session = app(CashService::class)->openSession($this->register, $this->cashier, 1000);

    Sanctum::actingAs($this->cashier);
    $response = $this->getJson(
        "/api/v1/cash/sessions/{$session->uuid}/report",
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertOk()
        ->assertJsonPath('data.session.status', 'open')
        ->assertJsonPath('data.session.closing', null)
        ->assertJsonPath('data.sales.count', 0)
        ->assertJsonPath('data.sales.total_amount', 0)
        ->assertJsonPath('data.payments', [])
        ->assertJsonPath('data.movements', [])
        ->assertJsonPath('data.cash.opening_amount', 1000)
        ->assertJsonPath('data.cash.cash_affecting_delta', 0)
        ->assertJsonPath('data.cash.expected_amount', 1000)
        ->assertJsonPath('data.cash.counted_amount', null)
        ->assertJsonPath('data.cash.difference', null);
});

it('GET sessions/{uuid}/report con cash_in/cash_out refleja expected (corte X)', function () {
    $session = app(CashService::class)->openSession($this->register, $this->cashier, 1000);

    app(CashService::class)->addMovement(
        session: $session, user: $this->admin,
        type: CashMovement::TYPE_CASH_IN, amount: 300, reason: 'Refuerzo'
    );
    app(CashService::class)->addMovement(
        session: $session, user: $this->admin,
        type: CashMovement::TYPE_CASH_OUT, amount: 100, reason: 'Pago proveedor'
    );

    Sanctum::actingAs($this->admin);
    $response = $this->getJson(
        "/api/v1/cash/sessions/{$session->uuid}/report",
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertOk()
        ->assertJsonPath('data.cash.opening_amount', 1000)
        ->assertJsonPath('data.cash.cash_affecting_delta', 200)
        ->assertJsonPath('data.cash.expected_amount', 1200)
        ->assertJsonPath('data.cash.counted_amount', null);

    $movements = $response->json('data.movements');
    expect($movements)->toHaveCount(2);

    $byType = collect($movements)->keyBy('type');
    expect($byType['cash_in']['amount'])->toBe(300)
        ->and($byType['cash_in']['delta_signed'])->toBe(300)
        ->and($byType['cash_out']['amount'])->toBe(100)
        ->and($byType['cash_out']['delta_signed'])->toBe(-100);
});

it('GET sessions/{uuid}/report tras cerrar refleja el cierre persistido (corte Z)', function () {
    $session = app(CashService::class)->openSession($this->register, $this->cashier, 1000);

    app(CashService::class)->addMovement(
        session: $session, user: $this->admin,
        type: CashMovement::TYPE_CASH_IN, amount: 100, reason: 'Refuerzo'
    );

    // Expected = 1000 + 100 = 1100. Cajero cuenta 1095 (faltante de 5).
    app(CashService::class)->closeSession($session->fresh(), $this->cashier, 1095, 'Cierre con faltante');

    Sanctum::actingAs($this->admin);
    $response = $this->getJson(
        "/api/v1/cash/sessions/{$session->uuid}/report",
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertOk()
        ->assertJsonPath('data.session.status', 'closed')
        ->assertJsonPath('data.session.closing.expected_amount', 1100)
        ->assertJsonPath('data.session.closing.counted_amount', 1095)
        ->assertJsonPath('data.session.closing.difference', -5)
        ->assertJsonPath('data.cash.opening_amount', 1000)
        ->assertJsonPath('data.cash.cash_affecting_delta', 100)
        ->assertJsonPath('data.cash.expected_amount', 1100)
        ->assertJsonPath('data.cash.counted_amount', 1095)
        ->assertJsonPath('data.cash.difference', -5);
});

it('GET sessions/{uuid}/report con cajero (CASH_VIEW) funciona', function () {
    $session = app(CashService::class)->openSession($this->register, $this->cashier, 0);

    Sanctum::actingAs($this->cashier);
    $response = $this->getJson(
        "/api/v1/cash/sessions/{$session->uuid}/report",
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertOk();
});

it('GET sessions/{uuid}/report sin permiso CASH_VIEW devuelve 403', function () {
    $session = app(CashService::class)->openSession($this->register, $this->cashier, 0);

    // ALMACEN no tiene CASH_VIEW (ver Roles::defaultMatrix).
    $almacen = User::factory()->create(['company_id' => $this->tenant->id]);
    $almacen->assignRole(Roles::ALMACEN);

    Sanctum::actingAs($almacen);
    $response = $this->getJson(
        "/api/v1/cash/sessions/{$session->uuid}/report",
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(403);
});

it('GET sessions/{uuid}/report de tenant A no es visible desde tenant B (404)', function () {
    $session = app(CashService::class)->openSession($this->register, $this->cashier, 500);

    $tenantB = Company::factory()->create(['slug' => 'tenant-b']);
    app(RoleProvisioner::class)->provisionDefaultRoles($tenantB);
    TenantContext::set($tenantB);
    Branch::factory()->default()->create(['company_id' => $tenantB->id]);
    $adminB = User::factory()->create(['company_id' => $tenantB->id]);
    $adminB->assignRole(Roles::ADMIN);

    TenantContext::forget();

    Sanctum::actingAs($adminB);
    $response = $this->getJson(
        "/api/v1/cash/sessions/{$session->uuid}/report",
        ['X-Tenant' => 'tenant-b']
    );

    $response->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});
