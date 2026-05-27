<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Category;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = $this->faker->randomElement([
            'Bebidas', 'Abarrotes', 'Lácteos', 'Pan y galletas',
            'Limpieza', 'Cuidado personal', 'Frutas y verduras',
            'Carnes y embutidos', 'Dulces y botanas', 'Congelados',
        ]).' '.$this->faker->numberBetween(1, 9999);

        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'parent_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'description' => $this->faker->optional()->sentence(),
            'icon' => $this->faker->randomElement(['package', 'box', 'tag', 'archive', null]),
            'color' => '#'.$this->faker->numerify('######'),
            'sort_order' => $this->faker->numberBetween(0, 100),
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function child(Category $parent): self
    {
        return $this->state(fn () => [
            'parent_id' => $parent->id,
            'company_id' => $parent->company_id,
        ]);
    }
}
