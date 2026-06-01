<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
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

// ====================================================================
//  Cash Registers
// ====================================================================

it('GET /cash/registers lista registros del tenant', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/cash/registers', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);
});

it('POST /cash/registers crea un registro', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        '/api/v1/cash/registers',
        [
            'branch_uuid' => $this->branch->uuid,
            'code' => 'CAJA-99',
            'name' => 'Caja secundaria',
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertCreated()->assertJsonPath('data.code', 'CAJA-99');
});

// ====================================================================
//  Sesiones: open / close
// ====================================================================

it('POST /cash/sessions/open con cajero abre sesión', function () {
    Sanctum::actingAs($this->cashier);
    $response = $this->postJson(
        '/api/v1/cash/sessions/open',
        [
            'cash_register_uuid' => $this->register->uuid,
            'opening_amount' => 1500,
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.opening.amount', 1500);
});

it('POST /cash/sessions/open en caja con sesión abierta devuelve 409', function () {
    app(CashService::class)->openSession($this->register, $this->cashier, 0);

    Sanctum::actingAs($this->cashier);
    $response = $this->postJson(
        '/api/v1/cash/sessions/open',
        ['cash_register_uuid' => $this->register->uuid],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'SESSION_ALREADY_OPEN');
});

it('POST /cash/sessions/{uuid}/close cierra la sesión', function () {
    $session = app(CashService::class)->openSession($this->register, $this->cashier, 1000);

    Sanctum::actingAs($this->cashier);
    $response = $this->postJson(
        "/api/v1/cash/sessions/{$session->uuid}/close",
        ['counted_amount' => 1000, 'closing_notes' => 'Sin diferencia'],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.closing.expected_amount', 1000)
        ->assertJsonPath('data.closing.difference', 0);
});

it('POST /cash/sessions/{uuid}/close en sesión ya cerrada devuelve 409', function () {
    $session = app(CashService::class)->openSession($this->register, $this->cashier, 0);
    app(CashService::class)->closeSession($session, $this->cashier, 0);

    Sanctum::actingAs($this->cashier);
    $response = $this->postJson(
        "/api/v1/cash/sessions/{$session->uuid}/close",
        ['counted_amount' => 0],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'SESSION_NOT_OPEN');
});

it('POST /cash/sessions/open sin permiso CASH_OPEN devuelve 403', function () {
    $auditor = User::factory()->create(['company_id' => $this->tenant->id]);
    $auditor->assignRole(Roles::AUDITOR);  // auditor solo lee

    Sanctum::actingAs($auditor);
    $response = $this->postJson(
        '/api/v1/cash/sessions/open',
        ['cash_register_uuid' => $this->register->uuid],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(403);
});

// ====================================================================
//  Movements
// ====================================================================

it('POST sessions/{uuid}/movements crea cash_in', function () {
    // El cajero abre la sesión (tiene CASH_OPEN), pero los movimientos manuales
    // requieren CASH_MOVEMENT que pertenece a admin/supervisor (decisión de diseño:
    // el cajero NO puede registrar cash_in/cash_out manuales, solo cobrar ventas).
    $session = app(CashService::class)->openSession($this->register, $this->cashier, 0);

    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        "/api/v1/cash/sessions/{$session->uuid}/movements",
        [
            'type' => 'cash_in',
            'amount' => 200,
            'reason' => 'Refuerzo de fondo',
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertCreated()
        ->assertJsonPath('data.type', 'cash_in')
        ->assertJsonPath('data.amount', 200)
        ->assertJsonPath('data.delta_signed', 200);
});

it('POST sessions/{uuid}/movements adjustment sin sign devuelve 422', function () {
    $session = app(CashService::class)->openSession($this->register, $this->cashier, 0);

    Sanctum::actingAs($this->admin);  // CASH_MOVEMENT pertenece a admin
    $response = $this->postJson(
        "/api/v1/cash/sessions/{$session->uuid}/movements",
        [
            'type' => 'adjustment',
            'amount' => 5,
            'reason' => 'Diferencia menor',
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['sign']);
});

it('POST sessions/{uuid}/movements en sesión cerrada devuelve 409', function () {
    $session = app(CashService::class)->openSession($this->register, $this->cashier, 0);
    app(CashService::class)->closeSession($session, $this->cashier, 0);

    // CASH_MOVEMENT lo tiene admin, no cajero
    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        "/api/v1/cash/sessions/{$session->uuid}/movements",
        ['type' => 'cash_in', 'amount' => 50, 'reason' => 'Tarde'],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'SESSION_NOT_OPEN');
});

it('POST sessions/{uuid}/movements con cajero devuelve 403 (sin CASH_MOVEMENT)', function () {
    // Decisión de diseño: el cajero abre/cobra/cierra pero NO registra movimientos
    // manuales (cash_in, cash_out, adjustment). Esos requieren CASH_MOVEMENT que
    // pertenece a admin/supervisor. El cajero solo puede cobrar ventas (módulo
    // de ventas, no este endpoint).
    $session = app(CashService::class)->openSession($this->register, $this->cashier, 0);

    Sanctum::actingAs($this->cashier);
    $response = $this->postJson(
        "/api/v1/cash/sessions/{$session->uuid}/movements",
        ['type' => 'cash_in', 'amount' => 100, 'reason' => 'Refuerzo'],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(403);
});

it('GET sessions/{uuid}/movements lista movements de la sesión', function () {
    $session = app(CashService::class)->openSession($this->register, $this->cashier, 0);
    app(CashService::class)->addMovement(
        session: $session, user: $this->cashier,
        type: 'cash_in', amount: 100, reason: 'X'
    );
    app(CashService::class)->addMovement(
        session: $session, user: $this->cashier,
        type: 'cash_out', amount: 25, reason: 'Y'
    );

    Sanctum::actingAs($this->cashier);
    $response = $this->getJson(
        "/api/v1/cash/sessions/{$session->uuid}/movements",
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(2);
});

// ====================================================================
//  Aislamiento
// ====================================================================

it('Aislamiento: sesiones de tenant A no visibles desde tenant B', function () {
    app(CashService::class)->openSession($this->register, $this->cashier, 0);

    $tenantB = Company::factory()->create(['slug' => 'tenant-b']);
    app(RoleProvisioner::class)->provisionDefaultRoles($tenantB);
    TenantContext::set($tenantB);
    $branchB = Branch::factory()->default()->create(['company_id' => $tenantB->id]);
    $registerB = CashRegister::factory()->ofBranch($branchB)->create();

    $adminB = User::factory()->create(['company_id' => $tenantB->id]);
    $adminB->assignRole(Roles::ADMIN);

    Sanctum::actingAs($adminB);
    $response = $this->getJson('/api/v1/cash/sessions', ['X-Tenant' => 'tenant-b']);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(0);  // ninguna sesión en tenant B
});

// ====================================================================
//  Regresion: route-model-binding tenant-scoped (orden middleware)
//  El TenantScope aplica WHERE FALSE sin contexto. Si EnsureTenantContext
//  corre DESPUES de SubstituteBindings, el binding {session:uuid} no
//  encuentra la fila y devuelve 404. Estos tests anclan el orden correcto.
// ====================================================================

it('GET /cash/sessions/{uuid} resuelve el binding con tenant del header (regresion 404)', function () {
    $session = app(CashService::class)->openSession($this->register, $this->cashier, 500);

    // Vaciar el contexto: solo el middleware EnsureTenantContext (disparado
    // por el header X-Tenant) puede restaurarlo. Si el binding corre antes
    // que el middleware, el TenantScope da WHERE FALSE y el show da 404.
    TenantContext::forget();

    Sanctum::actingAs($this->admin);
    $response = $this->getJson(
        "/api/v1/cash/sessions/{$session->uuid}",
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertOk()
        ->assertJsonPath('data.uuid', $session->uuid)
        ->assertJsonPath('data.status', 'open');
});

it('GET /cash/sessions/{uuid} de tenant A no es visible desde tenant B (404)', function () {
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
        "/api/v1/cash/sessions/{$session->uuid}",
        ['X-Tenant' => 'tenant-b']
    );

    $response->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});
