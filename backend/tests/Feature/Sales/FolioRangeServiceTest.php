<?php

declare(strict_types=1);
use App\Domain\Cash\Models\CashRegister;
use App\Domain\Sales\Models\SaleNumberCounter;
use App\Domain\Sales\Models\SaleNumberRange;
use App\Domain\Sales\Services\FolioRangeService;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;

beforeEach(function () {
    $this->tenant = Company::factory()->create(['slug' => 'test-folio', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    $this->branch = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA-01']);
    $this->service = app(FolioRangeService::class);
});

test('reserva un rango nuevo y avanza el contador global', function () {
    $result = $this->service->reserve($this->register, 'A', 'device-001', 50);

    expect($result['range_start'])->toBe(1)
        ->and($result['range_end'])->toBe(50)
        ->and($result['series'])->toBe('A')
        ->and($result['device_id'])->toBe('device-001');

    $counter = SaleNumberCounter::where('cash_register_id', $this->register->id)
        ->where('series', 'A')->first();
    expect($counter->current_value)->toBe(50);
});

test('dos dispositivos obtienen rangos disjuntos', function () {
    $r1 = $this->service->reserve($this->register, 'A', 'device-001', 50);
    $r2 = $this->service->reserve($this->register, 'A', 'device-002', 50);

    expect($r1['range_end'])->toBeLessThan($r2['range_start']);
    expect($r2['range_start'])->toBe($r1['range_end'] + 1);
});

test('mismo dispositivo obtiene el rango activo sin crear uno nuevo', function () {
    $r1 = $this->service->reserve($this->register, 'A', 'device-001', 50);
    $r2 = $this->service->reserve($this->register, 'A', 'device-001', 50);

    expect($r1['range_start'])->toBe($r2['range_start'])
        ->and($r1['range_end'])->toBe($r2['range_end']);

    $count = SaleNumberRange::where('cash_register_id', $this->register->id)->count();
    expect($count)->toBe(1);
});

test('series distintas tienen rangos independientes', function () {
    $rA = $this->service->reserve($this->register, 'A', 'device-001', 10);
    $rB = $this->service->reserve($this->register, 'B', 'device-001', 10);

    // Cada serie tiene su propio sale_number_counters, por lo que ambas empiezan en 1.
    expect($rA['range_start'])->toBe(1)->and($rA['range_end'])->toBe(10);
    expect($rB['range_start'])->toBe(1)->and($rB['range_end'])->toBe(10);
    // Pero son rangos distintos: series A != series B, no se solapan por definicion.
    expect($rA['series'])->toBe('A');
    expect($rB['series'])->toBe('B');
});

test('respeta el size solicitado', function () {
    $result = $this->service->reserve($this->register, 'A', 'device-001', 100);
    expect($result['range_end'] - $result['range_start'] + 1)->toBe(100);
});

test('size maximo no supera 500', function () {
    $result = $this->service->reserve($this->register, 'A', 'device-001', 9999);
    expect($result['range_end'] - $result['range_start'] + 1)->toBe(500);
});

test('consume acepta un folio dentro del rango activo sin agotarlo', function () {
    $this->service->reserve($this->register, 'A', 'device-001', 50);

    $ok = $this->service->consume($this->register, 'A', 'device-001', 10);

    expect($ok)->toBeTrue();
    $range = SaleNumberRange::query()
        ->where('device_id', 'device-001')->first();
    expect($range->exhausted_at)->toBeNull();
});

test('consume del range_end marca el rango como agotado y reserve entrega uno nuevo', function () {
    $r1 = $this->service->reserve($this->register, 'A', 'device-001', 50);

    $ok = $this->service->consume($this->register, 'A', 'device-001', $r1['range_end']);

    expect($ok)->toBeTrue();
    $range = SaleNumberRange::query()
        ->where('device_id', 'device-001')->first();
    expect($range->exhausted_at)->not->toBeNull();

    // El siguiente reserve del mismo device ya no devuelve el agotado.
    $r2 = $this->service->reserve($this->register, 'A', 'device-001', 50);
    expect($r2['range_start'])->toBe($r1['range_end'] + 1);
});

test('consume rechaza un folio fuera del rango del dispositivo', function () {
    $r1 = $this->service->reserve($this->register, 'A', 'device-001', 50);

    expect($this->service->consume($this->register, 'A', 'device-001', $r1['range_end'] + 1))->toBeFalse();
    expect($this->service->consume($this->register, 'A', 'device-001', 0))->toBeFalse();
});

test('consume retorna false si el dispositivo no tiene rango activo', function () {
    expect($this->service->consume($this->register, 'A', 'device-sin-rango', 5))->toBeFalse();
});

test('consume no permite usar el rango de otro dispositivo', function () {
    $r1 = $this->service->reserve($this->register, 'A', 'device-001', 50);

    // device-002 intenta consumir un folio del rango de device-001.
    expect($this->service->consume($this->register, 'A', 'device-002', $r1['range_start']))->toBeFalse();

    // El rango de device-001 sigue activo e intacto.
    $range = SaleNumberRange::query()
        ->where('device_id', 'device-001')->first();
    expect($range->exhausted_at)->toBeNull();
});
