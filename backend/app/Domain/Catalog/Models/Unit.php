<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Models\TenantScopedModel;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property string|null $plural_name
 * @property string|null $symbol
 * @property string $category
 * @property float $factor
 * @property bool $is_decimal
 * @property bool $is_active
 */
class Unit extends TenantScopedModel
{
    use HasFactory;

    public const CATEGORY_COUNT = 'count';
    public const CATEGORY_WEIGHT = 'weight';
    public const CATEGORY_VOLUME = 'volume';
    public const CATEGORY_LENGTH = 'length';
    public const CATEGORY_OTHER = 'other';

    protected $table = 'units';

    protected $fillable = [
        'uuid',
        'company_id',
        'code',
        'name',
        'plural_name',
        'symbol',
        'category',
        'factor',
        'is_decimal',
        'is_active',
    ];

    protected $casts = [
        'factor' => 'decimal:6',
        'is_decimal' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function newFactory(): Factory
    {
        return UnitFactory::new();
    }
}
