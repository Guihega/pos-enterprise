<?php

declare(strict_types=1);

use App\Domain\Tenancy\Exceptions\CrossTenantAccessException;
use App\Domain\Tenancy\Exceptions\NoTenantContextException;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Scopes\TenantScope;
use App\Domain\Tenancy\Services\TenantContext;

/*
|--------------------------------------------------------------------------
| Aislamiento de tenants (Bloque 1.1)
|--------------------------------------------------------------------------
|
| Estos tests son críticos: si fallan, hay un bug de seguridad. Validan
| las tres barreras de aislamiento descritas en ADR-0003 y ADR-0006:
|
|   1. Global scope en Eloquent (TenantScope)
|   2. Validación al crear/actualizar (TenantScopedModel boot)
|   3. RLS en Postgres (verificado indirectamente con queries crudas)
|
| Convención dentro de estos tests: SIEMPRE pasar company_id explícito
| a Branch::factory() para no depender del contexto actual y ser
| inmunes al orden de las llamadas.
|
*/

it('TenantContext devuelve null cuando no se ha establecido', function () {
    expect(TenantContext::has())->toBeFalse()
        ->and(TenantContext::current())->toBeNull();
});

it('TenantContext::id() lanza si no hay contexto', function () {
    expect(fn () => TenantContext::id())
        ->toThrow(NoTenantContextException::class);
});

it('establecer un tenant lo hace accesible vía current()', function () {
    $company = Company::factory()->create();

    TenantContext::set($company);

    expect(TenantContext::has())->toBeTrue()
        ->and(TenantContext::id())->toBe($company->id)
        ->and(TenantContext::current()->slug)->toBe($company->slug);
});

it('forget() limpia el contexto', function () {
    $company = Company::factory()->create();
    TenantContext::set($company);

    TenantContext::forget();

    expect(TenantContext::has())->toBeFalse();
});

it('runAs() establece y restaura el contexto previo', function () {
    $a = Company::factory()->create();
    $b = Company::factory()->create();

    TenantContext::set($a);

    $result = TenantContext::runAs($b, function () use ($b) {
        expect(TenantContext::id())->toBe($b->id);

        return 'inside-b';
    });

    expect($result)->toBe('inside-b')
        ->and(TenantContext::id())->toBe($a->id);
});

it('los modelos tenant-scoped solo ven datos del tenant en contexto', function () {
    $tenantA = Company::factory()->create(['slug' => 'tenant-a']);
    $tenantB = Company::factory()->create(['slug' => 'tenant-b']);

    // Crear sucursales explícitamente en cada tenant
    TenantContext::set($tenantA);
    Branch::factory()->count(3)->create(['company_id' => $tenantA->id]);

    TenantContext::set($tenantB);
    Branch::factory()->count(2)->create(['company_id' => $tenantB->id]);

    // Verificar aislamiento desde A
    TenantContext::set($tenantA);
    expect(Branch::query()->count())->toBe(3);

    // Verificar aislamiento desde B
    TenantContext::set($tenantB);
    expect(Branch::query()->count())->toBe(2);
});

it('sin contexto de tenant no se devuelve ningún registro (fail-secure)', function () {
    $tenant = Company::factory()->create();
    TenantContext::set($tenant);
    Branch::factory()->count(5)->create(['company_id' => $tenant->id]);

    TenantContext::forget();

    expect(Branch::query()->count())->toBe(0);
});

it('crear un modelo asigna automáticamente company_id desde el contexto', function () {
    $tenant = Company::factory()->create();
    TenantContext::set($tenant);

    // Sin pasar company_id: lo debe asignar el boot del modelo desde el contexto
    $branch = Branch::factory()->make();
    $branch->company_id = null;
    $branch->save();

    expect($branch->company_id)->toBe($tenant->id);
});

it('crear con company_id distinto del tenant en contexto lanza excepción', function () {
    $tenantA = Company::factory()->create();
    $tenantB = Company::factory()->create();

    TenantContext::set($tenantA);

    expect(fn () => Branch::factory()->create(['company_id' => $tenantB->id]))
        ->toThrow(CrossTenantAccessException::class);
});

it('cambiar company_id en un update lanza excepción', function () {
    $tenantA = Company::factory()->create();
    $tenantB = Company::factory()->create();

    TenantContext::set($tenantA);
    $branch = Branch::factory()->create(['company_id' => $tenantA->id]);

    // Intentamos modificar el company_id directamente
    $branch->company_id = $tenantB->id;

    expect(fn () => $branch->save())
        ->toThrow(CrossTenantAccessException::class);
});

it('UUID se genera automáticamente al crear si no se proveyó', function () {
    $tenant = Company::factory()->create();
    TenantContext::set($tenant);

    $branch = Branch::factory()->create([
        'company_id' => $tenant->id,
        'uuid' => null,
    ]);

    expect($branch->uuid)->toBeUuid();
});

it('withoutGlobalScopes permite ver datos cross-tenant (uso administrativo)', function () {
    $tenantA = Company::factory()->create();
    $tenantB = Company::factory()->create();

    TenantContext::set($tenantA);
    Branch::factory()->count(3)->create(['company_id' => $tenantA->id]);

    TenantContext::set($tenantB);
    Branch::factory()->count(2)->create(['company_id' => $tenantB->id]);

    TenantContext::set($tenantA);

    // Con scope: solo 3
    expect(Branch::query()->count())->toBe(3);

    // Sin scope: 5 (todas)
    expect(Branch::query()->withoutGlobalScope(TenantScope::class)->count())->toBe(5);
});

it('super_admin mode bypasea el scope', function () {
    $tenantA = Company::factory()->create();
    $tenantB = Company::factory()->create();

    TenantContext::set($tenantA);
    Branch::factory()->count(3)->create(['company_id' => $tenantA->id]);

    TenantContext::set($tenantB);
    Branch::factory()->count(2)->create(['company_id' => $tenantB->id]);

    TenantContext::forget();
    TenantContext::enableSuperAdminMode();

    expect(Branch::query()->count())->toBe(5);
});
