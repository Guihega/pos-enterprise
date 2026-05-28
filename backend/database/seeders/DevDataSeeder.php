<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Cash\Models\CashRegister;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Seeder de datos de desarrollo: poblar el tenant `demo` con catalogo
 * de productos visible para el POS.
 *
 * Idempotente: si ya hay productos para `demo`, no hace nada.
 *
 * NO se corre automaticamente desde DatabaseSeeder en produccion.
 * Llamar manualmente con:
 *
 *     php artisan db:seed --class=DevDataSeeder
 *
 * O dejarlo en la cadena de DatabaseSeeder (que lo invoca solo en
 * entornos no-production).
 *
 * Requiere que DatabaseSeeder ya haya corrido: depende del tenant
 * `demo` y de las unidades/impuestos provisionados por CatalogProvisioner.
 */
class DevDataSeeder extends Seeder
{
    private const NUM_BRANDS = 5;
    private const NUM_CATEGORIES = 5;
    private const NUM_PRODUCTS = 25;

    public function run(): void
    {
        $demo = Company::query()->where('slug', 'demo')->first();

        if ($demo === null) {
            $this->command?->warn('[DevDataSeeder] No existe el tenant `demo`. Corre DatabaseSeeder primero.');

            return;
        }

        TenantContext::set($demo);

        try {
            $this->command?->info('[DevDataSeeder] Provisionando datos de desarrollo para tenant `demo`...');

            // Idempotencia por seccion: cada bloque chequea si ya tiene
            // sus propios datos. Asi anadir nuevos recursos al seeder no
            // requiere reset completo de la BD.

            $existingProducts = Product::query()->where('company_id', $demo->id)->count();
            $needsCatalog = $existingProducts === 0;

            // 1) Brands (solo si no hay productos)
            $brands = $needsCatalog
                ? Brand::factory()->count(self::NUM_BRANDS)->create(['company_id' => $demo->id])
                : Brand::query()->where('company_id', $demo->id)->get();

            // 2) Categories (solo si no hay productos)
            $categories = $needsCatalog
                ? Category::factory()->count(self::NUM_CATEGORIES)->create(['company_id' => $demo->id])
                : Category::query()->where('company_id', $demo->id)->get();

            if (! $needsCatalog) {
                $this->command?->info("[DevDataSeeder] {$existingProducts} productos ya existen para `demo`, skip catalogo.");
            }

            // 3) Resolver unit_id e tax_id de las ya provisionadas por CatalogProvisioner.
            $unitPza = Unit::query()->where('company_id', $demo->id)->where('code', 'PZA')->firstOrFail();
            $unitKg  = Unit::query()->where('company_id', $demo->id)->where('code', 'KG')->firstOrFail();
            $unitLt  = Unit::query()->where('company_id', $demo->id)->where('code', 'LT')->firstOrFail();
            $units = [$unitPza, $unitKg, $unitLt];

            $taxIva16 = Tax::query()->where('company_id', $demo->id)->where('code', 'IVA-16')->firstOrFail();
            $taxIva0  = Tax::query()->where('company_id', $demo->id)->where('code', 'IVA-0')->firstOrFail();

            // 4) Productos: 25 productos, mezcla de categorias/marcas/unidades.
            //    Asignamos IVA-16 por default; algunos IVA-0 (productos basicos).
            //    5 productos tienen descuento (compare_at_price).
            if ($needsCatalog) {
            for ($i = 0; $i < self::NUM_PRODUCTS; $i++) {
                $brand    = $brands->random();
                $category = $categories->random();
                $unit     = $units[array_rand($units)];
                $tax      = ($i % 5 === 0) ? $taxIva0 : $taxIva16;

                $factory = Product::factory()
                    ->active()
                    ->state([
                        'company_id'  => $demo->id,
                        'category_id' => $category->id,
                        'brand_id'    => $brand->id,
                        'unit_id'     => $unit->id,
                        'tax_id'      => $tax->id,
                    ]);

                if ($i % 5 === 1) {
                    $factory = $factory->withDiscount(15.0);
                }

                $factory->create();
            }

            $total = Product::query()->where('company_id', $demo->id)->count();
            $this->command?->info("[DevDataSeeder] OK: {$total} productos creados para `demo`.");
            }

            // 5) Cajas registradoras: una por branch del tenant demo.
            //    Idempotente: solo crea si la branch no tiene caja todavia.
            $branches = Branch::query()->where('company_id', $demo->id)->get();
            $registersCreated = 0;
            foreach ($branches as $branch) {
                $exists = CashRegister::query()
                    ->where('company_id', $demo->id)
                    ->where('branch_id', $branch->id)
                    ->exists();
                if ($exists) {
                    continue;
                }
                CashRegister::factory()->ofBranch($branch)->create([
                    'code' => $branch->code . '-CAJA-01',
                    'name' => 'Caja 1 - ' . $branch->name,
                ]);
                $registersCreated++;
            }
            if ($registersCreated > 0) {
                $this->command?->info("[DevDataSeeder] OK: {$registersCreated} caja(s) registradora(s) creada(s).");
            }
        } finally {
            TenantContext::forget();
        }
    }
}
