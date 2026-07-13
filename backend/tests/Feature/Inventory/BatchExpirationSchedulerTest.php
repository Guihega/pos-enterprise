<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Scheduler de caducidad de lotes (RN-195, batches:detect-expiring)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->tenant = Company::factory()->create();
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(CatalogProvisioner::class)->provision($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->unit = Unit::query()->where('code', 'PZA')->firstOrFail();
    $this->branch = Branch::factory()->default()->create([
        'company_id' => $this->tenant->id,
        'code' => 'BEX',
    ]);
    $this->warehouse = Warehouse::factory()->default()->ofBranch($this->branch)->create();

    $this->almacenista = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->almacenista->assignRole(Roles::ALMACEN);
    $this->almacenista->syncBranches([$this->branch]);

    $this->inventory = app(InventoryService::class);
});

function bexpProduct($test, string $sku): Product
{
    return Product::factory()->create([
        'company_id' => $test->tenant->id,
        'unit_id' => $test->unit->id,
        'sku' => $sku,
        'price' => 100,
        'track_inventory' => true,
        'tracks_lots' => true,
    ]);
}

function bexpBatch($test, Product $product, float $qty, string $lot, ?string $exp): Batch
{
    $test->inventory->recordEntry($product, $test->warehouse, $qty, 40,
        batch: ['lot_number' => $lot, 'expiration_date' => $exp]);

    return Batch::query()->where('lot_number', $lot)->firstOrFail();
}

it('notifica expiring_soon WARNING a almacen por lote proximo a caducar', function (): void {
    $product = bexpProduct($this, 'SKU-BEXP-1');
    $near = bexpBatch($this, $product, 5, 'L-BEXP-CERCA', now()->addDays(10)->toDateString());
    bexpBatch($this, $product, 5, 'L-BEXP-LEJOS', now()->addDays(90)->toDateString());
    bexpBatch($this, $product, 5, 'L-BEXP-SIN', null);

    $this->artisan('batches:detect-expiring')->assertSuccessful();

    TenantContext::set($this->tenant);
    $notes = Notification::query()
        ->forNotifiable($this->almacenista)
        ->where('type', 'inventory.expiring_soon')
        ->get();

    expect($notes)->toHaveCount(1);
    expect($notes->first()->severity)->toBe(Notification::SEVERITY_WARNING);
    expect($notes->first()->data['lot_number'])->toBe('L-BEXP-CERCA');
    expect((float) Batch::query()->find($near->id)->expiring_alerted_at?->timestamp)->toBeGreaterThan(0);
});

it('notifica expired CRITICAL por lote caducado y no lo duplica como expiring', function (): void {
    $product = bexpProduct($this, 'SKU-BEXP-2');
    $expired = bexpBatch($this, $product, 5, 'L-BEXP-VENCIDO', now()->subDays(3)->toDateString());

    $this->artisan('batches:detect-expiring')->assertSuccessful();

    TenantContext::set($this->tenant);
    $all = Notification::query()->forNotifiable($this->almacenista)->get();

    expect($all)->toHaveCount(1);
    expect($all->first()->type)->toBe('inventory.expired');
    expect($all->first()->severity)->toBe(Notification::SEVERITY_CRITICAL);

    $fresh = Batch::query()->find($expired->id);
    expect($fresh->expired_alerted_at)->not->toBeNull();
    expect($fresh->expiring_alerted_at)->not->toBeNull();
});

it('es idempotente: segunda corrida no genera notificaciones nuevas', function (): void {
    $product = bexpProduct($this, 'SKU-BEXP-3');
    bexpBatch($this, $product, 5, 'L-BEXP-IDEM-C', now()->addDays(5)->toDateString());
    bexpBatch($this, $product, 5, 'L-BEXP-IDEM-V', now()->subDay()->toDateString());

    $this->artisan('batches:detect-expiring')->assertSuccessful();
    TenantContext::set($this->tenant);
    $countAfterFirst = Notification::query()->forNotifiable($this->almacenista)->count();

    $this->artisan('batches:detect-expiring')->assertSuccessful();
    TenantContext::set($this->tenant);
    $countAfterSecond = Notification::query()->forNotifiable($this->almacenista)->count();

    expect($countAfterFirst)->toBe(2);
    expect($countAfterSecond)->toBe($countAfterFirst);
});

it('ignora lotes agotados y respeta --days configurable', function (): void {
    $product = bexpProduct($this, 'SKU-BEXP-4');
    $depleted = bexpBatch($this, $product, 3, 'L-BEXP-AGOTADO', now()->addDays(5)->toDateString());
    TenantContext::set($this->tenant);
    $depleted->update(['quantity' => 0]);
    bexpBatch($this, $product, 5, 'L-BEXP-D45', now()->addDays(45)->toDateString());

    $this->artisan('batches:detect-expiring')->assertSuccessful();
    TenantContext::set($this->tenant);
    expect(Notification::query()->forNotifiable($this->almacenista)->count())->toBe(0);

    $this->artisan('batches:detect-expiring', ['--days' => 60])->assertSuccessful();
    TenantContext::set($this->tenant);
    $notes = Notification::query()->forNotifiable($this->almacenista)->get();
    expect($notes)->toHaveCount(1);
    expect($notes->first()->data['lot_number'])->toBe('L-BEXP-D45');
});
