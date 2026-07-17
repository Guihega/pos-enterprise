<?php

declare(strict_types=1);

use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Models\SyncConflict;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| GET /sync/conflicts + POST resolve (maestro 29.13, sec. 39.3)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'chttp-test', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->default()->create([
        'company_id' => $this->tenant->id,
        'code' => 'CTR',
    ]);

    $this->gerente = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->gerente->assignRole(Roles::GERENTE);
    $this->auditor = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->auditor->assignRole(Roles::AUDITOR);
    $this->cajero = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cajero->assignRole(Roles::CAJERO);

    $this->conflict = SyncConflict::query()->create([
        'uuid' => (string) Str::uuid(),
        'company_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'device_id' => 'device-001',
        'entity_type' => 'sale',
        'entity_uuid' => (string) Str::uuid(),
        'conflict_type' => SyncConflict::TYPE_CASH_SESSION_CLOSED,
        'client_data' => ['foo' => 'bar'],
        'server_data' => ['session_status' => 'closed'],
    ]);
});

function chttpHeaders(): array
{
    return ['X-Tenant' => 'chttp-test'];
}

it('gerente lista los conflictos pendientes', function (): void {
    Sanctum::actingAs($this->gerente);

    $this->withHeaders(chttpHeaders())
        ->getJson('/api/v1/sync/conflicts')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.conflict_type', 'cash_session_closed')
        ->assertJsonPath('data.0.resolution', null);
});

it('gerente resuelve un conflicto y sale de pendientes', function (): void {
    Sanctum::actingAs($this->gerente);

    $this->withHeaders(chttpHeaders())
        ->postJson('/api/v1/sync/conflicts/'.$this->conflict->uuid.'/resolve', [
            'resolution' => SyncConflict::RESOLUTION_ACCEPT_SERVER,
            'notes' => 'Venta cancelada por gerencia',
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.resolution', 'accept_server');

    TenantContext::set($this->tenant);
    $fresh = $this->conflict->fresh();
    expect($fresh->resolved_at)->not->toBeNull()
        ->and($fresh->resolved_by)->toBe($this->gerente->id);

    // Ya no aparece en pendientes; si en ?resolved=1
    $this->withHeaders(chttpHeaders())
        ->getJson('/api/v1/sync/conflicts')
        ->assertJsonCount(0, 'data');
    $this->withHeaders(chttpHeaders())
        ->getJson('/api/v1/sync/conflicts?resolved=1')
        ->assertJsonCount(1, 'data');
});

it('resolver dos veces devuelve 409', function (): void {
    Sanctum::actingAs($this->gerente);

    $payload = ['resolution' => SyncConflict::RESOLUTION_ACCEPT_CLIENT];
    $this->withHeaders(chttpHeaders())
        ->postJson('/api/v1/sync/conflicts/'.$this->conflict->uuid.'/resolve', $payload)
        ->assertStatus(200);
    $this->withHeaders(chttpHeaders())
        ->postJson('/api/v1/sync/conflicts/'.$this->conflict->uuid.'/resolve', $payload)
        ->assertStatus(409);
});

it('resolution invalida devuelve 422', function (): void {
    Sanctum::actingAs($this->gerente);

    $this->withHeaders(chttpHeaders())
        ->postJson('/api/v1/sync/conflicts/'.$this->conflict->uuid.'/resolve', [
            'resolution' => 'lo_que_sea',
        ])
        ->assertStatus(422);
});

it('auditor ve la cola pero no puede resolver', function (): void {
    Sanctum::actingAs($this->auditor);

    $this->withHeaders(chttpHeaders())
        ->getJson('/api/v1/sync/conflicts')
        ->assertStatus(200);
    $this->withHeaders(chttpHeaders())
        ->postJson('/api/v1/sync/conflicts/'.$this->conflict->uuid.'/resolve', [
            'resolution' => SyncConflict::RESOLUTION_ACCEPT_CLIENT,
        ])
        ->assertStatus(403);
});

it('cajero recibe 403 al listar', function (): void {
    Sanctum::actingAs($this->cajero);

    $this->withHeaders(chttpHeaders())
        ->getJson('/api/v1/sync/conflicts')
        ->assertStatus(403);
});
