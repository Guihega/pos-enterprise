<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Tenancy\Models\Branch;
use App\Models\TenantScopedModel;
use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $branch_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $type
 * @property bool $is_sellable
 * @property bool $is_default
 * @property bool $is_active
 */
class Warehouse extends TenantScopedModel
{
    use HasFactory;

    public const TYPE_MAIN = 'main';

    public const TYPE_STORAGE = 'storage';

    public const TYPE_TRANSIT = 'transit';

    public const TYPE_DAMAGED = 'damaged';

    public const TYPE_CONSIGNMENT = 'consignment';

    protected $table = 'warehouses';

    protected $fillable = [
        'uuid',
        'company_id',
        'branch_id',
        'code',
        'name',
        'description',
        'type',
        'is_sellable',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'is_sellable' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function scopeSellable(Builder $q): Builder
    {
        return $q->where('is_sellable', true)->where('is_active', true);
    }

    public function scopeOfBranch(Builder $q, int $branchId): Builder
    {
        return $q->where('branch_id', $branchId);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function newFactory(): Factory
    {
        return WarehouseFactory::new();
    }
}
