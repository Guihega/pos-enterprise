<?php

declare(strict_types=1);

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
| Enforcement SYNC_DEVICE_UNREGISTERED (catalogo maestro 28.7)
|--------------------------------------------------------------------------
|
| Dispositivo revocado (DELETE /auth/devices) no puede operar sync:
| batch y heartbeat 403; registration no lo reactiva. Device
| desconocido: batch 403 (debe registrarse primero), heartbeat no-op.
|
*/

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'enforce-test', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->default()->create([
        'company_id' => $this->tenant->id,
        'code' => 'CTR',
    ]);

    $this->user = User::factory()->create(['company_id' => $this->tenant->id]);
    Sanctum::actingAs($this->user);

    $this->revoked = SyncDevice::factory()->ofBranch($this->branch)->create([
        'device_id' => 'device-revoked',
        'is_active' => false,
    ]);
    $this->active = SyncDevice::factory()->ofBranch($this->branch)->create([
        'device_id' => 'device-active',
    ]);
});

function enforceHeaders(): array
{
    return ['X-Tenant' => 'enforce-test'];
}

function enforceBatchPayload(?string $deviceId): array
{
    $payload = [
        'batch_uuid' => (string) Str::uuid(),
        'items' => [[
            'client_uuid' => (string) Str::uuid(),
            'entity_type' => 'sale',
            'entity_uuid' => (string) Str::uuid(),
            'operation' => 'create',
            'client_timestamp' => '2026-01-01T10:00:00Z',
            'payload' => ['fake' => true],
        ]],
    ];
    if ($deviceId !== null) {
        $payload['device_id'] = $deviceId;
    }

    return $payload;
}

it('batch de dispositivo revocado responde 403 SYNC_DEVICE_UNREGISTERED', function (): void {
    $this->withHeaders(enforceHeaders())
        ->postJson('/api/v1/sync/batch', enforceBatchPayload('device-revoked'))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SYNC_DEVICE_UNREGISTERED');
});

it('batch de dispositivo desconocido responde 403', function (): void {
    $this->withHeaders(enforceHeaders())
        ->postJson('/api/v1/sync/batch', enforceBatchPayload('device-nunca-visto'))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SYNC_DEVICE_UNREGISTERED');
});

it('batch sin device_id conserva el contrato (procesa)', function (): void {
    $this->withHeaders(enforceHeaders())
        ->postJson('/api/v1/sync/batch', enforceBatchPayload(null))
        ->assertStatus(200);
});

it('heartbeat de revocado responde 403; desconocido sigue 200', function (): void {
    $this->withHeaders(enforceHeaders())
        ->getJson('/api/v1/sync/heartbeat?device_id=device-revoked')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SYNC_DEVICE_UNREGISTERED');

    $this->withHeaders(enforceHeaders())
        ->getJson('/api/v1/sync/heartbeat?device_id=device-nunca-visto')
        ->assertStatus(200);
});

it('registration no reactiva un dispositivo revocado', function (): void {
    $this->withHeaders(enforceHeaders())
        ->postJson('/api/v1/sync/registration', [
            'device_id' => 'device-revoked',
            'branch_uuid' => $this->branch->uuid,
            'type' => SyncDevice::TYPE_POS,
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SYNC_DEVICE_UNREGISTERED');

    TenantContext::set($this->tenant);
    expect($this->revoked->fresh()->is_active)->toBeFalse();
});

it('re-registro de dispositivo activo sigue funcionando', function (): void {
    $this->withHeaders(enforceHeaders())
        ->postJson('/api/v1/sync/registration', [
            'device_id' => 'device-active',
            'branch_uuid' => $this->branch->uuid,
            'type' => SyncDevice::TYPE_POS,
            'name' => 'Caja renombrada',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.name', 'Caja renombrada');
});
