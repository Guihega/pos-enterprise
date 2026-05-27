<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'mi-tenant']);
    TenantContext::set($this->tenant);
});

it('login exitoso devuelve 200 con token y datos del usuario', function () {
    $branch = Branch::factory()->create(['company_id' => $this->tenant->id]);
    $user = User::factory()->create([
        'company_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'email' => 'admin@mi-tenant.local',
        'password' => Hash::make('secret123'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@mi-tenant.local',
        'password' => 'secret123',
    ], ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'user' => ['uuid', 'name', 'email', 'is_active'],
                'token',
                'token_type',
            ],
        ])
        ->assertJsonPath('data.user.email', 'admin@mi-tenant.local')
        ->assertJsonPath('data.token_type', 'Bearer');

    // El formato real de un token Sanctum 4 con prefijo es:
    //   {tokenId}|pos_{plainText}
    // Ej: 6|pos_Zk2jfHbdd5LRqG0o4IT21HW...
    expect($response->json('data.token'))->toMatch('/^\d+\|pos_[a-zA-Z0-9]+$/');
});

it('login con password incorrecto devuelve 401 INVALID_CREDENTIALS', function () {
    User::factory()->create([
        'company_id' => $this->tenant->id,
        'email' => 'admin@mi-tenant.local',
        'password' => Hash::make('secret123'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@mi-tenant.local',
        'password' => 'wrong-password',
    ], ['X-Tenant' => 'mi-tenant']);

    $response->assertStatus(401)
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
});

it('login con email inexistente devuelve 401 INVALID_CREDENTIALS (anti-enumeración)', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'no-existe@mi-tenant.local',
        'password' => 'cualquiera',
    ], ['X-Tenant' => 'mi-tenant']);

    $response->assertStatus(401)
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
});

it('login con cuenta inactiva devuelve 403 ACCOUNT_INACTIVE', function () {
    User::factory()->inactive()->create([
        'company_id' => $this->tenant->id,
        'email' => 'inactive@mi-tenant.local',
        'password' => Hash::make('secret123'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'inactive@mi-tenant.local',
        'password' => 'secret123',
    ], ['X-Tenant' => 'mi-tenant']);

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
});

it('login con cuenta bloqueada devuelve 423 ACCOUNT_LOCKED con seconds_remaining', function () {
    User::factory()->locked()->create([
        'company_id' => $this->tenant->id,
        'email' => 'locked@mi-tenant.local',
        'password' => Hash::make('secret123'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'locked@mi-tenant.local',
        'password' => 'secret123',
    ], ['X-Tenant' => 'mi-tenant']);

    $response->assertStatus(423)
        ->assertJsonPath('error.code', 'ACCOUNT_LOCKED')
        ->assertJsonStructure(['error' => ['details' => ['locked_until', 'seconds_remaining']]]);

    expect($response->json('error.details.seconds_remaining'))->toBeGreaterThan(0);
});

it('quinto intento fallido bloquea la cuenta', function () {
    User::factory()->create([
        'company_id' => $this->tenant->id,
        'email' => 'will-lock@mi-tenant.local',
        'password' => Hash::make('secret123'),
    ]);

    for ($i = 1; $i <= 4; $i++) {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'will-lock@mi-tenant.local',
            'password' => 'wrong',
        ], ['X-Tenant' => 'mi-tenant']);
        $response->assertStatus(401);
    }

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'will-lock@mi-tenant.local',
        'password' => 'wrong',
    ], ['X-Tenant' => 'mi-tenant']);
    $response->assertStatus(423)
        ->assertJsonPath('error.code', 'ACCOUNT_LOCKED');
});

it('un usuario de tenant A no puede loguear vía X-Tenant: B', function () {
    User::factory()->create([
        'company_id' => $this->tenant->id,
        'email' => 'admin@mi-tenant.local',
        'password' => Hash::make('secret123'),
    ]);

    $tenantB = Company::factory()->create(['slug' => 'otro-tenant']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@mi-tenant.local',
        'password' => 'secret123',
    ], ['X-Tenant' => 'otro-tenant']);

    $response->assertStatus(401)
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
});

it('login con header X-Tenant inválido devuelve 400', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'cualquiera@test.com',
        'password' => 'cualquiera',
    ], ['X-Tenant' => 'no-existe']);

    $response->assertStatus(400)
        ->assertJsonPath('error.code', 'TENANT_NOT_RESOLVED');
});

it('login con tenant suspendido devuelve 402', function () {
    Company::factory()->suspended('No pago')->create(['slug' => 'suspendido']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'cualquiera@test.com',
        'password' => 'cualquiera',
    ], ['X-Tenant' => 'suspendido']);

    $response->assertStatus(402)
        ->assertJsonPath('error.code', 'TENANT_SUSPENDED');
});

it('login con email mal formado devuelve 422 VALIDATION', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'no-es-email',
        'password' => 'something',
    ], ['X-Tenant' => 'mi-tenant']);

    $response->assertStatus(422);
});

it('login exitoso registra last_login_at, last_login_ip y device_id', function () {
    $user = User::factory()->create([
        'company_id' => $this->tenant->id,
        'email' => 'admin@mi-tenant.local',
        'password' => Hash::make('secret123'),
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@mi-tenant.local',
        'password' => 'secret123',
        'device_id' => 'tablet-cdr-001',
    ], ['X-Tenant' => 'mi-tenant'])->assertOk();

    $fresh = $user->fresh();
    expect($fresh->last_login_at)->not->toBeNull()
        ->and($fresh->last_login_ip)->not->toBeNull()
        ->and($fresh->last_login_device_id)->toBe('tablet-cdr-001')
        ->and($fresh->failed_login_attempts)->toBe(0);
});
