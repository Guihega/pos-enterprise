<?php

declare(strict_types=1);

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Tenancy\Exceptions\CrossTenantAccessException;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;

beforeEach(function () {
    $this->tenant = Company::factory()->create();
    TenantContext::set($this->tenant);
    $this->branch = Branch::factory()->default()->create([
        'company_id' => $this->tenant->id,
    ]);
});

it('crea un almacén con UUID y respeta tenant', function () {
    $w = Warehouse::factory()->ofBranch($this->branch)->create();

    expect($w->uuid)->toBeUuid()
        ->and($w->company_id)->toBe($this->tenant->id)
        ->and($w->branch_id)->toBe($this->branch->id);
});

it('rechaza warehouse con company_id de otro tenant', function () {
    $otherTenant = Company::factory()->create();

    // Crear el branch dentro del contexto del otherTenant (legítimo)
    TenantContext::set($otherTenant);
    $otherBranch = Branch::factory()->create(['company_id' => $otherTenant->id]);

    // Volver al tenant principal y desde ahí intentar crear warehouse
    // del otherTenant: ESO es lo que debe lanzar CrossTenantAccessException.
    TenantContext::set($this->tenant);

    expect(fn () => Warehouse::factory()->create([
        'company_id' => $otherTenant->id,
        'branch_id' => $otherBranch->id,
    ]))->toThrow(CrossTenantAccessException::class);
});

it('rechaza dos almacenes con mismo code en mismo tenant', function () {
    Warehouse::factory()->ofBranch($this->branch)->create(['code' => 'WH-DUP']);

    expectQueryException(function () {
        Warehouse::factory()->ofBranch($this->branch)->create(['code' => 'WH-DUP']);
    });
});

it('rechaza dos almacenes default en la misma branch', function () {
    Warehouse::factory()->default()->ofBranch($this->branch)->create(['code' => 'A']);

    expectQueryException(function () {
        Warehouse::factory()->default()->ofBranch($this->branch)->create(['code' => 'B']);
    });
});

it('permite default en branches distintas del mismo tenant', function () {
    $branch2 = Branch::factory()->create(['company_id' => $this->tenant->id]);

    Warehouse::factory()->default()->ofBranch($this->branch)->create(['code' => 'A']);
    Warehouse::factory()->default()->ofBranch($branch2)->create(['code' => 'B']);

    expect(Warehouse::query()->where('is_default', true)->count())->toBe(2);
});

it('scope sellable filtra activos y vendibles', function () {
    Warehouse::factory()->ofBranch($this->branch)->create([
        'is_active' => true, 'is_sellable' => true, 'code' => 'A',
    ]);
    Warehouse::factory()->ofBranch($this->branch)->storage()->create([
        'code' => 'B',
    ]);
    Warehouse::factory()->ofBranch($this->branch)->create([
        'is_active' => false, 'is_sellable' => true, 'code' => 'C',
    ]);

    expect(Warehouse::query()->sellable()->count())->toBe(1);
});

it('scope ofBranch filtra por sucursal', function () {
    $branch2 = Branch::factory()->create(['company_id' => $this->tenant->id]);

    Warehouse::factory()->count(2)->ofBranch($this->branch)->create();
    Warehouse::factory()->count(3)->ofBranch($branch2)->create();

    expect(Warehouse::query()->ofBranch($this->branch->id)->count())->toBe(2)
        ->and(Warehouse::query()->ofBranch($branch2->id)->count())->toBe(3);
});

it('soft delete oculta pero conserva el registro', function () {
    $w = Warehouse::factory()->ofBranch($this->branch)->create();
    $id = $w->id;

    $w->delete();

    expect(Warehouse::query()->find($id))->toBeNull();
    expect(Warehouse::query()->withTrashed()->find($id))->not->toBeNull();
});
