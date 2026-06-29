<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $price = $this->faker->randomFloat(2, 5, 1000);
        $cost = $price * $this->faker->randomFloat(2, 0.4, 0.7);

        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'category_id' => null,
            'brand_id' => null,
            'unit_id' => Unit::factory(),
            'tax_id' => null,
            'parent_id' => null,
            'sku' => 'SKU-'.strtoupper(Str::random(8)),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->paragraph(),
            'short_description' => $this->faker->optional()->sentence(),
            'cost' => $cost,
            'price' => $price,
            'compare_at_price' => null,
            'min_price' => null,
            'track_inventory' => true,
            'is_sellable' => true,
            'is_purchasable' => true,
            'allow_decimals' => false,
            'status' => Product::STATUS_ACTIVE,
            'published_at' => now(),
            'custom_attributes' => [],
            'metadata' => [],
        ];
    }

    public function active(): self
    {
        return $this->state(fn () => ['status' => Product::STATUS_ACTIVE]);
    }

    /**
     * Asigna un nombre realista de producto de retail (abarrotes, bebidas,
     * limpieza, cuidado personal) en lugar del lorem ipsum por defecto.
     * Usa faker unique para evitar repeticiones dentro de una misma corrida.
     */
    public function realisticName(): self
    {
        $nombres = [
            'Coca-Cola 600ml', 'Sabritas Original 45g', 'Galletas Marias Gamesa',
            'Leche Lala Entera 1L', 'Pan Bimbo Blanco Grande', 'Huevo Blanco 18 piezas',
            'Aceite Capullo 1L', 'Arroz Verde Valle 1kg', 'Frijol Negro La Costena 1kg',
            'Azucar Estandar 1kg', 'Cafe Nescafe Clasico 200g', 'Atun Dolores en Agua',
            'Detergente Ariel 1kg', 'Jabon Zote Rosa', 'Papel Higienico Petalo 4 rollos',
            'Shampoo Head & Shoulders 375ml', 'Pasta Colgate Triple Accion',
            'Agua Bonafont 1.5L', 'Jugo Del Valle Naranja 1L', 'Cerveza Corona 355ml',
            'Tortillas de Maiz 1kg', 'Salsa Valentina 370ml', 'Mayonesa McCormick 390g',
            'Cloro Cloralex 950ml', 'Servilletas Petalo 100 piezas', 'Yogurt Danone Fresa',
            'Cereal Zucaritas 500g', 'Chocolate Carlos V', 'Refresco Sprite 600ml',
            'Sopa Maruchan Camaron', 'Mantequilla Lala 90g', 'Queso Oaxaca 400g',
            'Jamon FUD 250g', 'Crema Lala 450ml', 'Harina de Trigo Selecta 1kg',
            'Sal La Fina 1kg', 'Caldo de Pollo Knorr', 'Chiles Jalapenos La Costena',
            'Desodorante Rexona', 'Suavizante Suavitel 850ml',
        ];

        return $this->state(fn () => [
            'name' => $this->faker->unique()->randomElement($nombres),
        ]);
    }

    public function draft(): self
    {
        return $this->state(fn () => ['status' => Product::STATUS_DRAFT, 'published_at' => null]);
    }

    public function archived(): self
    {
        return $this->state(fn () => ['status' => Product::STATUS_ARCHIVED]);
    }

    public function notSellable(): self
    {
        return $this->state(fn () => ['is_sellable' => false]);
    }

    public function withDiscount(float $percent = 20): self
    {
        return $this->state(function (array $attrs) use ($percent) {
            $price = (float) $attrs['price'];
            $compare = round($price / (1 - $percent / 100), 2);

            return ['compare_at_price' => $compare];
        });
    }

    public function inCategory(Category $category): self
    {
        return $this->state(fn () => [
            'category_id' => $category->id,
            'company_id' => $category->company_id,
        ]);
    }

    public function ofBrand(Brand $brand): self
    {
        return $this->state(fn () => [
            'brand_id' => $brand->id,
            'company_id' => $brand->company_id,
        ]);
    }

    public function withTax(Tax $tax): self
    {
        return $this->state(fn () => [
            'tax_id' => $tax->id,
            'company_id' => $tax->company_id,
        ]);
    }
}
