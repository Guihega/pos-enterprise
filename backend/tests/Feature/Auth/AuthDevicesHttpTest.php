<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Models\SyncDevice;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Gestion de dispositivos (maestro 29.1: GET/DELETE auth/devices)
|--------------------------------------------------------------------------
|
| Dominio de autorizacion sobre SyncDevice. Desautorizar = is_active
| false. El enforcement en sync es del slice siguiente.
|
*/

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'devices-test', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->default()->create([
        'company_id' => $this->tenant->id,
        'code' => 'CTR',
    ]);

    $this->admin = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->admin->assignRole(Roles::ADMIN);
    $this->gerente = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->gerente->assignRole(Roles::GERENTE);
    $this->cajero = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cajero->assignRole(Roles::CAJERO);

    $this->device = SyncDevice::factory()->ofBranch($this->branch)->create([
        'device_id' => 'device-001',
    ]);
});

function authDevicesHeaders(): array
{
    return ['X-Tenant' => 'devices-test'];
}

it('admin lista los dispositivos del tenant', function (): void {
    Sanctum::actingAs($this->admin);

    $this->withHeaders(authDevicesHeaders())
        ->getJson('/api/v1/auth/devices')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.device_id', 'device-001')
        ->assertJsonPath('data.0.is_active', true);
});

it('filtra por estado con ?active=', function (): void {
    SyncDevice::factory()->ofBranch($this->branch)->create([
        'device_id' => 'device-002',
        'is_active' => false,
    ]);
    Sanctum::actingAs($this->admin);

    $this->withHeaders(authDevicesHeaders())
        ->getJson('/api/v1/auth/devices?active=0')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.device_id', 'device-002');
});

it('gerente desautoriza un dispositivo', function (): void {
    Sanctum::actingAs($this->gerente);

    $this->withHeaders(authDevicesHeaders())
        ->deleteJson('/api/v1/auth/devices/'.$this->device->uuid)
        ->assertStatus(200)
        ->assertJsonPath('data.is_active', false);

    TenantContext::set($this->tenant);
    expect($this->device->fresh()->is_active)->toBeFalse();
});

it('desautorizar dos veces es idempotente', function (): void {
    Sanctum::actingAs($this->admin);

    $this->withHeaders(authDevicesHeaders())
        ->deleteJson('/api/v1/auth/devices/'.$this->device->uuid)
        ->assertStatus(200);
    $this->withHeaders(authDevicesHeaders())
        ->deleteJson('/api/v1/auth/devices/'.$this->device->uuid)
        ->assertStatus(200)
        ->assertJsonPath('data.is_active', false);
});

it('cajero recibe 403 al listar y al desautorizar', function (): void {
    Sanctum::actingAs($this->cajero);

    $this->withHeaders(authDevicesHeaders())
        ->getJson('/api/v1/auth/devices')
        ->assertStatus(403);
    $this->withHeaders(authDevicesHeaders())
        ->deleteJson('/api/v1/auth/devices/'.$this->device->uuid)
        ->assertStatus(403);
});

it('dispositivo de otro tenant devuelve 404', function (): void {
    $otherTenant = Company::factory()->create(['slug' => 'other-tenant', 'country_code' => 'MX']);
    TenantContext::set($otherTenant);
    $otherBranch = Branch::factory()->default()->create([
        'company_id' => $otherTenant->id,
        'code' => 'OTR',
    ]);
    $foreignDevice = SyncDevice::factory()->ofBranch($otherBranch)->create([
        'device_id' => 'device-foreign',
    ]);

    TenantContext::set($this->tenant);
    Sanctum::actingAs($this->admin);

    $this->withHeaders(authDevicesHeaders())
        ->deleteJson('/api/v1/auth/devices/'.$foreignDevice->uuid)
        ->assertStatus(404);
});

it('admin reactiva un dispositivo revocado y vuelve a operar sync', function (): void {
    Sanctum::actingAs($this->admin);

    // Revocar
    $this->withHeaders(authDevicesHeaders())
        ->deleteJson('/api/v1/auth/devices/'.$this->device->uuid)
        ->assertStatus(200);

    // Revocado: el batch se rechaza (enforcement del epic anterior)
    $this->withHeaders(authDevicesHeaders())
        ->postJson('/api/v1/sync/batch', [
            'batch_uuid' => (string) Str::uuid(),
            'device_id' => 'device-001',
            'items' => [[
                'client_uuid' => (string) Str::uuid(),
                'entity_type' => 'sale',
                'entity_uuid' => (string) Str::uuid(),
                'operation' => 'create',
                'client_timestamp' => '2026-01-01T10:00:00Z',
                'payload' => ['fake' => true],
            ]],
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SYNC_DEVICE_UNREGISTERED');

    // Reactivar
    $this->withHeaders(authDevicesHeaders())
        ->postJson('/api/v1/auth/devices/'.$this->device->uuid.'/activate')
        ->assertStatus(200)
        ->assertJsonPath('data.is_active', true);

    // Reactivado: el batch vuelve a procesar (el item fake falla como
    // error individual, pero el HTTP es 200: el enforcement ya no aplica)
    $this->withHeaders(authDevicesHeaders())
        ->postJson('/api/v1/sync/batch', [
            'batch_uuid' => (string) Str::uuid(),
            'device_id' => 'device-001',
            'items' => [[
                'client_uuid' => (string) Str::uuid(),
                'entity_type' => 'sale',
                'entity_uuid' => (string) Str::uuid(),
                'operation' => 'create',
                'client_timestamp' => '2026-01-01T10:00:00Z',
                'payload' => ['fake' => true],
            ]],
        ])
        ->assertStatus(200);
});

it('reactivar un dispositivo activo es idempotente', function (): void {
    Sanctum::actingAs($this->admin);

    $this->withHeaders(authDevicesHeaders())
        ->postJson('/api/v1/auth/devices/'.$this->device->uuid.'/activate')
        ->assertStatus(200)
        ->assertJsonPath('data.is_active', true);
});

it('cajero recibe 403 al reactivar', function (): void {
    Sanctum::actingAs($this->cajero);

    $this->withHeaders(authDevicesHeaders())
        ->postJson('/api/v1/auth/devices/'.$this->device->uuid.'/activate')
        ->assertStatus(403);
});
