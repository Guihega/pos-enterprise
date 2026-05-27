<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Services\CatalogProvisioner;
use App\Domain\Tenancy\Exceptions\CrossTenantAccessException;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Tests del Catálogo Auxiliar (Bloque 1.4a)
|--------------------------------------------------------------------------
|
| NOTA sobre tests que esperan QueryException:
|   Postgres aborta la transacción entera al fallar un query con violación
|   de constraint. Como RefreshDatabase envuelve cada test en una
|   transacción, las queries posteriores (incluyendo el set_config del
|   tearDown) fallan con SQLSTATE[25P02].
|
|   Solución: encapsular la query violenta dentro de DB::transaction(),
|   que en Postgres se traduce a SAVEPOINT/ROLLBACK TO. Si la query falla,
|   solo rollbackeamos al savepoint, dejando la transacción exterior viva.
|
*/

beforeEach(function () {
    $this->tenant = Company::factory()->create();
    TenantContext::set($this->tenant);
});

// ====================================================================
//  Categorías
// ====================================================================

it('crea una categoría con UUID automático', function () {
    $category = Category::factory()->create(['company_id' => $this->tenant->id]);

    expect($category->uuid)->toBeUuid()
        ->and($category->company_id)->toBe($this->tenant->id);
});

it('soporta jerarquía padre → hijos', function () {
    $bebidas = Category::factory()->create([
        'company_id' => $this->tenant->id,
        'name' => 'Bebidas',
    ]);

    $refrescos = Category::factory()->child($bebidas)->create([
        'name' => 'Refrescos',
    ]);

    expect($refrescos->parent->id)->toBe($bebidas->id)
        ->and($bebidas->children->pluck('id')->all())->toContain($refrescos->id)
        ->and($refrescos->isRoot())->toBeFalse()
        ->and($bebidas->isRoot())->toBeTrue()
        ->and($refrescos->depth())->toBe(1);
});

it('rechaza dos categorías con mismo slug en el mismo tenant', function () {
    Category::factory()->create([
        'company_id' => $this->tenant->id,
        'slug' => 'bebidas',
    ]);

    expectQueryException(function () {
        Category::factory()->create([
            'company_id' => $this->tenant->id,
            'slug' => 'bebidas',
        ]);
    });
});

it('permite mismo slug en tenants distintos', function () {
    Category::factory()->create([
        'company_id' => $this->tenant->id,
        'slug' => 'bebidas',
    ]);

    $tenantB = Company::factory()->create();
    TenantContext::set($tenantB);

    Category::factory()->create([
        'company_id' => $tenantB->id,
        'slug' => 'bebidas',
    ]);

    expect(Category::query()->count())->toBe(1);  // sólo la de B desde su contexto
});

it('aplica TenantScope al listar categorías', function () {
    Category::factory()->count(3)->create(['company_id' => $this->tenant->id]);

    $tenantB = Company::factory()->create();
    TenantContext::set($tenantB);
    Category::factory()->count(2)->create(['company_id' => $tenantB->id]);

    expect(Category::query()->count())->toBe(2);

    TenantContext::set($this->tenant);
    expect(Category::query()->count())->toBe(3);
});

// ====================================================================
//  Brands
// ====================================================================

it('crea una marca con UUID y slug', function () {
    $brand = Brand::factory()->create([
        'company_id' => $this->tenant->id,
        'name' => 'Marca Famosa',
        'slug' => 'marca-famosa',
    ]);

    expect($brand->uuid)->toBeUuid()
        ->and($brand->slug)->toBe('marca-famosa');
});

it('rechaza dos marcas con mismo slug en el mismo tenant', function () {
    Brand::factory()->create([
        'company_id' => $this->tenant->id,
        'slug' => 'unica',
    ]);

    expectQueryException(function () {
        Brand::factory()->create([
            'company_id' => $this->tenant->id,
            'slug' => 'unica',
        ]);
    });
});

it('rechaza crear marca con company_id de otro tenant', function () {
    $tenantB = Company::factory()->create();

    expect(fn () => Brand::factory()->create(['company_id' => $tenantB->id]))
        ->toThrow(CrossTenantAccessException::class);
});

// ====================================================================
//  Units
// ====================================================================

it('crea una unidad con factor de conversión', function () {
    $kilo = Unit::factory()->create([
        'company_id' => $this->tenant->id,
        'code' => 'KG-TEST',
        'category' => Unit::CATEGORY_WEIGHT,
        'factor' => 1000,
        'is_decimal' => true,
    ]);

    expect((float) $kilo->factor)->toBe(1000.0)
        ->and($kilo->is_decimal)->toBeTrue()
        ->and($kilo->category)->toBe(Unit::CATEGORY_WEIGHT);
});

it('rechaza dos unidades con mismo code en el mismo tenant', function () {
    Unit::factory()->create([
        'company_id' => $this->tenant->id,
        'code' => 'PZA-DUP',
    ]);

    expectQueryException(function () {
        Unit::factory()->create([
            'company_id' => $this->tenant->id,
            'code' => 'PZA-DUP',
        ]);
    });
});

// ====================================================================
//  Taxes
// ====================================================================

it('crea un impuesto y calcula correctamente cuando es inclusive', function () {
    $tax = Tax::factory()->create([
        'company_id' => $this->tenant->id,
        'rate' => 0.16,
        'is_inclusive' => true,
    ]);

    // 116 inclusive con 16% → tax = 116 * 0.16 / 1.16 = 16.00
    expect($tax->compute(116.0))->toBe(16.0);
});

it('crea un impuesto y calcula correctamente cuando NO es inclusive', function () {
    $tax = Tax::factory()->create([
        'company_id' => $this->tenant->id,
        'rate' => 0.16,
        'is_inclusive' => false,
    ]);

    // 100 base + 16% = 16.00 de impuesto
    expect($tax->compute(100.0))->toBe(16.0);
});

it('permite varias taxes pero solo una default por tenant', function () {
    Tax::factory()->default()->create([
        'company_id' => $this->tenant->id,
        'code' => 'IVA-A',
    ]);

    expectQueryException(function () {
        Tax::factory()->default()->create([
            'company_id' => $this->tenant->id,
            'code' => 'IVA-B',
        ]);
    });
});

// ====================================================================
//  CatalogProvisioner
// ====================================================================

it('CatalogProvisioner siembra unidades base al provisionar un tenant nuevo', function () {
    /** @var CatalogProvisioner $cp */
    $cp = app(CatalogProvisioner::class);
    $newTenant = Company::factory()->create();

    $cp->provision($newTenant);

    TenantContext::set($newTenant);
    $codes = Unit::query()->pluck('code')->all();

    expect($codes)->toContain('PZA', 'KG', 'G', 'LT', 'ML', 'MT', 'CM', 'CJA', 'PQT');
});

it('CatalogProvisioner siembra impuestos de México (IVA 16/8/0 + EXENTO) si country_code=MX', function () {
    /** @var CatalogProvisioner $cp */
    $cp = app(CatalogProvisioner::class);
    $mxTenant = Company::factory()->create(['country_code' => 'MX']);

    $cp->provision($mxTenant);

    TenantContext::set($mxTenant);
    $codes = Tax::query()->pluck('code')->all();

    expect($codes)->toEqualCanonicalizing(['IVA-16', 'IVA-8', 'IVA-0', 'EXENTO']);

    $default = Tax::query()->where('is_default', true)->first();
    expect($default->code)->toBe('IVA-16');
});

it('CatalogProvisioner es idempotente (llamarlo dos veces no duplica)', function () {
    /** @var CatalogProvisioner $cp */
    $cp = app(CatalogProvisioner::class);
    $newTenant = Company::factory()->create();

    $cp->provision($newTenant);
    $cp->provision($newTenant);

    TenantContext::set($newTenant);

    expect(Unit::query()->count())->toBe(9)
        ->and(Tax::query()->count())->toBeGreaterThan(0);  // dependiendo del país
});
