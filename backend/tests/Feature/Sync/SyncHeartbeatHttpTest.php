<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'heartbeat-test', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);

    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->cashier = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cashier->assignRole(Roles::CAJERO);
    Sanctum::actingAs($this->cashier);
});

test('requiere autenticacion', function () {
    $this->app['auth']->forgetGuards();
    $this->withHeaders(['X-Tenant' => 'heartbeat-test'])
        ->getJson('/api/v1/sync/heartbeat')
        ->assertStatus(401);
});

test('devuelve server_time, tenant y user_uuid', function () {
    $this->withHeaders(['X-Tenant' => 'heartbeat-test'])
        ->getJson('/api/v1/sync/heartbeat')
        ->assertStatus(200)
        ->assertJsonStructure(['server_time', 'tenant', 'user_uuid']);
});

test('tenant coincide con el slug activo', function () {
    $this->withHeaders(['X-Tenant' => 'heartbeat-test'])
        ->getJson('/api/v1/sync/heartbeat')
        ->assertStatus(200)
        ->assertJsonPath('tenant', 'heartbeat-test');
});

test('user_uuid coincide con el usuario autenticado', function () {
    $this->withHeaders(['X-Tenant' => 'heartbeat-test'])
        ->getJson('/api/v1/sync/heartbeat')
        ->assertStatus(200)
        ->assertJsonPath('user_uuid', $this->cashier->uuid);
});

test('server_time es ISO 8601 Zulu (UTC)', function () {
    $response = $this->withHeaders(['X-Tenant' => 'heartbeat-test'])
        ->getJson('/api/v1/sync/heartbeat')
        ->assertStatus(200);

    $serverTime = $response->json('server_time');
    expect($serverTime)->toBeString();
    // Formato Zulu: termina en Z y parsea como fecha valida.
    expect($serverTime)->toEndWith('Z');
    expect(strtotime($serverTime))->not->toBeFalse();
});

test('server_time esta cerca de la hora actual del servidor', function () {
    $before = time();
    $response = $this->withHeaders(['X-Tenant' => 'heartbeat-test'])
        ->getJson('/api/v1/sync/heartbeat')
        ->assertStatus(200);
    $after = time();

    $serverTime = strtotime((string) $response->json('server_time'));
    // Tolerancia de 5s para el round-trip del test.
    expect($serverTime)->toBeGreaterThanOrEqual($before - 5);
    expect($serverTime)->toBeLessThanOrEqual($after + 5);
});
