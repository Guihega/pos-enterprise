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
    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA-01']);
    $this->cashier = User::factory()->create(['company_id' => $this->tenant->id]);
    $this->cashier->assignRole(Roles::CAJERO);
    Sanctum::actingAs($this->cashier);
});

test('POST /folio-ranges/reserve devuelve 201 con rango valido', function () {
    $this->withHeaders(['X-Tenant' => 'folio-http'])
        ->postJson('/api/v1/folio-ranges/reserve', [
            'cash_register_uuid' => $this->register->uuid,
            'series' => 'A',
            'device_id' => 'device-abc-001',
            'size' => 50,
        ])
        ->assertStatus(201)
        ->assertJsonStructure(['range_start', 'range_end', 'series', 'device_id'])
        ->assertJson(['range_start' => 1, 'range_end' => 50]);
});

test('devuelve el rango activo si ya existe para ese device_id', function () {
    $payload = [
        'cash_register_uuid' => $this->register->uuid,
        'series' => 'A',
        'device_id' => 'device-abc-001',
        'size' => 50,
    ];

    $first = $this->withHeaders(['X-Tenant' => 'folio-http'])->postJson('/api/v1/folio-ranges/reserve', $payload);
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
            'series' => 'A',
            'device_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ])
        ->assertStatus(422);
});

test('falla 422 si device_id supera 36 caracteres', function () {
    $this->withHeaders(['X-Tenant' => 'folio-http'])
        ->postJson('/api/v1/folio-ranges/reserve', [
            'cash_register_uuid' => $this->register->uuid,
            'series' => 'A',
            'device_id' => str_repeat('x', 37),
        ])
        ->assertStatus(422);
});

test('dos terminales obtienen rangos disjuntos via HTTP', function () {
    $payloadT1 = [
        'cash_register_uuid' => $this->register->uuid,
        'series' => 'A',
        'device_id' => 'terminal-http-001',
        'size' => 50,
    ];
    $payloadT2 = [
        'cash_register_uuid' => $this->register->uuid,
        'series' => 'A',
        'device_id' => 'terminal-http-002',
        'size' => 50,
    ];

    $r1 = $this->withHeaders(['X-Tenant' => 'folio-http'])->postJson('/api/v1/folio-ranges/reserve', $payloadT1);
    $r2 = $this->withHeaders(['X-Tenant' => 'folio-http'])->postJson('/api/v1/folio-ranges/reserve', $payloadT2);

    $r1->assertStatus(201);
    $r2->assertStatus(201);

    $start1 = $r1->json('range_start');
    $end1 = $r1->json('range_end');
    $start2 = $r2->json('range_start');
    $end2 = $r2->json('range_end');

    // Rangos disjuntos: [start1..end1] y [start2..end2] no se solapan
    expect($end1)->toBeLessThan($start2);

    // Cada terminal recibio exactamente 50 folios
    expect($end1 - $start1 + 1)->toBe(50);
    expect($end2 - $start2 + 1)->toBe(50);

    // device_id correcto en cada respuesta
    $r1->assertJson(['device_id' => 'terminal-http-001']);
    $r2->assertJson(['device_id' => 'terminal-http-002']);
});

test('tres terminales obtienen rangos disjuntos sin solapamiento', function () {
    $devices = ['terminal-A', 'terminal-B', 'terminal-C'];
    $ranges = [];

    foreach ($devices as $device) {
        $response = $this->withHeaders(['X-Tenant' => 'folio-http'])
            ->postJson('/api/v1/folio-ranges/reserve', [
                'cash_register_uuid' => $this->register->uuid,
                'series' => 'A',
                'device_id' => $device,
                'size' => 30,
            ]);
        $response->assertStatus(201);
        $ranges[] = ['start' => $response->json('range_start'), 'end' => $response->json('range_end')];
    }

    // Verificar que todos los rangos son disjuntos entre si
    for ($i = 0; $i < count($ranges); $i++) {
        for ($j = $i + 1; $j < count($ranges); $j++) {
            $a = $ranges[$i];
            $b = $ranges[$j];
            $solapan = $a['start'] <= $b['end'] && $b['start'] <= $a['end'];
            expect($solapan)->toBeFalse("Rangos [{$a['start']}-{$a['end']}] y [{$b['start']}-{$b['end']}] se solapan");
        }
    }

    // 90 folios en total, todos distintos
    $numeros = [];
    foreach ($ranges as $r) {
        for ($n = $r['start']; $n <= $r['end']; $n++) {
            $numeros[] = $n;
        }
    }
    expect(count(array_unique($numeros)))->toBe(90);
});

test('requiere autenticacion', function () {
    $this->app['auth']->forgetGuards();
    $this->withHeaders(['X-Tenant' => 'folio-http'])
        ->postJson('/api/v1/folio-ranges/reserve', [
            'cash_register_uuid' => $this->register->uuid,
            'series' => 'A',
            'device_id' => 'device-abc-001',
        ])
        ->assertStatus(401);
});
