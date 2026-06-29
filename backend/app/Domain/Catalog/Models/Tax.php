<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Models\TenantScopedModel;
use Database\Factories\TaxFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property float $rate
 * @property string $type
 * @property bool $is_inclusive
 * @property bool $is_active
 * @property bool $is_default
 */
class Tax extends TenantScopedModel
{
    use HasFactory;

    public const TYPE_VAT = 'vat';

    public const TYPE_SALES_TAX = 'sales_tax';

    public const TYPE_EXCISE = 'excise';

    public const TYPE_WITHHOLDING = 'withholding';

    public const TYPE_OTHER = 'other';

    protected $table = 'taxes';

    protected $fillable = [
        'uuid',
        'company_id',
        'code',
        'name',
        'description',
        'rate',
        'type',
        'is_inclusive',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'rate' => 'decimal:6',
        'is_inclusive' => 'boolean',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    /**
     * Calcula el monto de impuesto sobre un subtotal.
     *
     * Si is_inclusive: el subtotal YA incluye el tax, devuelve cuánto del
     * subtotal corresponde al impuesto. (price = base + tax → tax = price * rate / (1+rate))
     *
     * Si NO is_inclusive: el subtotal es la base, el tax se suma. (tax = base * rate)
     */
    public function compute(float $subtotal): float
    {
        $rate = (float) $this->rate;

        if ($this->is_inclusive) {
            return round($subtotal * $rate / (1 + $rate), 2);
        }

        return round($subtotal * $rate, 2);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function newFactory(): Factory
    {
        return TaxFactory::new();
    }
}
