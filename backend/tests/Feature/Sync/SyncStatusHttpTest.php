<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Models\SyncBatch;
use App\Domain\Sync\Models\SyncDevice;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| GET /sync/status/{device} (maestro 29.13)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'status-test', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->default()->create([
        'company_id' => $this->tenant->id,
        'code' => 'CTR',
    ]);

    $this->gerente = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->gerente->assignRole(Roles::GERENTE);
    $this->cajero = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cajero->assignRole(Roles::CAJERO);

    $this->device = SyncDevice::factory()->ofBranch($this->branch)->create([
        'device_id' => 'device-001',
        'last_sync_at' => now(),
    ]);
});

function statusHeaders(): array
{
    return ['X-Tenant' => 'status-test'];
}

it('gerente consulta el estado de sync de un dispositivo', function (): void {
    SyncBatch::query()->create([
        'uuid' => (string) Str::uuid(),
        'company_id' => $this->tenant->id,
        'device_id' => 'device-001',
        'request_payload' => [],
        'operations_count' => 3,
        'success_count' => 2,
        'conflict_count' => 1,
        'error_count' => 0,
        'status' => SyncBatch::STATUS_COMPLETED,
        'completed_at' => now(),
    ]);
    Sanctum::actingAs($this->gerente);

    $this->withHeaders(statusHeaders())
        ->getJson('/api/v1/sync/status/'.$this->device->uuid)
        ->assertStatus(200)
        ->assertJsonPath('data.device_id', 'device-001')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonCount(1, 'data.recent_batches')
        ->assertJsonPath('data.recent_batches.0.operations_count', 3)
        ->assertJsonPath('data.recent_batches.0.conflict_count', 1);
});

it('dispositivo sin batches devuelve lista vacia', function (): void {
    Sanctum::actingAs($this->gerente);

    $this->withHeaders(statusHeaders())
        ->getJson('/api/v1/sync/status/'.$this->device->uuid)
        ->assertStatus(200)
        ->assertJsonCount(0, 'data.recent_batches');
});

it('cajero recibe 403', function (): void {
    Sanctum::actingAs($this->cajero);

    $this->withHeaders(statusHeaders())
        ->getJson('/api/v1/sync/status/'.$this->device->uuid)
        ->assertStatus(403);
});

it('dispositivo de otro tenant devuelve 404', function (): void {
    $other = Company::factory()->create(['slug' => 'status-other', 'country_code' => 'MX']);
    TenantContext::set($other);
    $otherBranch = Branch::factory()->default()->create([
        'company_id' => $other->id,
        'code' => 'OTR',
    ]);
    $foreign = SyncDevice::factory()->ofBranch($otherBranch)->create([
        'device_id' => 'device-foreign',
    ]);

    TenantContext::set($this->tenant);
    Sanctum::actingAs($this->gerente);

    $this->withHeaders(statusHeaders())
        ->getJson('/api/v1/sync/status/'.$foreign->uuid)
        ->assertStatus(404);
});
