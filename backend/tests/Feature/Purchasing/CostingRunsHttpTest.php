<?php

declare(strict_types=1);

use App\Domain\Authorization\Permissions;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'crh-tenant']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function crhActor(array $permisos): User
{
    $u = User::factory()->create(['company_id' => test()->tenant->id]);
    $u->givePermissionTo($permisos);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Sanctum::actingAs($u);

    return $u;
}

function crhHeaders(): array
{
    return ['X-Tenant' => 'crh-tenant'];
}

function crhProduct(): Product
{
    $tax = Tax::factory()->rate(0.16)->create(['company_id' => test()->tenant->id]);
    $unit = Unit::factory()->create(['company_id' => test()->tenant->id]);

    return Product::factory()->withTax($tax)->create(['unit_id' => $unit->id]);
}

function crhPayload(Product $p): array
{
    return [
        'name' => 'viaje de prueba',
        'freight_total' => 40,
        'lines' => [[
            'product_uuid' => $p->uuid,
            'pack_description' => 'caja',
            'pack_price' => 100,
            'units_per_pack' => 10,
            'packs_qty' => 1,
            'waste_pct' => 0.2,
            'margin_pct' => 0.3,
        ]],
    ];
}

it('crea la corrida y devuelve computed en el 201', function (): void {
    crhActor([Permissions::COSTING_CREATE]);

    $resp = $this->postJson('/api/v1/costing-runs', crhPayload(crhProduct()), crhHeaders());
    TenantContext::set($this->tenant);

    // base 10 + flete 40/10 = 14; merma 0.2 -> 17.5; markup 0.3 -> 22.75
    $resp->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.lines.0.computed_unit_cost', 17.5)
        ->assertJsonPath('data.lines.0.computed_price', 22.75);
});

it('el cajero sin permiso recibe 403', function (): void {
    crhActor([]);

    $this->postJson('/api/v1/costing-runs', crhPayload(crhProduct()), crhHeaders())
        ->assertStatus(403);
});

it('el show devuelve la corrida con sus lineas', function (): void {
    crhActor([Permissions::COSTING_CREATE, Permissions::COSTING_VIEW]);
    $p = crhProduct();
    $resp = $this->postJson('/api/v1/costing-runs', crhPayload($p), crhHeaders());
    TenantContext::set($this->tenant);
    $uuid = $resp->json('data.uuid');

    $this->getJson('/api/v1/costing-runs/'.$uuid, crhHeaders())
        ->assertOk()
        ->assertJsonPath('data.lines.0.product_uuid', $p->uuid);
});

it('confirm escribe el costo y repetirlo devuelve 409 con su code', function (): void {
    crhActor([Permissions::COSTING_CREATE, Permissions::COSTING_CONFIRM]);
    $p = crhProduct();
    $resp = $this->postJson('/api/v1/costing-runs', crhPayload($p), crhHeaders());
    TenantContext::set($this->tenant);
    $uuid = $resp->json('data.uuid');

    $this->postJson('/api/v1/costing-runs/'.$uuid.'/confirm', [], crhHeaders())
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');
    TenantContext::set($this->tenant);
    expect((float) $p->refresh()->cost)->toBe(17.5);

    $this->postJson('/api/v1/costing-runs/'.$uuid.'/confirm', [], crhHeaders())
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COSTING_RUN_TRANSITION');
});

it('waste_pct de 1 rebota en el request con 422', function (): void {
    crhActor([Permissions::COSTING_CREATE]);
    $payload = crhPayload(crhProduct());
    $payload['lines'][0]['waste_pct'] = 1;

    $this->postJson('/api/v1/costing-runs', $payload, crhHeaders())
        ->assertStatus(422);
});
