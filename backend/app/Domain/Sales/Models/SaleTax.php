<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SaleTaxFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $sale_id
 * @property string $code
 * @property string $name
 * @property float $rate
 * @property float $taxable_base
 * @property float $amount
 */
class SaleTax extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'sale_taxes';

    protected $fillable = [
        'company_id', 'sale_id',
        'code', 'name', 'rate', 'taxable_base', 'amount',
    ];

    protected $casts = [
        'rate' => 'decimal:6',
        'taxable_base' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    protected static function newFactory(): Factory
    {
        return SaleTaxFactory::new();
    }
}
