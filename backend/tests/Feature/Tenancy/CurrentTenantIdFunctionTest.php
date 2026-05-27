<?php

declare(strict_types=1);

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Fail-secure de current_tenant_id() (función SQL en 01-init.sql)
|--------------------------------------------------------------------------
|
| current_tenant_id() es la pieza que sostiene la RLS de Postgres (segunda
| barrera de aislamiento entre tenants, ver ADR-0006). Si esta función
| devolviera NULL o lanzara excepción cuando no hay contexto, las políticas
| RLS escritas como `USING (company_id = current_tenant_id())` se romperían
| de formas distintas según el motor: con NULL el filtro nunca matchea pero
| el comportamiento depende de la política; con una excepción el query falla
| con error, no fail-secure.
|
| El contrato esperado, fijado por 01-init.sql, es:
|   - Sin contexto seteado:       devuelve 0.
|   - Contexto con cadena vacía:  devuelve 0 (defensa NULLIF).
|   - Contexto con basura:        devuelve 0 (defensa EXCEPTION).
|   - Contexto válido:            devuelve el id.
|
| Como ningún tenant real tiene id 0 (las secuencias arrancan en 1), el 0
| garantiza cero filas visibles bajo RLS. Eso es el fail-secure.
|
| Estos tests fijan ese contrato. Si alguien "mejora" la función SQL y
| rompe la defensa en profundidad, estos tests caen en rojo de inmediato.
*/

/**
 * Helper local: ejecuta SELECT current_tenant_id() y devuelve el valor.
 */
function callCurrentTenantId(): int
{
    return (int) DB::scalar('SELECT current_tenant_id()');
}

/**
 * Helper local: limpia COMPLETAMENTE el estado de la variable de sesión,
 * incluyendo el TenantContext PHP. La función SQL mira app.current_tenant_id
 * en la sesión de Postgres; el TenantContext la setea con set_config(..., false).
 */
function resetTenantSessionVar(): void
{
    TenantContext::forget();
    // forget() ya hace SELECT set_config('app.current_tenant_id', '0', false),
    // pero para los casos de "vacío" y "basura" lo sobreescribimos abajo.
}

beforeEach(function () {
    resetTenantSessionVar();
});

afterEach(function () {
    resetTenantSessionVar();
});

it('devuelve el id del tenant cuando el contexto esta seteado (control positivo)', function () {
    $company = Company::factory()->create();
    TenantContext::set($company);

    expect(callCurrentTenantId())->toBe($company->id);
});

it('devuelve 0 cuando el contexto es la cadena vacia (defensa NULLIF)', function () {
    DB::statement("SELECT set_config('app.current_tenant_id', '', false)");

    expect(callCurrentTenantId())->toBe(0);
});

it('devuelve 0 cuando el contexto contiene basura no numerica (defensa EXCEPTION)', function () {
    DB::statement("SELECT set_config('app.current_tenant_id', 'no-soy-un-numero', false)");

    expect(callCurrentTenantId())->toBe(0);
});

it('devuelve 0 tras forget() del TenantContext (fail-secure tras limpieza)', function () {
    $company = Company::factory()->create();
    TenantContext::set($company);
    TenantContext::forget();

    expect(callCurrentTenantId())->toBe(0);
});
