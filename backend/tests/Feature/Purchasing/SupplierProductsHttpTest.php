<?php

declare(strict_types=1);

use App\Domain\Authorization\Permissions;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Identity\Models\User;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'sp-tenant']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->supplier = Supplier::factory()->create(['code' => 'PROV-SP']);
});

function spActor(array $permisos): User
{
    $u = User::factory()->create(['company_id' => test()->tenant->id]);
    $u->givePermissionTo($permisos);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Sanctum::actingAs($u);

    return $u;
}

function spHeaders(): array
{
    return ['X-Tenant' => 'sp-tenant'];
}

function spProduct(): Product
{
    $tax = Tax::factory()->rate(0.16)->create(['company_id' => test()->tenant->id]);
    $unit = Unit::factory()->create(['company_id' => test()->tenant->id]);

    return Product::factory()->withTax($tax)->create(['unit_id' => $unit->id]);
}

/** OC en draft via HTTP (draft cuenta como relacion comercial). */
function spOrden(Product $product, ?Supplier $supplier = null): PurchaseOrder
{
    $resp = test()->postJson('/api/v1/purchase-orders', [
        'supplier_uuid' => ($supplier ?? test()->supplier)->uuid,
        'branch_uuid' => test()->branch->uuid,
        'items' => [['product_uuid' => $product->uuid, 'quantity' => 5, 'unit_cost' => 10]],
    ], spHeaders());
    TenantContext::set(test()->tenant);

    return PurchaseOrder::query()->where('uuid', $resp->json('data.uuid'))->firstOrFail();
}

it('lista los productos del proveedor sin duplicar los repetidos en varias OCs', function (): void {
    spActor([Permissions::SUPPLIER_VIEW, Permissions::PURCHASE_ORDER_CREATE]);
    $a = spProduct();
    $b = spProduct();
    spOrden($a);
    spOrden($a);
    spOrden($b);

    $resp = test()->getJson('/api/v1/suppliers/'.test()->supplier->uuid.'/products', spHeaders());
    TenantContext::set(test()->tenant);

    $resp->assertOk();
    expect($resp->json('data'))->toHaveCount(2);
    expect(collect($resp->json('data'))->pluck('uuid')->sort()->values()->all())
        ->toBe(collect([$a->uuid, $b->uuid])->sort()->values()->all());
});

it('excluye los productos que solo aparecen en OCs canceladas', function (): void {
    spActor([Permissions::SUPPLIER_VIEW, Permissions::PURCHASE_ORDER_CREATE]);
    $vivo = spProduct();
    $cancelado = spProduct();
    spOrden($vivo);
    $oc = spOrden($cancelado);
    $oc->update(['status' => PurchaseOrder::STATUS_CANCELLED]);

    $resp = test()->getJson('/api/v1/suppliers/'.test()->supplier->uuid.'/products', spHeaders());
    TenantContext::set(test()->tenant);

    expect($resp->json('data'))->toHaveCount(1);
    expect($resp->json('data.0.uuid'))->toBe($vivo->uuid);
});

it('no lista productos comprados a otro proveedor', function (): void {
    spActor([Permissions::SUPPLIER_VIEW, Permissions::PURCHASE_ORDER_CREATE]);
    $otro = Supplier::factory()->create(['code' => 'PROV-SP2']);
    $ajeno = spProduct();
    spOrden($ajeno, $otro);

    $resp = test()->getJson('/api/v1/suppliers/'.test()->supplier->uuid.'/products', spHeaders());
    TenantContext::set(test()->tenant);

    $resp->assertOk();
    expect($resp->json('data'))->toHaveCount(0);
});

it('devuelve 403 sin el permiso supplier.view', function (): void {
    spActor([Permissions::PURCHASE_ORDER_CREATE]);

    $resp = test()->getJson('/api/v1/suppliers/'.test()->supplier->uuid.'/products', spHeaders());
    TenantContext::set(test()->tenant);

    $resp->assertForbidden();
});

it('devuelve 404 con un uuid de proveedor inexistente', function (): void {
    spActor([Permissions::SUPPLIER_VIEW]);

    $resp = test()->getJson('/api/v1/suppliers/'.Str::uuid().'/products', spHeaders());
    TenantContext::set(test()->tenant);

    $resp->assertNotFound();
});

it('respeta per_page en la paginacion', function (): void {
    spActor([Permissions::SUPPLIER_VIEW, Permissions::PURCHASE_ORDER_CREATE]);
    spOrden(spProduct());
    spOrden(spProduct());
    spOrden(spProduct());

    $resp = test()->getJson('/api/v1/suppliers/'.test()->supplier->uuid.'/products'.'?per_page=2', spHeaders());
    TenantContext::set(test()->tenant);

    expect($resp->json('data'))->toHaveCount(2);
    expect($resp->json('meta.total'))->toBe(3);
});
