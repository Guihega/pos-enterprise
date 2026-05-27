<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Tax;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tax>
 *
 * Default: tax-inclusive (precio bruto al público con IVA dentro), alineado
 * con el comportamiento del CatalogProvisioner para MX/Colombia/Argentina,
 * que es el flujo POS B2C estándar regional.
 *
 * Para tests que requieran tax exclusive (precio neto + IVA), usar el state
 * explícito ->exclusive().
 */
class TaxFactory extends Factory
{
    protected $model = Tax::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'code' => 'TAX'.$this->faker->numerify('##'),
            'name' => 'IVA tasa '.$this->faker->randomElement([0, 8, 16]).'%',
            'description' => null,
            'rate' => $this->faker->randomElement([0, 0.08, 0.16]),
            'type' => Tax::TYPE_VAT,
            'is_inclusive' => true,
            'is_active' => true,
            'is_default' => false,
        ];
    }

    public function default(): self
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    public function rate(float $rate): self
    {
        return $this->state(fn () => ['rate' => $rate]);
    }

    /**
     * Estado: el precio del producto YA incluye el IVA (default explícito).
     * El calculator extrae el impuesto del precio en lugar de sumarlo encima.
     */
    public function inclusive(): self
    {
        return $this->state(fn () => ['is_inclusive' => true]);
    }

    /**
     * Estado: el precio del producto NO incluye el IVA (precio neto).
     * El calculator suma el impuesto encima del precio.
     */
    public function exclusive(): self
    {
        return $this->state(fn () => ['is_inclusive' => false]);
    }
}
