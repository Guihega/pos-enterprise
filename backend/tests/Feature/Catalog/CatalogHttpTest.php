<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Tests HTTP de catálogo auxiliar (Bloque 1.4d)
|--------------------------------------------------------------------------
|
| Cubre los 4 endpoints REST: categories, brands, units, taxes.
|
*/

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'mi-tenant', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);

    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(CatalogProvisioner::class)->provision($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->admin->assignRole(Roles::ADMIN);

    $this->cashier = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cashier->assignRole(Roles::CAJERO);
});

// ====================================================================
//  CATEGORIES
// ====================================================================

it('GET /categories devuelve listado paginado al admin', function () {
    Category::factory()->count(3)->create(['company_id' => $this->tenant->id]);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/categories', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()->assertJsonStructure([
        'data' => [['uuid', 'name', 'slug', 'is_active']],
        'meta',
    ]);
    expect($response->json('meta.total'))->toBe(3);
});

it('POST /categories crea con slug único per tenant', function () {
    Sanctum::actingAs($this->admin);

    $response = $this->postJson(
        '/api/v1/categories',
        ['name' => 'Bebidas', 'slug' => 'bebidas'],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertCreated()->assertJsonPath('data.slug', 'bebidas');
});

it('POST /categories rechaza slug duplicado en mismo tenant', function () {
    Category::factory()->create([
        'company_id' => $this->tenant->id,
        'slug' => 'bebidas',
    ]);

    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        '/api/v1/categories',
        ['name' => 'Otra', 'slug' => 'bebidas'],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(422)->assertJsonValidationErrors(['slug']);
});

it('PATCH /categories/{uuid} actualiza la jerarquía', function () {
    $padre = Category::factory()->create([
        'company_id' => $this->tenant->id,
        'name' => 'Bebidas', 'slug' => 'bebidas',
    ]);
    $hijo = Category::factory()->create([
        'company_id' => $this->tenant->id,
        'name' => 'Refrescos', 'slug' => 'refrescos',
    ]);

    Sanctum::actingAs($this->admin);
    $response = $this->patchJson(
        "/api/v1/categories/{$hijo->uuid}",
        ['parent_uuid' => $padre->uuid],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertOk();
    expect($hijo->fresh()->parent_id)->toBe($padre->id);
});

it('PATCH /categories/{uuid} rechaza ciclo (parent = self)', function () {
    $cat = Category::factory()->create(['company_id' => $this->tenant->id]);

    Sanctum::actingAs($this->admin);
    $response = $this->patchJson(
        "/api/v1/categories/{$cat->uuid}",
        ['parent_uuid' => $cat->uuid],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(422)->assertJsonValidationErrors(['parent_uuid']);
});

it('DELETE /categories/{uuid} hace soft delete', function () {
    $cat = Category::factory()->create(['company_id' => $this->tenant->id]);

    Sanctum::actingAs($this->admin);
    $response = $this->deleteJson(
        "/api/v1/categories/{$cat->uuid}",
        [],
        ['X-Tenant' => 'mi-tenant']
    );
    $response->assertNoContent();

    TenantContext::set($this->tenant);
    expect(Category::query()->find($cat->id))->toBeNull();
    expect(Category::query()->withTrashed()->find($cat->id))->not->toBeNull();
});

// ====================================================================
//  BRANDS
// ====================================================================

it('GET /brands devuelve listado paginado', function () {
    Brand::factory()->count(2)->create(['company_id' => $this->tenant->id]);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/brands', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(2);
});

it('POST /brands crea una marca', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        '/api/v1/brands',
        ['name' => 'Coca-Cola Co.', 'slug' => 'coca-cola'],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertCreated()->assertJsonPath('data.slug', 'coca-cola');
});

it('PATCH /brands/{uuid} actualiza nombre', function () {
    $brand = Brand::factory()->create([
        'company_id' => $this->tenant->id,
        'name' => 'Vieja', 'slug' => 'vieja',
    ]);

    Sanctum::actingAs($this->admin);
    $response = $this->patchJson(
        "/api/v1/brands/{$brand->uuid}",
        ['name' => 'Nueva'],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertOk()->assertJsonPath('data.name', 'Nueva');
});

it('GET /brands con cajero devuelve 200', function () {
    Brand::factory()->create(['company_id' => $this->tenant->id]);
    Sanctum::actingAs($this->cashier);
    $this->getJson('/api/v1/brands', ['X-Tenant' => 'mi-tenant'])->assertOk();
});

it('POST /brands con cajero devuelve 403', function () {
    Sanctum::actingAs($this->cashier);
    $response = $this->postJson(
        '/api/v1/brands',
        ['name' => 'X', 'slug' => 'x'],
        ['X-Tenant' => 'mi-tenant']
    );
    $response->assertStatus(403);
});

// ====================================================================
//  UNITS
// ====================================================================

it('GET /units lista las 9 unidades default sembradas', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/units', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(9);
});

it('GET /units?category=weight filtra por categoría', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/units?category=weight', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk();
    // KG y G del seed
    expect($response->json('meta.total'))->toBe(2);
});

it('POST /units crea una unidad nueva', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->postJson(
        '/api/v1/units',
        [
            'code' => 'TON',
            'name' => 'Tonelada',
            'plural_name' => 'Toneladas',
            'symbol' => 't',
            'category' => 'weight',
            'factor' => 1000000,
            'is_decimal' => true,
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertCreated()->assertJsonPath('data.code', 'TON');
});

it('DELETE /units/{uuid} en uso devuelve 409', function () {
    $unit = Unit::query()->where('code', 'PZA')->firstOrFail();
    // Crear producto que use esta unidad
    Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $unit->id,
    ]);

    Sanctum::actingAs($this->admin);
    $response = $this->deleteJson(
        "/api/v1/units/{$unit->uuid}",
        [],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'UNIT_IN_USE');
});

it('DELETE /units/{uuid} sin uso responde 204', function () {
    $unit = Unit::factory()->create([
        'company_id' => $this->tenant->id,
        'code' => 'CUSTOM-X',
    ]);

    Sanctum::actingAs($this->admin);
    $response = $this->deleteJson(
        "/api/v1/units/{$unit->uuid}",
        [],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertNoContent();
});

// ====================================================================
//  TAXES
// ====================================================================

it('GET /taxes lista los 4 taxes de México sembrados, default primero', function () {
    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/taxes', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(4);
    expect($response->json('data.0.code'))->toBe('IVA-16');  // default va primero
    expect($response->json('data.0.is_default'))->toBeTrue();
});

it('POST /taxes crea uno nuevo y respeta is_default exclusivo', function () {
    Sanctum::actingAs($this->admin);

    // Crear como default → el IVA-16 que era default debe dejar de serlo
    $response = $this->postJson(
        '/api/v1/taxes',
        [
            'code' => 'IEPS-8',
            'name' => 'IEPS botanas 8%',
            'rate' => 0.08,
            'type' => 'excise',
            'is_inclusive' => true,
            'is_default' => true,
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertCreated();

    // Verificar exclusividad
    TenantContext::set($this->tenant);
    expect(Tax::query()->where('is_default', true)->count())->toBe(1);
    expect(Tax::query()->where('code', 'IEPS-8')->first()->is_default)->toBeTrue();
});

it('DELETE /taxes/{uuid} del default devuelve 409', function () {
    Sanctum::actingAs($this->admin);

    $defaultTax = Tax::query()->where('is_default', true)->firstOrFail();
    $response = $this->deleteJson(
        "/api/v1/taxes/{$defaultTax->uuid}",
        [],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'TAX_IS_DEFAULT');
});

it('DELETE /taxes/{uuid} no-default soft-borra', function () {
    $nonDefault = Tax::query()->where('code', 'IVA-8')->firstOrFail();

    Sanctum::actingAs($this->admin);
    $response = $this->deleteJson(
        "/api/v1/taxes/{$nonDefault->uuid}",
        [],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertNoContent();
});

// ====================================================================
//  Aislamiento general
// ====================================================================

it('Aislamiento: GET /categories de tenant A no muestra categorías de B', function () {
    Category::factory()->count(2)->create(['company_id' => $this->tenant->id]);

    $tenantB = Company::factory()->create();
    TenantContext::set($tenantB);
    Category::factory()->count(5)->create(['company_id' => $tenantB->id]);

    TenantContext::set($this->tenant);
    Sanctum::actingAs($this->admin);

    $response = $this->getJson('/api/v1/categories', ['X-Tenant' => 'mi-tenant']);
    expect($response->json('meta.total'))->toBe(2);
});
