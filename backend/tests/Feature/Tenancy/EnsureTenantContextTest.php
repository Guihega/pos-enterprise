<?php

declare(strict_types=1);

use App\Domain\Tenancy\Middleware\EnsureTenantContext;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Ruta de prueba protegida por el middleware
    Route::middleware(EnsureTenantContext::class)
        ->get('/_test/tenant-info', function (Request $request) {
            $tenant = TenantContext::current();

            return response()->json([
                'has_context' => TenantContext::has(),
                'tenant_id' => $tenant?->id,
                'tenant_slug' => $tenant?->slug,
            ]);
        });
});

it('responde 400 si no se pudo resolver el tenant', function () {
    config(['tenancy.fallback_to_default' => false]);

    $response = $this->getJson('/_test/tenant-info');

    $response->assertStatus(400)
        ->assertJsonPath('error.code', 'TENANT_NOT_RESOLVED');
});

it('resuelve el tenant por header X-Tenant con slug', function () {
    $tenant = Company::factory()->create(['slug' => 'mi-tienda']);

    $response = $this->getJson('/_test/tenant-info', [
        'X-Tenant' => 'mi-tienda',
    ]);

    $response->assertOk()
        ->assertJsonPath('has_context', true)
        ->assertJsonPath('tenant_id', $tenant->id)
        ->assertJsonPath('tenant_slug', 'mi-tienda');
});

it('resuelve el tenant por header X-Tenant con UUID', function () {
    $tenant = Company::factory()->create();

    $response = $this->getJson('/_test/tenant-info', [
        'X-Tenant' => $tenant->uuid,
    ]);

    $response->assertOk()
        ->assertJsonPath('tenant_id', $tenant->id);
});

it('rechaza tenant suspendido con 402', function () {
    Company::factory()->suspended('Pago vencido')->create([
        'slug' => 'suspendido',
    ]);

    $response = $this->getJson('/_test/tenant-info', [
        'X-Tenant' => 'suspendido',
    ]);

    $response->assertStatus(402)
        ->assertJsonPath('error.code', 'TENANT_SUSPENDED')
        ->assertJsonPath('error.details.suspension_reason', 'Pago vencido');
});

it('limpia el contexto al terminar el request', function () {
    Company::factory()->create(['slug' => 'tester']);

    $this->getJson('/_test/tenant-info', ['X-Tenant' => 'tester']);

    expect(TenantContext::has())->toBeFalse();
});

it('NO cae al fallback si el header X-Tenant fue enviado pero es inválido', function () {
    // Habilitamos fallback (modo desarrollo)
    config(['tenancy.fallback_to_default' => true]);

    // Existe un tenant en la base
    Company::factory()->create(['slug' => 'real']);

    // Cliente manda un slug que no existe → debe rechazarse, no caer al "real"
    $response = $this->getJson('/_test/tenant-info', [
        'X-Tenant' => 'no-existe',
    ]);

    $response->assertStatus(400)
        ->assertJsonPath('error.code', 'TENANT_NOT_RESOLVED');
});

it('cae al fallback solo si NO se envió ninguna pista de tenant', function () {
    config(['tenancy.fallback_to_default' => true]);
    config(['tenancy.domain' => null]);  // sin subdominio

    $tenant = Company::factory()->create(['slug' => 'el-default']);

    // Sin header, sin subdominio → fallback OK
    $response = $this->getJson('/_test/tenant-info');

    $response->assertOk()
        ->assertJsonPath('tenant_slug', 'el-default');
});
