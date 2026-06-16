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
    $this->tenant   = Company::factory()->create(['slug' => 'test-folio', 'country_code' => 'MX']);
    TenantContext::set($this->tenant);
    $this->branch   = Branch::factory()->default()->create(['company_id' => $this->tenant->id]);
    $this->register = CashRegister::factory()->ofBranch($this->branch)->create(['code' => 'CAJA-01']);
    $this->service  = app(FolioRangeService::class);
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
