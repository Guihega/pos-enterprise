<?php
declare(strict_types=1);
use App\Domain\Authorization\Roles;
use App\Domain\Authorization\Services\RoleProvisioner;
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Identity\Models\User;
use App\Domain\Sales\Models\SaleNumberRange;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'folio-http', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->branch   = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA-01']);
    $this->cashier  = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cashier->assignRole(Roles::CAJERO);
    Sanctum::actingAs($this->cashier);
});

test('POST /folio-ranges/reserve devuelve 201 con rango valido', function () {
    $this->withHeaders(['X-Tenant' => 'folio-http'])
        ->postJson('/api/v1/folio-ranges/reserve', [
            'cash_register_uuid' => $this->register->uuid,
            'series'             => 'A',
            'device_id'          => 'device-abc-001',
            'size'               => 50,
        ])
        ->assertStatus(201)
        ->assertJsonStructure(['range_start', 'range_end', 'series', 'device_id'])
        ->assertJson(['range_start' => 1, 'range_end' => 50]);
});

test('devuelve el rango activo si ya existe para ese device_id', function () {
    $payload = [
        'cash_register_uuid' => $this->register->uuid,
        'series'             => 'A',
        'device_id'          => 'device-abc-001',
        'size'               => 50,
    ];

    $first  = $this->withHeaders(['X-Tenant' => 'folio-http'])->postJson('/api/v1/folio-ranges/reserve', $payload);
    $second = $this->withHeaders(['X-Tenant' => 'folio-http'])->postJson('/api/v1/folio-ranges/reserve', $payload);

    $first->assertStatus(201);
    $second->assertStatus(201);
    expect($first->json('range_start'))->toBe($second->json('range_start'));
    TenantContext::set($this->tenant);
    expect(SaleNumberRange::count())->toBe(1);
});

test('falla 422 si cash_register_uuid no pertenece al tenant', function () {
    // UUID valido pero inexistente en el tenant actual.
    $this->withHeaders(['X-Tenant' => 'folio-http'])
        ->postJson('/api/v1/folio-ranges/reserve', [
            'cash_register_uuid' => '00000000-0000-0000-0000-000000000000',
            'series'             => 'A',
            'device_id'          => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ])
        ->assertStatus(422);
});

test('falla 422 si device_id supera 36 caracteres', function () {
    $this->withHeaders(['X-Tenant' => 'folio-http'])
        ->postJson('/api/v1/folio-ranges/reserve', [
            'cash_register_uuid' => $this->register->uuid,
            'series'             => 'A',
            'device_id'          => str_repeat('x', 37),
        ])
        ->assertStatus(422);
});

test('requiere autenticacion', function () {
    $this->app['auth']->forgetGuards();
    $this->withHeaders(['X-Tenant' => 'folio-http'])
        ->postJson('/api/v1/folio-ranges/reserve', [
            'cash_register_uuid' => $this->register->uuid,
            'series'             => 'A',
            'device_id'          => 'device-abc-001',
        ])
        ->assertStatus(401);
});
