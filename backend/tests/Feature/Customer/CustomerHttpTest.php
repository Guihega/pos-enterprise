<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'mi-tenant']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->admin->assignRole(Roles::ADMIN);

    $this->cashier = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cashier->assignRole(Roles::CAJERO);

    $this->auditor = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->auditor->assignRole(Roles::AUDITOR);
});

it('GET /customers con admin devuelve listado paginado', function () {
    Customer::factory()->count(5)->create();

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/customers', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [['uuid', 'name', 'tax', 'contact', 'address', 'credit', 'flags']],
            'meta',
        ]);
    expect($response->json('meta.total'))->toBe(5);
});

it('GET /customers?q=juan filtra por búsqueda', function () {
    Customer::factory()->create(['name' => 'Juan Pérez']);
    Customer::factory()->count(3)->create();

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/customers?q=juan', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);
});

it('GET /customers con cajero devuelve 200 (tiene CUSTOMER_VIEW)', function () {
    Customer::factory()->create();
    Sanctum::actingAs($this->cashier);
    $this->getJson('/api/v1/customers', ['X-Tenant' => 'mi-tenant'])->assertOk();
});

it('GET /customers con auditor devuelve 200 (tiene CUSTOMER_VIEW)', function () {
    Sanctum::actingAs($this->auditor);
    $this->getJson('/api/v1/customers', ['X-Tenant' => 'mi-tenant'])->assertOk();
});

it('POST /customers con admin crea cliente', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        '/api/v1/customers',
        [
            'type' => 'individual',
            'name' => 'Cliente Nuevo',
            'email' => 'nuevo@x.com',
            'phone' => '5551234567',
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Cliente Nuevo')
        ->assertJsonPath('data.type', 'individual');
});

it('POST /customers sin credit_limit no queda null (default 0 en BD)', function () {
    Sanctum::actingAs($this->admin);

    $response = $this->postJson(
        '/api/v1/customers',
        [
            'type' => 'individual',
            'name' => 'Cliente sin credito',
            // 'credit_limit', 'is_active', 'is_blocked' deliberadamente ausentes.
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertCreated();

    $this->assertDatabaseHas('customers', [
        'name' => 'Cliente sin credito',
        'company_id' => $this->tenant->id,
        'credit_limit' => 0,
        'credit_balance' => 0,
        'is_active' => true,
        'is_blocked' => false,
    ]);
});

it('POST /customers con credit_limit null no queda null (default 0 en BD)', function () {
    Sanctum::actingAs($this->admin);

    $response = $this->postJson(
        '/api/v1/customers',
        [
            'type' => 'individual',
            'name' => 'Cliente credito null',
            'credit_limit' => null,
            'is_active' => null,
            'is_blocked' => null,
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertCreated();

    $this->assertDatabaseHas('customers', [
        'name' => 'Cliente credito null',
        'company_id' => $this->tenant->id,
        'credit_limit' => 0,
        'is_active' => true,
        'is_blocked' => false,
    ]);
});

it('POST /customers con cajero crea cliente (tiene CUSTOMER_CREATE)', function () {
    Sanctum::actingAs($this->cashier);
    $response = $this->postJson(
        '/api/v1/customers',
        ['type' => 'individual', 'name' => 'Cliente Cajero'],
        ['X-Tenant' => 'mi-tenant']
    );
    $response->assertCreated();
});

it('POST /customers con auditor devuelve 403 (sin CUSTOMER_CREATE)', function () {
    Sanctum::actingAs($this->auditor);
    $response = $this->postJson(
        '/api/v1/customers',
        ['type' => 'individual', 'name' => 'X'],
        ['X-Tenant' => 'mi-tenant']
    );
    $response->assertStatus(403);
});

it('POST /customers business requiere campos extra', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        '/api/v1/customers',
        [
            'type' => 'business',
            'name' => 'ACME',
            'legal_name' => 'ACME S.A. de C.V.',
            'tax_id' => 'ACM010101AA1',
            'tax_data' => ['cfdi_use' => 'G03', 'fiscal_regime' => '601'],
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertCreated()
        ->assertJsonPath('data.tax.tax_id', 'ACM010101AA1')
        ->assertJsonPath('data.tax.data.cfdi_use', 'G03');
});

it('POST /customers con email duplicado en mismo tenant devuelve 422', function () {
    Customer::factory()->create(['email' => 'dup@x.com']);

    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        '/api/v1/customers',
        ['type' => 'individual', 'name' => 'Otro', 'email' => 'dup@x.com'],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('POST /customers permite mismo email en otro tenant', function () {
    // Crear cliente con email en tenant A
    Customer::factory()->create(['email' => 'shared@x.com']);

    // Crear tenant B con su propio admin
    $tenantB = Company::factory()->create(['slug' => 'tenant-b']);
    app(RoleProvisioner::class)->provisionDefaultRoles($tenantB);
    TenantContext::set($tenantB);
    $adminB = User::factory()->create(['company_id' => $tenantB->id]);
    $adminB->assignRole(Roles::ADMIN);

    Sanctum::actingAs($adminB);
    $response = $this->postJson(
        '/api/v1/customers',
        ['type' => 'individual', 'name' => 'B Customer', 'email' => 'shared@x.com'],
        ['X-Tenant' => 'tenant-b']
    );

    $response->assertCreated();
});

it('PATCH /customers/{uuid} actualiza campos', function () {
    $c = Customer::factory()->create(['name' => 'Original']);

    Sanctum::actingAs($this->admin);
    $response = $this->patchJson(
        "/api/v1/customers/{$c->uuid}",
        ['name' => 'Actualizado', 'credit_limit' => 5000],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertOk()
        ->assertJsonPath('data.name', 'Actualizado')
        ->assertJsonPath('data.credit.limit', 5000);
});

it('DELETE /customers/{uuid} sin saldo soft-borra', function () {
    $c = Customer::factory()->create(['credit_balance' => 0]);

    Sanctum::actingAs($this->admin);
    $response = $this->deleteJson(
        "/api/v1/customers/{$c->uuid}",
        [],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertNoContent();
});

it('DELETE /customers/{uuid} con saldo deudor devuelve 409', function () {
    $c = Customer::factory()->create(['credit_balance' => 1500]);

    Sanctum::actingAs($this->admin);
    $response = $this->deleteJson(
        "/api/v1/customers/{$c->uuid}",
        [],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'CUSTOMER_HAS_BALANCE');
});

it('GET /customers/{uuid} de otro tenant devuelve 404', function () {
    $tenantB = Company::factory()->create();
    TenantContext::set($tenantB);
    $cB = Customer::factory()->create(['company_id' => $tenantB->id]);

    TenantContext::set($this->tenant);
    Sanctum::actingAs($this->admin);
    $response = $this->getJson(
        "/api/v1/customers/{$cB->uuid}",
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(404);
});

it('GET /customers sin token devuelve 401', function () {
    $this->getJson('/api/v1/customers', ['X-Tenant' => 'mi-tenant'])
        ->assertStatus(401);
});

it('Aislamiento: GET /customers de tenant A no muestra los de B', function () {
    Customer::factory()->count(2)->create();

    $tenantB = Company::factory()->create();
    TenantContext::set($tenantB);
    Customer::factory()->count(7)->create(['company_id' => $tenantB->id]);

    TenantContext::set($this->tenant);
    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/customers', ['X-Tenant' => 'mi-tenant']);

    expect($response->json('meta.total'))->toBe(2);
});
