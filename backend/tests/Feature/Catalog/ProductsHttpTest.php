<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
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
| Tests HTTP de products (Bloque 1.4c)
|--------------------------------------------------------------------------
|
| Cobertura: 200 / 201 / 204 / 401 / 403 / 404 / 422
| Cubre: CRUD, búsqueda, filtros, paginación, aislamiento cross-tenant,
| validación de FKs cross-tenant.
|
*/

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'mi-tenant', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);

    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(CatalogProvisioner::class)->provision($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->unit = Unit::query()->where('code', 'PZA')->firstOrFail();
    $this->tax = Tax::query()->where('is_default', true)->firstOrFail();

    // Usuarios típicos para los escenarios de autorización
    $this->admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->admin->assignRole(Roles::ADMIN);

    $this->cashier = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cashier->assignRole(Roles::CAJERO);

    $this->auditor = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->auditor->assignRole(Roles::AUDITOR);
});

// ====================================================================
//  GET /products (index)
// ====================================================================

it('GET /products sin token devuelve 401', function () {
    $response = $this->getJson('/api/v1/products', ['X-Tenant' => 'mi-tenant']);
    $response->assertStatus(401);
});

it('GET /products con admin devuelve 200 con paginación', function () {
    Product::factory()->count(5)->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);

    Sanctum::actingAs($this->admin);

    $response = $this->getJson('/api/v1/products', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [['uuid', 'sku', 'name', 'pricing', 'flags', 'status']],
            'meta' => ['current_page', 'last_page', 'total'],
        ]);

    expect($response->json('meta.total'))->toBe(5);
});

it('GET /products con cajero devuelve 200 (cajero tiene PRODUCT_VIEW)', function () {
    Product::factory()->count(3)->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);

    Sanctum::actingAs($this->cashier);

    $response = $this->getJson('/api/v1/products', ['X-Tenant' => 'mi-tenant']);
    $response->assertOk();
    expect($response->json('meta.total'))->toBe(3);
});

it('GET /products busca por nombre con ?q', function () {
    Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'name' => 'Coca Cola 600ml',
    ]);
    Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'name' => 'Pepsi 600ml',
    ]);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/products?q=coca', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);
});

it('GET /products filtra por status=draft', function () {
    Product::factory()->active()->create(['company_id' => $this->tenant->id, 'unit_id' => $this->unit->id]);
    Product::factory()->draft()->create(['company_id' => $this->tenant->id, 'unit_id' => $this->unit->id]);
    Product::factory()->draft()->create(['company_id' => $this->tenant->id, 'unit_id' => $this->unit->id]);

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/v1/products?status=draft', ['X-Tenant' => 'mi-tenant']);

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(2);
});

it('GET /products aísla productos de otros tenants', function () {
    // Productos en tenant actual
    Product::factory()->count(2)->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);

    // Productos en OTRO tenant
    $otherTenant = Company::factory()->create(['slug' => 'otro-tenant']);
    app(CatalogProvisioner::class)->provision($otherTenant);
    TenantContext::set($otherTenant);
    $otherUnit = Unit::query()->where('code', 'PZA')->firstOrFail();
    Product::factory()->count(3)->create([
        'company_id' => $otherTenant->id,
        'unit_id' => $otherUnit->id,
    ]);

    // Volver al contexto original
    TenantContext::set($this->tenant);
    Sanctum::actingAs($this->admin);

    $response = $this->getJson('/api/v1/products', ['X-Tenant' => 'mi-tenant']);
    expect($response->json('meta.total'))->toBe(2);  // solo los del tenant actual
});

// ====================================================================
//  GET /products/{uuid} (show)
// ====================================================================

it('GET /products/{uuid} devuelve el producto con relaciones cargadas', function () {
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'tax_id' => $this->tax->id,
    ]);

    Sanctum::actingAs($this->admin);

    $response = $this->getJson("/api/v1/products/{$product->uuid}", ['X-Tenant' => 'mi-tenant']);

    $response->assertOk()
        ->assertJsonPath('data.uuid', $product->uuid)
        ->assertJsonStructure(['data' => ['unit' => ['code'], 'tax' => ['code']]]);
});

it('GET /products/{uuid} de otro tenant devuelve 404', function () {
    $otherTenant = Company::factory()->create();
    app(CatalogProvisioner::class)->provision($otherTenant);
    TenantContext::set($otherTenant);
    $otherUnit = Unit::query()->where('code', 'PZA')->firstOrFail();
    $otherProduct = Product::factory()->create([
        'company_id' => $otherTenant->id,
        'unit_id' => $otherUnit->id,
    ]);

    TenantContext::set($this->tenant);
    Sanctum::actingAs($this->admin);

    $response = $this->getJson(
        "/api/v1/products/{$otherProduct->uuid}",
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(404);
});

// ====================================================================
//  POST /products (store)
// ====================================================================

it('POST /products con admin crea producto y devuelve 201', function () {
    Sanctum::actingAs($this->admin);

    $response = $this->postJson(
        '/api/v1/products',
        [
            'sku' => 'NEW-001',
            'name' => 'Nuevo Producto',
            'unit_uuid' => $this->unit->uuid,
            'tax_uuid' => $this->tax->uuid,
            'price' => 99.99,
            'cost' => 50.00,
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertCreated()
        ->assertJsonPath('data.sku', 'NEW-001')
        ->assertJsonPath('data.name', 'Nuevo Producto')
        ->assertJsonPath('data.pricing.price', 99.99);

    $this->assertDatabaseHas('products', [
        'sku' => 'NEW-001',
        'company_id' => $this->tenant->id,
    ]);
});

it('POST /products sin cost no queda null (default 0 en BD)', function () {
    Sanctum::actingAs($this->admin);

    $response = $this->postJson(
        '/api/v1/products',
        [
            'sku' => 'NO-COST-001',
            'name' => 'Producto sin costo',
            'unit_uuid' => $this->unit->uuid,
            'price' => 10,
            // 'cost' deliberadamente ausente.
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertCreated();

    $this->assertDatabaseHas('products', [
        'sku' => 'NO-COST-001',
        'company_id' => $this->tenant->id,
        'cost' => 0,
    ]);
});

it('POST /products con cost null no queda null (default 0 en BD)', function () {
    Sanctum::actingAs($this->admin);

    $response = $this->postJson(
        '/api/v1/products',
        [
            'sku' => 'NULL-COST-001',
            'name' => 'Producto cost null',
            'unit_uuid' => $this->unit->uuid,
            'price' => 10,
            'cost' => null,
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertCreated();

    $this->assertDatabaseHas('products', [
        'sku' => 'NULL-COST-001',
        'company_id' => $this->tenant->id,
        'cost' => 0,
    ]);
});

it('POST /products con cajero devuelve 403 (no tiene PRODUCT_CREATE)', function () {
    Sanctum::actingAs($this->cashier);

    $response = $this->postJson(
        '/api/v1/products',
        [
            'sku' => 'X',
            'name' => 'X',
            'unit_uuid' => $this->unit->uuid,
            'price' => 1,
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(403);
});

it('POST /products sin SKU devuelve 422', function () {
    Sanctum::actingAs($this->admin);

    $response = $this->postJson(
        '/api/v1/products',
        [
            'name' => 'Sin SKU',
            'unit_uuid' => $this->unit->uuid,
            'price' => 10,
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['sku']);
});

it('POST /products con SKU duplicado en mismo tenant devuelve 422', function () {
    Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'sku' => 'DUP-001',
    ]);

    Sanctum::actingAs($this->admin);

    $response = $this->postJson(
        '/api/v1/products',
        [
            'sku' => 'DUP-001',
            'name' => 'Otro',
            'unit_uuid' => $this->unit->uuid,
            'price' => 10,
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['sku']);
});

it('POST /products con unit de OTRO tenant devuelve 422', function () {
    // Unit de otro tenant
    $otherTenant = Company::factory()->create();
    app(CatalogProvisioner::class)->provision($otherTenant);
    TenantContext::set($otherTenant);
    $otherUnit = Unit::query()->where('code', 'PZA')->firstOrFail();

    TenantContext::set($this->tenant);
    Sanctum::actingAs($this->admin);

    $response = $this->postJson(
        '/api/v1/products',
        [
            'sku' => 'CROSS-001',
            'name' => 'Producto cross',
            'unit_uuid' => $otherUnit->uuid,  // ← unit de otro tenant
            'price' => 10,
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['unit_uuid']);
});

it('POST /products con price negativo devuelve 422', function () {
    Sanctum::actingAs($this->admin);

    $response = $this->postJson(
        '/api/v1/products',
        [
            'sku' => 'NEG-001',
            'name' => 'Negativo',
            'unit_uuid' => $this->unit->uuid,
            'price' => -10,
        ],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['price']);
});

// ====================================================================
//  PATCH /products/{uuid} (update)
// ====================================================================

it('PATCH /products/{uuid} actualiza el nombre y precio', function () {
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'name' => 'Original',
        'price' => 100,
    ]);

    Sanctum::actingAs($this->admin);

    $response = $this->patchJson(
        "/api/v1/products/{$product->uuid}",
        ['name' => 'Actualizado', 'price' => 150],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertOk()
        ->assertJsonPath('data.name', 'Actualizado')
        ->assertJsonPath('data.pricing.price', 150);  // JSON encoder emite 150.0 como 150

    expect($product->fresh()->name)->toBe('Actualizado');
    expect((float) $product->fresh()->price)->toBe(150.0);
});

it('PATCH /products/{uuid} con auditor devuelve 403 (sin PRODUCT_UPDATE)', function () {
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);

    Sanctum::actingAs($this->auditor);

    $response = $this->patchJson(
        "/api/v1/products/{$product->uuid}",
        ['name' => 'X'],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(403);
});

// ====================================================================
//  DELETE /products/{uuid} (destroy)
// ====================================================================

it('DELETE /products/{uuid} responde 204 y soft-borra', function () {
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);

    Sanctum::actingAs($this->admin);

    $response = $this->deleteJson(
        "/api/v1/products/{$product->uuid}",
        [],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertNoContent();

    // Tras el HTTP request, el middleware terminate() llamó TenantContext::forget().
    // Re-establecemos el contexto para verificar el estado de BD.
    TenantContext::set($this->tenant);

    expect(Product::query()->find($product->id))->toBeNull();
    expect(Product::query()->withTrashed()->find($product->id))->not->toBeNull();
});

it('DELETE /products/{uuid} con cajero devuelve 403', function () {
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);

    Sanctum::actingAs($this->cashier);

    $response = $this->deleteJson(
        "/api/v1/products/{$product->uuid}",
        [],
        ['X-Tenant' => 'mi-tenant']
    );

    $response->assertStatus(403);
});
