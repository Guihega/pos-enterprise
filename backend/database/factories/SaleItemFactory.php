<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SaleItem>
 */
class SaleItemFactory extends Factory
{
    protected $model = SaleItem::class;

    public function definition(): array
    {
        $companyId = TenantContext::has() ? TenantContext::id() : null;
        $qty = $this->faker->numberBetween(1, 5);
        $price = $this->faker->randomFloat(2, 10, 500);
        $subtotal = round($qty * $price, 2);

        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId ?? Company::factory(),
            'sale_id' => Sale::factory(),
            'product_id' => Product::factory(),
            'product_sku' => 'SKU-'.strtoupper(Str::random(6)),
            'product_name' => $this->faker->words(3, true),
            'unit_name' => 'Pieza',
            'quantity' => $qty,
            'unit_price' => $price,
            'unit_cost' => round($price * 0.6, 2),
            'line_subtotal' => $subtotal,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'is_taxable' => true,
            'tax_inclusive' => false,
            'tax_rate' => 0.16,
            'tax_amount' => round($subtotal * 0.16, 2),
            'tax_code' => 'IVA-16',
            'line_total' => round($subtotal + ($subtotal * 0.16), 2),
            'track_inventory' => true,
            'metadata' => [],
        ];
    }
}
