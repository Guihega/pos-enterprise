<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Tenancy\Models\Branch;
use App\Models\TenantScopedModel;
use Database\Factories\PurchaseOrderFactory;
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
 * @property int $supplier_id
 * @property string $folio
 * @property string $status
 * @property float $subtotal
 * @property float $tax_total
 * @property float $total
 */
class PurchaseOrder extends TenantScopedModel
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'purchase_orders';

    protected $fillable = [
        'uuid',
        'company_id',
        'branch_id',
        'supplier_id',
        'folio',
        'status',
        'requested_by',
        'approved_by',
        'submitted_at',
        'approved_at',
        'cancelled_at',
        'expected_date',
        'subtotal',
        'tax_total',
        'total',
        'notes',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expected_date' => 'date',
        'subtotal' => 'float',
        'tax_total' => 'float',
        'total' => 'float',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function scopeOfStatus(Builder $q, string $status): Builder
    {
        return $q->where('status', $status);
    }

    protected static function newFactory(): Factory
    {
        return PurchaseOrderFactory::new();
    }
}
