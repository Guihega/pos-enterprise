<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'changes-test', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->cashier = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cashier->assignRole(Roles::CAJERO);

    Sanctum::actingAs($this->cashier);
});

function makeProduct(): Product
{
    $unit = Unit::factory()->create(['company_id' => TenantContext::id()]);
    $tax  = Tax::factory()->create(['company_id' => TenantContext::id()]);

    return Product::factory()->create([
        'company_id' => TenantContext::id(),
        'unit_id'    => $unit->id,
        'tax_id'     => $tax->id,
        'status'     => Product::STATUS_ACTIVE,
    ]);
}

test('requiere autenticacion', function () {
    $this->app['auth']->forgetGuards();
    $this->withHeaders(['X-Tenant' => 'changes-test'])
        ->getJson('/api/v1/sync/changes?entities=products')
        ->assertStatus(401);
});

test('falla 422 si falta entities', function () {
    $this->withHeaders(['X-Tenant' => 'changes-test'])
        ->getJson('/api/v1/sync/changes')
        ->assertStatus(422);
});

test('falla 422 si since no es fecha valida', function () {
    $this->withHeaders(['X-Tenant' => 'changes-test'])
        ->getJson('/api/v1/sync/changes?entities=products&since=no-es-fecha')
        ->assertStatus(422);
});

test('sin since devuelve snapshot completo (todo created)', function () {
    makeProduct();
    makeProduct();

    $this->withHeaders(['X-Tenant' => 'changes-test'])
        ->getJson('/api/v1/sync/changes?entities=products')
        ->assertStatus(200)
        ->assertJsonCount(2, 'data.products.created')
        ->assertJsonCount(0, 'data.products.updated')
        ->assertJsonCount(0, 'data.products.deleted')
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonPath('meta.next_cursor', null);
});

test('con since solo devuelve cambios posteriores', function () {
    // Producto viejo, anterior al corte
    $old = makeProduct();
    $old->created_at = Carbon::parse('2026-01-01T00:00:00Z');
    $old->updated_at = Carbon::parse('2026-01-01T00:00:00Z');
    $old->saveQuietly();

    $since = Carbon::parse('2026-06-01T00:00:00Z');

    // Producto nuevo, posterior al corte
    $new = makeProduct();
    $new->created_at = Carbon::parse('2026-06-15T00:00:00Z');
    $new->updated_at = Carbon::parse('2026-06-15T00:00:00Z');
    $new->saveQuietly();

    $resp = $this->withHeaders(['X-Tenant' => 'changes-test'])
        ->getJson('/api/v1/sync/changes?entities=products&since=' . urlencode($since->toIso8601ZuluString()))
        ->assertStatus(200);

    $created = $resp->json('data.products.created');
    expect(collect($created)->pluck('uuid'))->toContain($new->uuid)
        ->not->toContain($old->uuid);
});

test('detecta updated vs created segun timestamps', function () {
    $since = Carbon::parse('2026-06-01T00:00:00Z');

    // Creado antes, actualizado despues => updated
    $p = makeProduct();
    $p->created_at = Carbon::parse('2026-01-01T00:00:00Z');
    $p->updated_at = Carbon::parse('2026-06-10T00:00:00Z');
    $p->saveQuietly();

    $resp = $this->withHeaders(['X-Tenant' => 'changes-test'])
        ->getJson('/api/v1/sync/changes?entities=products&since=' . urlencode($since->toIso8601ZuluString()))
        ->assertStatus(200);

    expect(collect($resp->json('data.products.updated'))->pluck('uuid'))->toContain($p->uuid);
    expect(collect($resp->json('data.products.created'))->pluck('uuid'))->not->toContain($p->uuid);
});

test('detecta deleted via soft-delete', function () {
    $since = Carbon::parse('2026-06-01T00:00:00Z');

    $p = makeProduct();
    $p->created_at = Carbon::parse('2026-01-01T00:00:00Z');
    $p->updated_at = Carbon::parse('2026-01-01T00:00:00Z');
    $p->saveQuietly();
    // Borrado despues del corte
    Carbon::setTestNow(Carbon::parse('2026-06-10T00:00:00Z'));
    $p->delete();
    Carbon::setTestNow();

    $resp = $this->withHeaders(['X-Tenant' => 'changes-test'])
        ->getJson('/api/v1/sync/changes?entities=products&since=' . urlencode($since->toIso8601ZuluString()))
        ->assertStatus(200);

    expect(collect($resp->json('data.products.deleted'))->pluck('uuid'))->toContain($p->uuid);
});

test('soporta multiples entidades en una peticion', function () {
    makeProduct();
    Customer::factory()->create(['company_id' => TenantContext::id()]);

    $this->withHeaders(['X-Tenant' => 'changes-test'])
        ->getJson('/api/v1/sync/changes?entities=products,customers')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.products.created')
        ->assertJsonCount(1, 'data.customers.created');
});

test('ignora entidades no soportadas sin fallar', function () {
    makeProduct();

    $this->withHeaders(['X-Tenant' => 'changes-test'])
        ->getJson('/api/v1/sync/changes?entities=products,promotions,inventory_lots')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.products.created')
        ->assertJsonMissingPath('data.promotions');
});
