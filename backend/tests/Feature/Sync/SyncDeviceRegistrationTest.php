<?php

declare(strict_types=1);

use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Models\SyncDevice;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Registro de dispositivos sync + heartbeat persistente (26.12, RN-194)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'mi-tenant', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(CatalogProvisioner::class)->provision($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->default()->create([
        'company_id' => $this->tenant->id,
        'code' => 'SYD',
    ]);

    $this->user = User::factory()->create(['company_id' => $this->tenant->id]);
});

const SYD_HEADERS = ['X-Tenant' => 'mi-tenant'];

it('registra un dispositivo nuevo con 201', function (): void {
    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/v1/sync/registration', [
        'device_id' => 'dev-caja-01',
        'branch_uuid' => $this->branch->uuid,
        'type' => 'pos',
        'name' => 'Caja principal',
    ], SYD_HEADERS);

    $response->assertCreated()
        ->assertJsonPath('data.device_id', 'dev-caja-01')
        ->assertJsonPath('data.type', 'pos')
        ->assertJsonPath('data.is_active', true);

    TenantContext::set($this->tenant);
    $device = SyncDevice::query()->where('device_id', 'dev-caja-01')->firstOrFail();
    expect($device->branch_id)->toBe($this->branch->id);
    expect($device->last_seen_at)->not->toBeNull();
});

it('re-registro es idempotente: 200, actualiza y no duplica', function (): void {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/sync/registration', [
        'device_id' => 'dev-caja-02',
        'branch_uuid' => $this->branch->uuid,
        'type' => 'pos',
    ], SYD_HEADERS)->assertCreated();

    TenantContext::set($this->tenant);
    $branch2 = Branch::factory()->create([
        'company_id' => $this->tenant->id,
        'code' => 'SY2',
    ]);

    $again = $this->postJson('/api/v1/sync/registration', [
        'device_id' => 'dev-caja-02',
        'branch_uuid' => $branch2->uuid,
        'type' => 'mobile',
        'name' => 'Reasignada',
    ], SYD_HEADERS);

    $again->assertOk()->assertJsonPath('data.type', 'mobile');

    TenantContext::set($this->tenant);
    expect(SyncDevice::query()->where('device_id', 'dev-caja-02')->count())->toBe(1);
    $device = SyncDevice::query()->where('device_id', 'dev-caja-02')->firstOrFail();
    expect($device->branch_id)->toBe($branch2->id);
});

it('rechaza branch de otro tenant con 422', function (): void {
    $otro = Company::factory()->create();
    $branchAjeno = TenantContext::runAs($otro, fn () => Branch::factory()->default()->create([
        'company_id' => $otro->id,
        'code' => 'AJE',
    ]));
    TenantContext::set($this->tenant);

    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/sync/registration', [
        'device_id' => 'dev-caja-03',
        'branch_uuid' => $branchAjeno->uuid,
        'type' => 'pos',
    ], SYD_HEADERS)->assertUnprocessable();
});

it('heartbeat con device_id marca last_seen_at y limpia stale_alerted_at', function (): void {
    $device = SyncDevice::factory()->ofBranch($this->branch)->stale(5)->create([
        'device_id' => 'dev-hb-01',
        'stale_alerted_at' => now()->subHour(),
    ]);

    // Releer de BD: los timestamps solo son comparables entre si
    // cuando ambos pasaron por BD (misma convencion de serializacion);
    // el Carbon en memoria de la creacion cruza convenciones.
    $device->refresh();

    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/sync/heartbeat?device_id=dev-hb-01', SYD_HEADERS)
        ->assertOk()
        ->assertJsonStructure(['server_time', 'tenant', 'user_uuid']);

    TenantContext::set($this->tenant);
    $fresh = SyncDevice::query()->find($device->id);
    // Comparar contra el valor previo del registro, no contra now():
    // el proyecto serializa fechas en hora local con etiqueta +00, asi
    // que comparar instantes contra now() cruza convenciones y falla.
    expect($fresh->last_seen_at->greaterThan($device->last_seen_at))->toBeTrue();
    expect($fresh->stale_alerted_at)->toBeNull();
});

it('heartbeat sin device_id conserva su contrato stateless', function (): void {
    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/sync/heartbeat', SYD_HEADERS)
        ->assertOk()
        ->assertJsonPath('tenant', 'mi-tenant');

    TenantContext::set($this->tenant);
    expect(SyncDevice::query()->count())->toBe(0);
});
