<?php

declare(strict_types=1);

use App\Domain\Authorization\Permissions;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'sup-tenant']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
});

function supActor(array $permisos): User
{
    $u = User::factory()->create(['company_id' => test()->tenant->id]);
    $u->givePermissionTo($permisos);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Sanctum::actingAs($u);

    return $u;
}

function supHeaders(): array
{
    return ['X-Tenant' => 'sup-tenant'];
}

it('crea un proveedor y responde 201 con el recurso', function (): void {
    supActor([Permissions::SUPPLIER_CREATE]);

    $resp = $this->postJson('/api/v1/suppliers', [
        'code' => 'PROV-100',
        'name' => 'Distribuidora del Norte',
        'email' => 'ventas@dnorte.test',
    ], supHeaders());

    $resp->assertCreated()
        ->assertJsonPath('data.code', 'PROV-100')
        ->assertJsonPath('data.is_active', true);

    TenantContext::set($this->tenant);
    expect(Supplier::where('code', 'PROV-100')->exists())->toBeTrue();
});

it('rechaza un code duplicado dentro del mismo tenant con 422', function (): void {
    supActor([Permissions::SUPPLIER_CREATE]);
    Supplier::factory()->create(['code' => 'PROV-DUP']);

    $this->postJson('/api/v1/suppliers', [
        'code' => 'PROV-DUP',
        'name' => 'Otro',
    ], supHeaders())->assertStatus(422);
});

it('exige code y name al crear', function (): void {
    supActor([Permissions::SUPPLIER_CREATE]);

    $this->postJson('/api/v1/suppliers', [], supHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code', 'name']);
});

it('lista proveedores y filtra por active', function (): void {
    supActor([Permissions::SUPPLIER_VIEW]);
    Supplier::factory()->create(['code' => 'PROV-A', 'name' => 'Alfa']);
    Supplier::factory()->inactive()->create(['code' => 'PROV-B', 'name' => 'Beta']);

    $this->getJson('/api/v1/suppliers', supHeaders())
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson('/api/v1/suppliers?active=1', supHeaders())
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'PROV-A');
});

it('actualiza un proveedor por uuid con PATCH', function (): void {
    supActor([Permissions::SUPPLIER_UPDATE]);
    $s = Supplier::factory()->create(['code' => 'PROV-U', 'name' => 'Viejo']);

    $this->patchJson('/api/v1/suppliers/'.$s->uuid, [
        'name' => 'Nuevo',
    ], supHeaders())->assertOk()->assertJsonPath('data.name', 'Nuevo');

    TenantContext::set($this->tenant);
    expect($s->fresh()->name)->toBe('Nuevo');
});

it('desactiva un proveedor sin borrarlo', function (): void {
    supActor([Permissions::SUPPLIER_UPDATE]);
    $s = Supplier::factory()->create(['code' => 'PROV-D']);

    $this->postJson('/api/v1/suppliers/'.$s->uuid.'/deactivate', [], supHeaders())
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    TenantContext::set($this->tenant);
    expect($s->fresh()->is_active)->toBeFalse();
    expect(Supplier::withTrashed()->where('code', 'PROV-D')->exists())->toBeTrue();
});

it('niega con 403 al usuario sin permisos de proveedor', function (): void {
    supActor([Permissions::PRODUCT_VIEW]);
    $s = Supplier::factory()->create(['code' => 'PROV-X']);

    $this->getJson('/api/v1/suppliers', supHeaders())->assertForbidden();
    $this->postJson('/api/v1/suppliers', ['code' => 'PROV-Y', 'name' => 'N'], supHeaders())
        ->assertForbidden();
    $this->postJson('/api/v1/suppliers/'.$s->uuid.'/deactivate', [], supHeaders())
        ->assertForbidden();
});

it('no expone proveedores de otro tenant', function (): void {
    $otro = Company::factory()->create(['slug' => 'sup-otro']);
    TenantContext::runAs($otro, function (): void {
        Supplier::factory()->create(['code' => 'PROV-OTRO', 'name' => 'Ajeno']);
    });

    TenantContext::set($this->tenant);
    supActor([Permissions::SUPPLIER_VIEW]);

    $this->getJson('/api/v1/suppliers', supHeaders())
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
