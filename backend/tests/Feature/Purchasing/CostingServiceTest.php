<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Purchasing\Exceptions\CostingRunTransitionException;
use App\Domain\Purchasing\Models\CostingRun;
use App\Domain\Purchasing\Services\CostingService;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->tenant = Company::factory()->create(['slug' => 'cr-tenant']);
    TenantContext::set($this->tenant);
    $this->service = app(CostingService::class);
});

function crProduct(): Product
{
    $tax = Tax::factory()->rate(0.16)->create(['company_id' => test()->tenant->id]);
    $unit = Unit::factory()->create(['company_id' => test()->tenant->id]);

    return Product::factory()->withTax($tax)->create(['unit_id' => $unit->id]);
}

/** Linea base: caja de 10 unidades a 100, sin extras. */
function crLinea(Product $p, array $extra = []): array
{
    return array_merge([
        'product_uuid' => $p->uuid,
        'pack_description' => 'caja',
        'pack_price' => 100.0,
        'units_per_pack' => 10.0,
        'packs_qty' => 1.0,
    ], $extra);
}

it('calcula el costo base sin flete ni merma: precio de caja entre unidades', function (): void {
    $run = test()->service->create('viaje', [crLinea(crProduct())]);

    $l = $run->lines->first();
    expect($l->computed_units)->toBe(10.0)
        ->and($l->computed_unit_cost)->toBe(10.0)
        ->and($l->computed_price)->toBe(10.0)
        ->and($run->status)->toBe(CostingRun::STATUS_DRAFT);
});

it('prorratea el flete por VALOR de linea, no por unidades', function (): void {
    // Linea A: 1 caja de 10 a 300 (valor 300). Linea B: 1 caja de 10 a 100 (valor 100).
    // Flete 40: A carga 30 (3 por unidad), B carga 10 (1 por unidad).
    $a = crProduct();
    $b = crProduct();
    $run = test()->service->create('viaje', [
        crLinea($a, ['pack_price' => 300.0]),
        crLinea($b),
    ], freightTotal: 40.0);

    $la = $run->lines->firstWhere('product_id', $a->id);
    $lb = $run->lines->firstWhere('product_id', $b->id);
    expect($la->computed_unit_cost)->toBe(33.0)
        ->and($lb->computed_unit_cost)->toBe(11.0);
});

it('la merma encarece las unidades que sobreviven como divisor', function (): void {
    // 10.0 de base con 20% de merma: 10 / 0.8 = 12.5
    $run = test()->service->create('viaje', [
        crLinea(crProduct(), ['waste_pct' => 0.2]),
    ]);

    expect($run->lines->first()->computed_unit_cost)->toBe(12.5);
});

it('markup y on_price dan precios distintos con el mismo margen', function (): void {
    // Costo 100: markup 30% -> 130; on_price 30% -> 142.8571
    $a = crProduct();
    $b = crProduct();
    $run = test()->service->create('viaje', [
        crLinea($a, ['pack_price' => 1000.0, 'margin_pct' => 0.3]),
        crLinea($b, ['pack_price' => 1000.0, 'margin_pct' => 0.3, 'margin_type' => 'on_price']),
    ]);

    $la = $run->lines->firstWhere('product_id', $a->id);
    $lb = $run->lines->firstWhere('product_id', $b->id);
    expect($la->computed_price)->toBe(130.0)
        ->and($lb->computed_price)->toBe(142.8571);
});

it('extra_cost de la linea se reparte entre sus unidades antes de la merma', function (): void {
    // base 10 + extra 20/10 unidades = 12; sin merma queda 12
    $run = test()->service->create('viaje', [
        crLinea(crProduct(), ['extra_cost' => 20.0]),
    ]);

    expect($run->lines->first()->computed_unit_cost)->toBe(12.0);
});

it('rechaza con 422 waste_pct de 1 o mas', function (): void {
    expect(fn () => test()->service->create('viaje', [
        crLinea(crProduct(), ['waste_pct' => 1.0]),
    ]))->toThrow(InvalidArgumentException::class);
});

it('rechaza con 422 productos inexistentes listando los uuid', function (): void {
    $falso = (string) Str::uuid();
    expect(fn () => test()->service->create('viaje', [
        crLinea(crProduct()),
        array_merge(crLinea(crProduct()), ['product_uuid' => $falso]),
    ]))->toThrow(InvalidArgumentException::class, $falso);
});

it('confirm escribe products.cost y NO toca products.price', function (): void {
    $p = crProduct();
    $precioOriginal = $p->price;
    $run = test()->service->create('viaje', [
        crLinea($p, ['waste_pct' => 0.2, 'margin_pct' => 0.3]),
    ]);

    $confirmado = test()->service->confirm($run);
    $p->refresh();

    expect($confirmado->status)->toBe(CostingRun::STATUS_CONFIRMED)
        ->and($confirmado->confirmed_at)->not->toBeNull()
        ->and((float) $p->cost)->toBe(12.5)
        ->and((float) $p->price)->toBe((float) $precioOriginal);
});

it('confirmar dos veces lanza la excepcion de transicion', function (): void {
    $run = test()->service->create('viaje', [crLinea(crProduct())]);
    test()->service->confirm($run);

    expect(fn () => test()->service->confirm($run->refresh()))
        ->toThrow(CostingRunTransitionException::class);
});
