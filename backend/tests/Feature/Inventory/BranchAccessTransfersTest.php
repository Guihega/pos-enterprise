<?php

declare(strict_types=1);

use App\Domain\Authorization\Permissions;
use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
 * Gate de pertenencia de sucursal en transferencias y solicitudes
 * (docs/DISENO_CROSS_BRANCH.md, sec. 4 y 5; decision D1 = a).
 *
 * Todos los usuarios operativos son GERENTE (tiene transfers.* y
 * transfer-requests.view/create/approve): cualquier 403 aqui es del gate
 * de sucursal, no de la matriz de permisos. ADMIN solo aparece para probar
 * el bypass transfers.cross-branch que hereda de Permissions::all().
 */

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'cb-tenant', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);

    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(CatalogProvisioner::class)->provision($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->unit = Unit::query()->where('code', 'PZA')->firstOrFail();

    $this->branchA = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->warehouseA = Warehouse::factory()->default()->ofBranch($this->branchA)->create();
    $this->branchB = Branch::factory()->create(['company_id' => $this->tenant->id, 'is_default' => false]);
    $this->warehouseB = Warehouse::factory()->default()->ofBranch($this->branchB)->create();
    $this->branchC = Branch::factory()->create(['company_id' => $this->tenant->id, 'is_default' => false]);
    $this->warehouseC = Warehouse::factory()->default()->ofBranch($this->branchC)->create();

    $this->product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);
    app(InventoryService::class)->recordEntry($this->product, $this->warehouseA, 100, 5);

    // ADMIN sin sucursales asignadas: solo opera por el bypass.
    $this->admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->admin->assignRole(Roles::ADMIN);

    $this->gerenteA = cbGerente([$this->branchA]);
    $this->gerenteB = cbGerente([$this->branchB]);
    $this->gerenteC = cbGerente([$this->branchC]);
});

function cbGerente(array $branches): User
{
    $user = User::factory()->create(['company_id' => test()->tenant->id]);
    $user->assignRole(Roles::GERENTE);
    $user->syncBranches($branches);

    return $user;
}

function cbH(): array
{
    return ['X-Tenant' => 'cb-tenant'];
}

function cbPayload(string $fromUuid, string $toUuid, float $qty = 10): array
{
    return [
        'from_branch_uuid' => $fromUuid,
        'to_branch_uuid' => $toUuid,
        'items' => [['product_uuid' => test()->product->uuid, 'quantity' => $qty, 'unit_cost' => 5]],
    ];
}

/** Transfer A->B en draft, creada por ADMIN (bypass), devuelve el uuid. */
function cbDraft(): string
{
    Sanctum::actingAs(test()->admin);
    $uuid = test()->postJson('/api/v1/transfers', cbPayload(test()->branchA->uuid, test()->branchB->uuid), cbH())
        ->assertCreated()->json('data.uuid');
    TenantContext::set(test()->tenant);

    return $uuid;
}

/** Transfer A->B ya enviada por ADMIN, devuelve el uuid. */
function cbSent(): string
{
    $uuid = cbDraft();
    Sanctum::actingAs(test()->admin);
    test()->postJson("/api/v1/transfers/{$uuid}/send", [], cbH())->assertOk();
    TenantContext::set(test()->tenant);

    return $uuid;
}

/** Solicitud A->B pending creada por ADMIN (bypass), devuelve el uuid. */
function cbRequest(): string
{
    Sanctum::actingAs(test()->admin);
    $uuid = test()->postJson('/api/v1/transfer-requests', [
        'from_branch_uuid' => test()->branchA->uuid,
        'to_branch_uuid' => test()->branchB->uuid,
        'items' => [['product_uuid' => test()->product->uuid, 'quantity' => 5]],
    ], cbH())->assertCreated()->json('data.uuid');
    TenantContext::set(test()->tenant);

    return $uuid;
}

// ====================================================================
//  Transferencias: origen para create/send/cancel, destino para receive
// ====================================================================

it('gerente de la sucursal origen crea la transferencia', function () {
    Sanctum::actingAs($this->gerenteA);
    $this->postJson('/api/v1/transfers', cbPayload($this->branchA->uuid, $this->branchB->uuid), cbH())
        ->assertCreated()->assertJsonPath('data.status', 'draft');
});

it('gerente ajeno al origen no crea la transferencia aunque sea del destino', function () {
    Sanctum::actingAs($this->gerenteB);
    $this->postJson('/api/v1/transfers', cbPayload($this->branchA->uuid, $this->branchB->uuid), cbH())
        ->assertForbidden();
});

it('send exige pertenecer a la sucursal origen', function () {
    $uuid = cbDraft();

    Sanctum::actingAs($this->gerenteB);
    $this->postJson("/api/v1/transfers/{$uuid}/send", [], cbH())->assertForbidden();
    TenantContext::set($this->tenant);

    Sanctum::actingAs($this->gerenteA);
    $this->postJson("/api/v1/transfers/{$uuid}/send", [], cbH())
        ->assertOk()->assertJsonPath('data.status', 'sent');
});

it('cancel exige pertenecer a la sucursal origen', function () {
    $uuid = cbDraft();

    Sanctum::actingAs($this->gerenteB);
    $this->postJson("/api/v1/transfers/{$uuid}/cancel", [], cbH())->assertForbidden();
    TenantContext::set($this->tenant);

    Sanctum::actingAs($this->gerenteA);
    $this->postJson("/api/v1/transfers/{$uuid}/cancel", [], cbH())
        ->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('receive exige pertenecer a la sucursal destino', function () {
    $uuid = cbSent();

    // Ni el origen ni una tercera sucursal reciben.
    Sanctum::actingAs($this->gerenteA);
    $this->postJson("/api/v1/transfers/{$uuid}/receive", [], cbH())->assertForbidden();
    TenantContext::set($this->tenant);

    Sanctum::actingAs($this->gerenteC);
    $this->postJson("/api/v1/transfers/{$uuid}/receive", [], cbH())->assertForbidden();
    TenantContext::set($this->tenant);

    Sanctum::actingAs($this->gerenteB);
    $this->postJson("/api/v1/transfers/{$uuid}/receive", [], cbH())
        ->assertOk()->assertJsonPath('data.status', 'received');
});

// ====================================================================
//  Solicitudes: destino para create, origen para approve/reject
// ====================================================================

it('crear solicitud exige pertenecer a la sucursal destino', function () {
    $payload = [
        'from_branch_uuid' => $this->branchA->uuid,
        'to_branch_uuid' => $this->branchB->uuid,
        'items' => [['product_uuid' => $this->product->uuid, 'quantity' => 5]],
    ];

    Sanctum::actingAs($this->gerenteA);
    $this->postJson('/api/v1/transfer-requests', $payload, cbH())->assertForbidden();
    TenantContext::set($this->tenant);

    Sanctum::actingAs($this->gerenteB);
    $this->postJson('/api/v1/transfer-requests', $payload, cbH())
        ->assertCreated()->assertJsonPath('data.status', 'pending');
});

it('aprobar y rechazar solicitud exigen pertenecer a la sucursal origen', function () {
    $uuid = cbRequest();

    Sanctum::actingAs($this->gerenteB);
    $this->postJson("/api/v1/transfer-requests/{$uuid}/approve", [], cbH())->assertForbidden();
    TenantContext::set($this->tenant);
    $this->postJson("/api/v1/transfer-requests/{$uuid}/reject", ['reason' => 'No'], cbH())->assertForbidden();
    TenantContext::set($this->tenant);

    Sanctum::actingAs($this->gerenteA);
    $this->postJson("/api/v1/transfer-requests/{$uuid}/reject", ['reason' => 'Sin stock'], cbH())
        ->assertOk()->assertJsonPath('data.rejection_reason', 'Sin stock');
    TenantContext::set($this->tenant);

    $otra = cbRequest();
    Sanctum::actingAs($this->gerenteA);
    $this->postJson("/api/v1/transfer-requests/{$otra}/approve", [], cbH())->assertOk();
});

// ====================================================================
//  Regional y bypass (D1 = a)
// ====================================================================

it('gerente con varias sucursales opera desde todas sin bypass (regional)', function () {
    $regional = cbGerente([$this->branchA, $this->branchB]);
    expect($regional->can(Permissions::TRANSFERS_CROSS_BRANCH))->toBeFalse();

    Sanctum::actingAs($regional);
    $this->postJson('/api/v1/transfers', cbPayload($this->branchA->uuid, $this->branchB->uuid), cbH())
        ->assertCreated();
    TenantContext::set($this->tenant);
    $this->postJson('/api/v1/transfers', cbPayload($this->branchB->uuid, $this->branchA->uuid), cbH())
        ->assertCreated();
    TenantContext::set($this->tenant);

    // Pero no desde una sucursal que no es suya.
    $this->postJson('/api/v1/transfers', cbPayload($this->branchC->uuid, $this->branchA->uuid), cbH())
        ->assertForbidden();
});

it('admin sin sucursales asignadas opera por el bypass transfers.cross-branch', function () {
    expect($this->admin->branches()->count())->toBe(0)
        ->and($this->admin->can(Permissions::TRANSFERS_CROSS_BRANCH))->toBeTrue()
        ->and($this->gerenteA->can(Permissions::TRANSFERS_CROSS_BRANCH))->toBeFalse();

    Sanctum::actingAs($this->admin);
    $this->postJson('/api/v1/transfers', cbPayload($this->branchC->uuid, $this->branchA->uuid), cbH())
        ->assertCreated();
});
