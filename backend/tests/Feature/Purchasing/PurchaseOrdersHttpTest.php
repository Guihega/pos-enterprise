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
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'po-tenant']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->supplier = Supplier::factory()->create(['code' => 'PROV-PO']);
});

function poActor(array $permisos): User
{
    $u = User::factory()->create(['company_id' => test()->tenant->id]);
    $u->givePermissionTo($permisos);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Sanctum::actingAs($u);

    return $u;
}

function poHeaders(): array
{
    return ['X-Tenant' => 'po-tenant'];
}

function poProduct(float $rate = 0.16): Product
{
    $tax = Tax::factory()->rate($rate)->create(['company_id' => test()->tenant->id]);

    $unit = Unit::factory()->create(['company_id' => test()->tenant->id]);

    return Product::factory()->withTax($tax)->create(['unit_id' => $unit->id]);
}

function poPayload(Product $product, float $qty = 10, float $cost = 25): array
{
    return [
        'supplier_uuid' => test()->supplier->uuid,
        'branch_uuid' => test()->branch->uuid,
        'items' => [[
            'product_uuid' => $product->uuid,
            'quantity' => $qty,
            'unit_cost' => $cost,
        ]],
    ];
}

function poOrder(string $status = PurchaseOrder::STATUS_DRAFT): PurchaseOrder
{
    $estados = [
        PurchaseOrder::STATUS_SUBMITTED => 'submitted',
        PurchaseOrder::STATUS_APPROVED => 'approved',
        PurchaseOrder::STATUS_CANCELLED => 'cancelled',
    ];

    $f = PurchaseOrder::factory();
    if (isset($estados[$status])) {
        $f = $f->{$estados[$status]}();
    }

    return $f->create([
        'company_id' => test()->tenant->id,
        'branch_id' => test()->branch->id,
        'supplier_id' => test()->supplier->id,
    ]);
}

it('crea una orden en draft y calcula los totales con IVA', function (): void {
    poActor([Permissions::PURCHASE_ORDER_CREATE]);
    $product = poProduct(0.16);

    $resp = $this->postJson('/api/v1/purchase-orders', poPayload($product, 10, 25), poHeaders());

    $resp->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.subtotal', 250)
        ->assertJsonPath('data.tax_total', 40)
        ->assertJsonPath('data.total', 290);

    expect($resp->json('data.folio'))->toStartWith('OC-');
});

it('calcula sin impuesto cuando el producto no tiene tax', function (): void {
    poActor([Permissions::PURCHASE_ORDER_CREATE]);
    $unit = Unit::factory()->create(['company_id' => $this->tenant->id]);
    $product = Product::factory()->create(['company_id' => $this->tenant->id, 'unit_id' => $unit->id]);

    $this->postJson('/api/v1/purchase-orders', poPayload($product, 3, 100), poHeaders())
        ->assertCreated()
        ->assertJsonPath('data.tax_total', 0)
        ->assertJsonPath('data.total', 300);
});

it('exige al menos una linea', function (): void {
    poActor([Permissions::PURCHASE_ORDER_CREATE]);

    $this->postJson('/api/v1/purchase-orders', [
        'supplier_uuid' => $this->supplier->uuid,
        'branch_uuid' => $this->branch->uuid,
        'items' => [],
    ], poHeaders())->assertStatus(422)->assertJsonValidationErrors(['items']);
});

it('recorre el flujo draft submit approve', function (): void {
    poActor([Permissions::PURCHASE_ORDER_CREATE, Permissions::PURCHASE_ORDER_APPROVE]);
    $orden = poOrder();

    $this->postJson('/api/v1/purchase-orders/'.$orden->uuid.'/submit', [], poHeaders())
        ->assertOk()->assertJsonPath('data.status', 'submitted');

    $this->postJson('/api/v1/purchase-orders/'.$orden->uuid.'/approve', [], poHeaders())
        ->assertOk()->assertJsonPath('data.status', 'approved');

    TenantContext::set($this->tenant);
    expect($orden->fresh()->approved_at)->not->toBeNull();
});

it('rechaza con 409 aprobar una orden en draft', function (): void {
    poActor([Permissions::PURCHASE_ORDER_APPROVE]);
    $orden = poOrder();

    $this->postJson('/api/v1/purchase-orders/'.$orden->uuid.'/approve', [], poHeaders())
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'PURCHASE_ORDER_TRANSITION');
});

it('rechaza con 409 cancelar una orden ya cancelada', function (): void {
    poActor([Permissions::PURCHASE_ORDER_CREATE]);
    $orden = poOrder(PurchaseOrder::STATUS_CANCELLED);

    $this->postJson('/api/v1/purchase-orders/'.$orden->uuid.'/cancel', [], poHeaders())
        ->assertStatus(409);
});

it('cancela una orden aprobada', function (): void {
    poActor([Permissions::PURCHASE_ORDER_CREATE]);
    $orden = poOrder(PurchaseOrder::STATUS_APPROVED);

    $this->postJson('/api/v1/purchase-orders/'.$orden->uuid.'/cancel', [], poHeaders())
        ->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('lista ordenes y filtra por status', function (): void {
    poActor([Permissions::PURCHASE_ORDER_VIEW]);
    poOrder();
    poOrder(PurchaseOrder::STATUS_APPROVED);

    $this->getJson('/api/v1/purchase-orders', poHeaders())
        ->assertOk()->assertJsonCount(2, 'data');

    $this->getJson('/api/v1/purchase-orders?status=approved', poHeaders())
        ->assertOk()->assertJsonCount(1, 'data');
});

it('niega aprobar al usuario sin permiso de aprobacion', function (): void {
    poActor([Permissions::PURCHASE_ORDER_CREATE, Permissions::PURCHASE_ORDER_VIEW]);
    $orden = poOrder(PurchaseOrder::STATUS_SUBMITTED);

    $this->postJson('/api/v1/purchase-orders/'.$orden->uuid.'/approve', [], poHeaders())
        ->assertForbidden();
});

it('no expone ordenes de otro tenant', function (): void {
    poActor([Permissions::PURCHASE_ORDER_VIEW]);
    poOrder();

    $otro = Company::factory()->create(['slug' => 'po-otro']);
    TenantContext::runAs($otro, function () use ($otro): void {
        $b = Branch::factory()->default()->create(['company_id' => $otro->id]);
        $s = Supplier::factory()->create(['code' => 'PROV-AJENO']);
        PurchaseOrder::factory()->create([
            'company_id' => $otro->id,
            'branch_id' => $b->id,
            'supplier_id' => $s->id,
        ]);
    });

    TenantContext::set($this->tenant);
    $this->getJson('/api/v1/purchase-orders', poHeaders())
        ->assertOk()->assertJsonCount(1, 'data');
});
