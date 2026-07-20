<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Services\SyncSnapshotService;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| POST/GET /sync/snapshot/{entity} (maestro sec. 38.6)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'snap-test', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->default()->create([
        'company_id' => $this->tenant->id,
        'code' => 'CTR',
    ]);

    $this->cajero = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cajero->assignRole(Roles::CAJERO);
});

function snapTestProducts(int $count)
{
    $unit = Unit::factory()->create(['company_id' => TenantContext::id(), 'code' => 'PZA-SNAP']);
    $tax = Tax::factory()->create(['company_id' => TenantContext::id()]);

    return Product::factory()->count($count)->create([
        'company_id' => TenantContext::id(),
        'unit_id' => $unit->id,
        'tax_id' => $tax->id,
    ]);
}

function snapTestHeaders(): array
{
    return ['X-Tenant' => 'snap-test'];
}

it('manifest devuelve total y cursor inicial', function (): void {
    snapTestProducts(3);
    Sanctum::actingAs($this->cajero);

    $this->postJson('/api/v1/sync/snapshot/products', [], snapTestHeaders())
        ->assertOk()
        ->assertJson([
            'entity' => 'products',
            'total' => 3,
            'per_page' => SyncSnapshotService::PER_PAGE,
            'next_cursor' => '0',
        ]);
});

it('manifest con catalogo vacio devuelve cursor null', function (): void {
    Sanctum::actingAs($this->cajero);

    $this->postJson('/api/v1/sync/snapshot/customers', [], snapTestHeaders())
        ->assertOk()
        ->assertJson(['entity' => 'customers', 'total' => 0, 'next_cursor' => null]);
});

it('page entrega los registros con shape REST y agota cursor', function (): void {
    $products = snapTestProducts(3);
    Sanctum::actingAs($this->cajero);

    $response = $this->getJson('/api/v1/sync/snapshot/products', snapTestHeaders())
        ->assertOk()
        ->assertJsonPath('entity', 'products')
        ->assertJsonPath('next_cursor', null)
        ->assertJsonCount(3, 'data');

    TenantContext::set($this->tenant);
    $uuids = collect($response->json('data'))->pluck('uuid')->sort()->values()->all();
    expect($uuids)->toEqualCanonicalizing($products->pluck('uuid')->all());
});

it('page respeta cursor keyset y pagina en orden', function (): void {
    Customer::factory()->count(3)->create(['company_id' => TenantContext::id()]);
    Sanctum::actingAs($this->cajero);

    $primera = $this->getJson('/api/v1/sync/snapshot/customers', snapTestHeaders())
        ->assertOk();
    $todos = collect($primera->json('data'));
    expect($todos)->toHaveCount(3);

    // Cursor a mitad: pedir despues del primer id devuelto.
    TenantContext::set($this->tenant);
    $primerId = Customer::query()->orderBy('id')->first()->id;

    $segunda = $this->getJson('/api/v1/sync/snapshot/customers?cursor='.$primerId, snapTestHeaders())
        ->assertOk()
        ->assertJsonPath('next_cursor', null)
        ->assertJsonCount(2, 'data');
});

it('entidad no soportada devuelve 404', function (): void {
    Sanctum::actingAs($this->cajero);

    $this->getJson('/api/v1/sync/snapshot/sales', snapTestHeaders())->assertNotFound();
});

it('sin autenticacion devuelve 401', function (): void {
    $this->getJson('/api/v1/sync/snapshot/products', snapTestHeaders())->assertStatus(401);
});
