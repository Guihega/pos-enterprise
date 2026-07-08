<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\TransferRequest;
use App\Domain\Inventory\Models\TransferRequestItem;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransferRequestItem>
 */
class TransferRequestItemFactory extends Factory
{
    protected $model = TransferRequestItem::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'transfer_request_id' => TransferRequest::factory(),
            'product_id' => Product::factory(),
            'quantity' => 1,
            'notes' => null,
        ];
    }
}
