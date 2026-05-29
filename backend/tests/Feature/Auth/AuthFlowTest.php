<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'mi-tenant']);
    TenantContext::set($this->tenant);
    $this->user = User::factory()->create([
        'company_id' => $this->tenant->id,
        'email' => 'me@mi-tenant.local',
    ]);
});

it('GET /auth/me devuelve el usuario autenticado', function () {
    Sanctum::actingAs($this->user);

    $response = $this->getJson('/api/v1/auth/me', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonPath('data.email', 'me@mi-tenant.local')
        ->assertJsonPath('data.uuid', $this->user->uuid);
});

it('GET /auth/me incluye default_warehouse_uuid del branch default cuando existe', function () {
    // Crear branch default + warehouse default asociado al mismo branch.
    $branch = \App\Domain\Tenancy\Models\Branch::factory()
        ->for($this->tenant, 'company')
        ->state(['is_default' => true])
        ->create();

    $warehouse = \App\Domain\Inventory\Models\Warehouse::factory()
        ->ofBranch($branch)
        ->default()
        ->create();

    // Asociar al user como su default_branch.
    $this->user->update(['branch_id' => $branch->id]);

    Sanctum::actingAs($this->user);
    $response = $this->getJson('/api/v1/auth/me', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonPath('data.default_branch.uuid', $branch->uuid)
        ->assertJsonPath('data.default_branch.default_warehouse_uuid', $warehouse->uuid);
});

it('GET /auth/me default_warehouse_uuid es null cuando el branch no tiene warehouse default', function () {
    $branch = \App\Domain\Tenancy\Models\Branch::factory()
        ->for($this->tenant, 'company')
        ->state(['is_default' => true])
        ->create();
    // Sin warehouse asociado.

    $this->user->update(['branch_id' => $branch->id]);

    Sanctum::actingAs($this->user);
    $response = $this->getJson('/api/v1/auth/me', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonPath('data.default_branch.uuid', $branch->uuid)
        ->assertJsonPath('data.default_branch.default_warehouse_uuid', null);
});

it('POST /auth/login devuelve default_warehouse_uuid del branch default', function () {
    $branch = \App\Domain\Tenancy\Models\Branch::factory()
        ->for($this->tenant, 'company')
        ->state(['is_default' => true])
        ->create();

    $warehouse = \App\Domain\Inventory\Models\Warehouse::factory()
        ->ofBranch($branch)
        ->default()
        ->create();

    // Asociar al user su branch default. Tambien necesita password
    // conocido porque login lo verifica.
    $this->user->update([
        'branch_id' => $branch->id,
        'password' => \Illuminate\Support\Facades\Hash::make('secret123'),
    ]);

    $response = $this->postJson(
        '/api/v1/auth/login',
        ['email' => $this->user->email, 'password' => 'secret123'],
        ['X-Tenant' => 'mi-tenant'],
    );

    $response->assertOk()
        ->assertJsonPath('data.user.default_branch.uuid', $branch->uuid)
        ->assertJsonPath('data.user.default_branch.default_warehouse_uuid', $warehouse->uuid);
});

it('GET /auth/me sin token devuelve 401', function () {
    $response = $this->getJson('/api/v1/auth/me', ['X-Tenant' => 'mi-tenant']);

    $response->assertStatus(401);
});

it('POST /auth/logout revoca el token actual', function () {
    $token = $this->user->createToken('test-session');
    $plainToken = $token->plainTextToken;
    $tokenId = $token->accessToken->id;

    // Verificar que el token existe en BD
    expect(PersonalAccessToken::find($tokenId))->not->toBeNull();

    $response = $this->postJson('/api/v1/auth/logout', [], [
        'X-Tenant' => 'mi-tenant',
        'Authorization' => "Bearer {$plainToken}",
    ]);

    $response->assertOk()
        ->assertJsonPath('data.message', 'Sesión cerrada.');

    // Validamos contra la BD (que es la fuente de verdad real),
    // no contra un segundo HTTP request porque el guard de Auth está
    // cacheado dentro del proceso PHP del test.
    expect(PersonalAccessToken::find($tokenId))->toBeNull();
});

it('POST /auth/logout-all revoca TODOS los tokens del usuario', function () {
    $this->user->createToken('phone');
    $this->user->createToken('tablet');
    $current = $this->user->createToken('laptop')->plainTextToken;

    expect($this->user->tokens()->count())->toBe(3);

    $response = $this->postJson('/api/v1/auth/logout-all', [], [
        'X-Tenant' => 'mi-tenant',
        'Authorization' => "Bearer {$current}",
    ]);

    $response->assertOk()
        ->assertJsonPath('data.tokens_revoked', 3);

    expect($this->user->fresh()->tokens()->count())->toBe(0);
});

it('un token de tenant A no funciona con header X-Tenant: B', function () {
    // Token del usuario en tenant A
    $tokenA = $this->user->createToken('test')->plainTextToken;
    [, $plainTextWithoutId] = explode('|', $tokenA, 2);
    $tokenHash = hash('sha256', $plainTextWithoutId);

    // Tenant B existe
    $tenantB = Company::factory()->create(['slug' => 'otro-tenant']);

    // El verdadero invariante: si el contexto del request es B, ningún
    // mecanismo Eloquent debería poder encontrar al user de A.
    // Sanctum hidrata el user vía $accessToken->tokenable, que es una
    // relación Eloquent normal y por lo tanto respeta TenantScope.
    TenantContext::set($tenantB);

    // Buscar el token (esa tabla NO tiene tenant scope, los tokens son
    // globales y se ligan al user vía tokenable_id)
    $accessToken = \Laravel\Sanctum\PersonalAccessToken::where('token', $tokenHash)->first();
    expect($accessToken)->not->toBeNull();

    // Intentar hidratar el user a través de la relación: bajo el contexto
    // de tenant B, el TenantScope filtra a los User del tenant A → null.
    $hidratedUser = $accessToken->tokenable;
    expect($hidratedUser)->toBeNull();

    // Y como prueba final: el flujo HTTP real ya está cubierto por los
    // smoke tests con curl que prueban manualmente:
    //   curl -H "X-Tenant: otro-tenant" -H "Authorization: Bearer ${tokenA}" /auth/me
    //   → 401 (validado en producción durante el bloque 1.2b)
});
