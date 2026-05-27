<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductBarcode;
use App\Domain\Catalog\Models\ProductImage;
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
| Tests de Productos (Bloque 1.4b)
|--------------------------------------------------------------------------
|
| Convención del proyecto: ver bloque 1.4a para detalles del helper
| expectQueryException (savepoint para no abortar transacción exterior).
|
*/

beforeEach(function () {
    $this->tenant = Company::factory()->create();
    TenantContext::set($this->tenant);

    // Catálogo auxiliar mínimo para tener unit/tax disponibles
    app(CatalogProvisioner::class)->provision($this->tenant);
    $this->unit = Unit::query()->where('code', 'PZA')->firstOrFail();
    $this->tax = Tax::query()->where('is_default', true)->firstOrFail();
});

// ====================================================================
//  Creación y aislamiento
// ====================================================================

it('crea un producto con UUID y respeta tenant en contexto', function () {
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'tax_id' => $this->tax->id,
    ]);

    expect($product->uuid)->toBeUuid()
        ->and($product->company_id)->toBe($this->tenant->id)
        ->and($product->status)->toBe(Product::STATUS_ACTIVE);
});

it('rechaza crear un producto con company_id de otro tenant', function () {
    $tenantB = Company::factory()->create();

    expect(fn () => Product::factory()->create([
        'company_id' => $tenantB->id,
        'unit_id' => $this->unit->id,
    ]))->toThrow(CrossTenantAccessException::class);
});

it('aplica TenantScope al listar productos', function () {
    Product::factory()->count(3)->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);

    $tenantB = Company::factory()->create();
    app(CatalogProvisioner::class)->provision($tenantB);
    TenantContext::set($tenantB);
    $unitB = Unit::query()->where('code', 'PZA')->firstOrFail();
    Product::factory()->count(2)->create([
        'company_id' => $tenantB->id,
        'unit_id' => $unitB->id,
    ]);

    expect(Product::query()->count())->toBe(2);

    TenantContext::set($this->tenant);
    expect(Product::query()->count())->toBe(3);
});

// ====================================================================
//  Constraints
// ====================================================================

it('rechaza dos productos con mismo SKU en el mismo tenant', function () {
    Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'sku' => 'SKU-DUP-1',
    ]);

    expectQueryException(function () {
        Product::factory()->create([
            'company_id' => $this->tenant->id,
            'unit_id' => $this->unit->id,
            'sku' => 'SKU-DUP-1',
        ]);
    });
});

it('permite mismo SKU en tenants distintos', function () {
    Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'sku' => 'SHARED-SKU',
    ]);

    $tenantB = Company::factory()->create();
    app(CatalogProvisioner::class)->provision($tenantB);
    TenantContext::set($tenantB);
    $unitB = Unit::query()->where('code', 'PZA')->firstOrFail();

    Product::factory()->create([
        'company_id' => $tenantB->id,
        'unit_id' => $unitB->id,
        'sku' => 'SHARED-SKU',
    ]);

    expect(Product::query()->count())->toBe(1);  // sólo el de B en su contexto
});

it('rechaza precio negativo (constraint check)', function () {
    expectQueryException(function () {
        Product::factory()->create([
            'company_id' => $this->tenant->id,
            'unit_id' => $this->unit->id,
            'price' => -10.00,
        ]);
    });
});

// ====================================================================
//  Relaciones
// ====================================================================

it('producto se asocia con categoría, marca, unidad y tax', function () {
    $cat = Category::factory()->create(['company_id' => $this->tenant->id]);
    $brand = Brand::factory()->create(['company_id' => $this->tenant->id]);

    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'category_id' => $cat->id,
        'brand_id' => $brand->id,
        'unit_id' => $this->unit->id,
        'tax_id' => $this->tax->id,
    ]);

    expect($product->category->id)->toBe($cat->id)
        ->and($product->brand->id)->toBe($brand->id)
        ->and($product->unit->id)->toBe($this->unit->id)
        ->and($product->tax->id)->toBe($this->tax->id);
});

it('un producto puede tener múltiples barcodes', function () {
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);

    ProductBarcode::factory()->primary()->ofProduct($product)->create([
        'company_id' => $this->tenant->id,
        'barcode' => '7501234567890',
    ]);
    ProductBarcode::factory()->ofProduct($product)->create([
        'company_id' => $this->tenant->id,
        'barcode' => '7501234567891',
        'pack_quantity' => 6,
    ]);

    expect($product->barcodes->count())->toBe(2)
        ->and($product->barcodes->where('is_primary', true)->count())->toBe(1);
});

it('rechaza barcode duplicado dentro del mismo tenant', function () {
    $p1 = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);
    $p2 = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);

    ProductBarcode::factory()->ofProduct($p1)->create([
        'company_id' => $this->tenant->id,
        'barcode' => '7501111111111',
    ]);

    expectQueryException(function () use ($p2) {
        ProductBarcode::factory()->ofProduct($p2)->create([
            'company_id' => $this->tenant->id,
            'barcode' => '7501111111111',
        ]);
    });
});

it('un producto solo puede tener una imagen primary', function () {
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);

    ProductImage::factory()->primary()->ofProduct($product)->create([
        'company_id' => $this->tenant->id,
    ]);

    expectQueryException(function () use ($product) {
        ProductImage::factory()->primary()->ofProduct($product)->create([
            'company_id' => $this->tenant->id,
        ]);
    });
});

// ====================================================================
//  Lógica del modelo
// ====================================================================

it('hasDiscount() detecta correctamente el precio comparativo', function () {
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'price' => 100,
        'compare_at_price' => 125,  // 20% de descuento
    ]);

    expect($product->hasDiscount())->toBeTrue()
        ->and((float) $product->compare_at_price)->toBeGreaterThan(100);
});

it('marginPercent calcula correctamente', function () {
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'cost' => 60,
        'price' => 100,
    ]);

    // (100 - 60) / 100 * 100 = 40%
    expect($product->margin_percent)->toBe(40.0);
});

it('marginPercent devuelve null si price = 0', function () {
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'price' => 0,
        'cost' => 0,
    ]);

    expect($product->margin_percent)->toBeNull();
});

// ====================================================================
//  Scopes y búsqueda
// ====================================================================

it('scope sellable filtra productos activos y vendibles', function () {
    Product::factory()->active()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'name' => 'Vendible',
    ]);
    Product::factory()->draft()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'name' => 'Borrador',
    ]);
    Product::factory()->notSellable()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'name' => 'Insumo',
    ]);

    $sellable = Product::query()->sellable()->pluck('name')->all();
    expect($sellable)->toBe(['Vendible']);
});

it('search() encuentra productos por nombre case-insensitive', function () {
    Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'name' => 'Coca Cola 600ml',
        'sku' => 'CC600',
    ]);
    Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'name' => 'Pepsi 600ml',
        'sku' => 'PE600',
    ]);

    expect(Product::query()->search('coca')->count())->toBe(1)
        ->and(Product::query()->search('COCA')->count())->toBe(1)
        ->and(Product::query()->search('600')->count())->toBe(2);
});

it('search() encuentra productos por SKU prefix', function () {
    Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'sku' => 'ABC-001',
        'name' => 'P1',
    ]);
    Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'sku' => 'ABC-002',
        'name' => 'P2',
    ]);
    Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'sku' => 'XYZ-001',
        'name' => 'P3',
    ]);

    expect(Product::query()->search('ABC-')->count())->toBe(2);
});

it('search() encuentra productos por barcode exacto', function () {
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'name' => 'Producto con barcode',
    ]);
    ProductBarcode::factory()->ofProduct($product)->create([
        'company_id' => $this->tenant->id,
        'barcode' => '7500000000001',
    ]);

    expect(Product::query()->search('7500000000001')->first()?->id)->toBe($product->id);
});

// ====================================================================
//  Soft deletes
// ====================================================================

it('soft delete oculta el producto pero conserva el registro', function () {
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);
    $id = $product->id;

    $product->delete();

    expect(Product::query()->find($id))->toBeNull();
    expect(Product::query()->withTrashed()->find($id))->not->toBeNull();
});

// ====================================================================
//  Schema sentinels: detectan si el schema vuelve a divergir del diseño
// ====================================================================

it('schema sentinel: products tiene status enum con draft/active/archived', function () {
    // Verifica directamente las columnas que distinguen mi schema del antiguo.
    // Si alguien restaura una migración previa con is_active boolean, este test
    // grita inmediatamente.
    $cols = DB::select("SELECT column_name FROM information_schema.columns
        WHERE table_name = 'products'
        ORDER BY ordinal_position");
    $colNames = array_map(fn ($r) => $r->column_name, $cols);

    expect($colNames)
        ->toContain('status')
        ->toContain('parent_id')
        ->toContain('compare_at_price')
        ->toContain('custom_attributes')
        ->toContain('published_at')
        ->toContain('tax_code')
        ->not->toContain('is_active', 'cost_price', 'selling_price', 'attributes', 'image_url');
});

it('schema sentinel: custom_attributes funciona como JSON sin chocar con Eloquent', function () {
    $product = Product::factory()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
        'custom_attributes' => ['origen' => 'México', 'lote' => 'A-2026'],
    ]);

    $fresh = Product::query()->find($product->id);

    expect($fresh->custom_attributes)->toBeArray()
        ->and($fresh->custom_attributes['origen'])->toBe('México')
        ->and($fresh->custom_attributes['lote'])->toBe('A-2026');
});

it('schema sentinel: archived() y draft() scopes funcionan', function () {
    Product::factory()->draft()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);
    Product::factory()->active()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);
    Product::factory()->archived()->create([
        'company_id' => $this->tenant->id,
        'unit_id' => $this->unit->id,
    ]);

    expect(Product::query()->draft()->count())->toBe(1)
        ->and(Product::query()->active()->count())->toBe(1)
        ->and(Product::query()->archived()->count())->toBe(1);
});
