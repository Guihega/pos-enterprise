<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Cash\Models\CashRegister;
use App\Domain\Cash\Models\CashSession;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Tenancy\Models\Branch;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Venta (encabezado).
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $number
 * @property string $series
 * @property int $number_value
 * @property int $branch_id
 * @property int $cash_register_id
 * @property int $cash_session_id
 * @property int $warehouse_id
 * @property int|null $customer_id
 * @property string|null $customer_name
 * @property string|null $customer_tax_id
 * @property array<string, mixed> $customer_data
 * @property int $user_id
 * @property string $status
 * @property string $currency_code
 * @property float $subtotal_amount
 * @property float $discount_amount
 * @property float $tax_amount
 * @property float $tip_amount
 * @property float $total_amount
 * @property float $paid_amount
 * @property float $change_amount
 * @property string|null $notes
 * @property string|null $void_reason
 * @property int|null $voided_by
 * @property array<string, mixed> $metadata
 * @property \Carbon\Carbon|null $completed_at
 * @property \Carbon\Carbon|null $voided_at
 */
class Sale extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_VOIDED = 'voided';
    public const STATUS_REFUNDED = 'refunded';

    protected $table = 'sales';

    protected $fillable = [
        'uuid', 'company_id',
        'number', 'series', 'number_value',
        'branch_id', 'cash_register_id', 'cash_session_id', 'warehouse_id',
        'customer_id', 'customer_name', 'customer_tax_id', 'customer_data',
        'user_id', 'status', 'currency_code',
        'subtotal_amount', 'discount_amount', 'tax_amount', 'tip_amount',
        'total_amount', 'paid_amount', 'change_amount',
        'notes', 'void_reason', 'voided_by',
        'metadata',
        'completed_at', 'voided_at',
    ];

    protected $casts = [
        'customer_data' => 'array',
        'subtotal_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tip_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'change_amount' => 'decimal:2',
        'metadata' => 'array',
        'completed_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    // -------------------- Relations --------------------

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(SaleTax::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    // -------------------- Helpers --------------------

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }

    public function balanceDue(): float
    {
        return max(0.0, (float) $this->total_amount - (float) $this->paid_amount);
    }

    public function isFullyPaid(): bool
    {
        return $this->balanceDue() <= 0.001;
    }

    // -------------------- Scopes --------------------

    public function scopeCompleted(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_COMPLETED);
    }

    public function scopeOfStatus(Builder $q, string $status): Builder
    {
        return $q->where('status', $status);
    }

    public function scopeOfBranch(Builder $q, int $branchId): Builder
    {
        return $q->where('branch_id', $branchId);
    }

    public function scopeOfSession(Builder $q, int $sessionId): Builder
    {
        return $q->where('cash_session_id', $sessionId);
    }

    public function scopeBetween(Builder $q, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $q->whereBetween('completed_at', [$from, $to]);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function newFactory(): Factory
    {
        return SaleFactory::new();
    }
}
