<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Unit;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        $combos = [
            ['code' => 'PZA', 'name' => 'Pieza', 'plural' => 'Piezas', 'sym' => 'pza',
                'cat' => Unit::CATEGORY_COUNT, 'factor' => 1, 'decimal' => false],
            ['code' => 'KG', 'name' => 'Kilogramo', 'plural' => 'Kilogramos', 'sym' => 'kg',
                'cat' => Unit::CATEGORY_WEIGHT, 'factor' => 1000, 'decimal' => true],
            ['code' => 'G', 'name' => 'Gramo', 'plural' => 'Gramos', 'sym' => 'g',
                'cat' => Unit::CATEGORY_WEIGHT, 'factor' => 1, 'decimal' => true],
            ['code' => 'LT', 'name' => 'Litro', 'plural' => 'Litros', 'sym' => 'l',
                'cat' => Unit::CATEGORY_VOLUME, 'factor' => 1000, 'decimal' => true],
            ['code' => 'ML', 'name' => 'Mililitro', 'plural' => 'Mililitros', 'sym' => 'ml',
                'cat' => Unit::CATEGORY_VOLUME, 'factor' => 1, 'decimal' => true],
        ];

        $combo = $this->faker->randomElement($combos);

        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'code' => $combo['code'].$this->faker->numerify('##'),
            'name' => $combo['name'],
            'plural_name' => $combo['plural'],
            'symbol' => $combo['sym'],
            'category' => $combo['cat'],
            'factor' => $combo['factor'],
            'is_decimal' => $combo['decimal'],
            'is_active' => true,
        ];
    }
}
