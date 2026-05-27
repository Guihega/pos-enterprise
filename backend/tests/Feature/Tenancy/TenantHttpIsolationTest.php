<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Aislamiento entre tenants a nivel HTTP REAL, con token vivo.
 *
 * Complementa a:
 *   - TenantIsolationTest      (aislamiento a nivel Eloquent / TenantContext)
 *   - EnsureTenantContextTest  (resolución del tenant, sin token)
 *   - AuthFlowTest             (verifica el invariante a nivel Eloquent y deja
 *                               el flujo HTTP real como comentario con curl)
 *
 * Este archivo cierra el hallazgo M3 de la revisión técnica: convierte ese
 * "probado manualmente con curl" en aserciones automáticas. El escenario
 * crítico es emitir un token autenticando de verdad en el tenant A (vía
 * POST /auth/login) y luego usar ese token apuntando al tenant B con la
 * cabecera X-Tenant. El sistema NO debe permitir que el token de A opere
 * como si fuera de B, ni exponer datos de ningún tenant.
 */
beforeEach(function () {
    // --- Tenant A, con un admin real (login por HTTP) ---
    $this->tenantA = Company::factory()->create(['slug' => 'tenant-a', 'country_code' => 'MX']);
    TenantContext::set($this->tenantA);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenantA);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $branchA = Branch::factory()->default()->create(['company_id' => $this->tenantA->id]);
    $this->userA = User::factory()->create([
        'company_id' => $this->tenantA->id,
        'branch_id' => $branchA->id,
        'email' => 'admin@tenant-a.local',
        'password' => Hash::make('secret123'),
    ]);
    $this->userA->assignRole(Roles::ADMIN);

    $unitA = Unit::factory()->create(['company_id' => $this->tenantA->id, 'code' => 'PZA-A']);
    $this->productA = Product::factory()->create([
        'company_id' => $this->tenantA->id,
        'unit_id' => $unitA->id,
        'name' => 'Producto exclusivo de A',
        'status' => Product::STATUS_ACTIVE,
    ]);

    // --- Tenant B, con su propio producto ---
    $this->tenantB = Company::factory()->create(['slug' => 'tenant-b', 'country_code' => 'MX']);
    TenantContext::set($this->tenantB);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenantB);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $unitB = Unit::factory()->create(['company_id' => $this->tenantB->id, 'code' => 'PZA-B']);
    $this->productB = Product::factory()->create([
        'company_id' => $this->tenantB->id,
        'unit_id' => $unitB->id,
        'name' => 'Producto exclusivo de B',
        'status' => Product::STATUS_ACTIVE,
    ]);

    // Limpiamos el contexto: las peticiones HTTP deben resolverlo por sí solas.
    TenantContext::forget();
});

/** Helper: hace login HTTP real en un tenant y devuelve el token plano. */
function loginAs(string $email, string $password, string $tenantSlug): string
{
    $response = test()->postJson('/api/v1/auth/login',
        ['email' => $email, 'password' => $password],
        ['X-Tenant' => $tenantSlug]
    );
    $response->assertOk();

    return $response->json('data.token');
}

// ====================================================================
//  Control positivo: el token de A SÍ funciona en su propio tenant
// ====================================================================

it('un token valido de A funciona con X-Tenant: A (control positivo)', function () {
    $tokenA = loginAs('admin@tenant-a.local', 'secret123', 'tenant-a');

    $response = $this->getJson('/api/v1/auth/me', [
        'X-Tenant' => 'tenant-a',
        'Authorization' => "Bearer {$tokenA}",
    ]);

    $response->assertOk()->assertJsonPath('data.email', 'admin@tenant-a.local');
});

// ====================================================================
//  El núcleo del hallazgo M3: token de A + X-Tenant: B
// ====================================================================

it('un token de A NO puede operar como tenant B en /auth/me', function () {
    $tokenA = loginAs('admin@tenant-a.local', 'secret123', 'tenant-a');

    // Mismo token, pero apuntando al tenant B.
    $response = $this->getJson('/api/v1/auth/me', [
        'X-Tenant' => 'tenant-b',
        'Authorization' => "Bearer {$tokenA}",
    ]);

    // No debe autenticar al usuario de A bajo el contexto de B.
    // Bajo el contexto B, el TenantScope no encuentra al user de A → 401.
    $response->assertStatus(401);

    // Y en ningún caso debe devolver los datos del usuario de A.
    expect($response->json('data.email'))->not->toBe('admin@tenant-a.local');
});

it('un token de A con X-Tenant: B no expone productos de B', function () {
    $tokenA = loginAs('admin@tenant-a.local', 'secret123', 'tenant-a');

    $response = $this->getJson('/api/v1/products', [
        'X-Tenant' => 'tenant-b',
        'Authorization' => "Bearer {$tokenA}",
    ]);

    // El acceso se rechaza (401): el token de A no es válido en contexto B.
    $response->assertStatus(401);

    // Defensa adicional: la respuesta no debe contener el producto de B.
    expect($response->getContent())->not->toContain('Producto exclusivo de B');
});

it('un token de A con X-Tenant: A solo ve productos de A, nunca de B', function () {
    $tokenA = loginAs('admin@tenant-a.local', 'secret123', 'tenant-a');

    $response = $this->getJson('/api/v1/products', [
        'X-Tenant' => 'tenant-a',
        'Authorization' => "Bearer {$tokenA}",
    ]);

    $response->assertOk();
    $body = $response->getContent();
    expect($body)->toContain('Producto exclusivo de A');
    expect($body)->not->toContain('Producto exclusivo de B');
});

// ====================================================================
//  Variantes de ataque: header basura, sin header, slug inexistente
// ====================================================================

it('un token de A con X-Tenant inexistente es rechazado (no cae a A)', function () {
    $tokenA = loginAs('admin@tenant-a.local', 'secret123', 'tenant-a');

    $response = $this->getJson('/api/v1/auth/me', [
        'X-Tenant' => 'tenant-que-no-existe',
        'Authorization' => "Bearer {$tokenA}",
    ]);

    // El middleware NUNCA cae al fallback ante una pista inválida → 400.
    $response->assertStatus(400)->assertJsonPath('error.code', 'TENANT_NOT_RESOLVED');
});

it('una peticion sin token a ruta protegida devuelve 401 aunque el tenant resuelva', function () {
    $response = $this->getJson('/api/v1/auth/me', ['X-Tenant' => 'tenant-a']);

    $response->assertStatus(401);
});

it('un token revocado deja de funcionar incluso en su propio tenant', function () {
    $tokenA = loginAs('admin@tenant-a.local', 'secret123', 'tenant-a');

    // Logout revoca el token actual.
    $this->postJson('/api/v1/auth/logout', [], [
        'X-Tenant' => 'tenant-a',
        'Authorization' => "Bearer {$tokenA}",
    ])->assertOk();

    // En tests, el guard de Sanctum cachea el usuario resuelto dentro del mismo
    // proceso PHP. En producción cada request es un proceso fresco, así que el
    // token borrado no podría autenticar a nadie. Forzamos ese aislamiento
    // entre requests para que la aserción siguiente refleje el comportamiento real.
    $this->app['auth']->forgetGuards();

    // El mismo token ya no debe servir.
    $response = $this->getJson('/api/v1/auth/me', [
        'X-Tenant' => 'tenant-a',
        'Authorization' => "Bearer {$tokenA}",
    ]);
    $response->assertStatus(401);
});
