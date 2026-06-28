<?php

declare(strict_types=1);

namespace App\Domain\Cash\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Branch;
use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Database\Factories\CashSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $cash_register_id
 * @property int $branch_id
 * @property int $opened_by
 * @property int|null $closed_by
 * @property string $status
 * @property float $opening_amount
 * @property float|null $expected_amount
 * @property float|null $counted_amount
 * @property float|null $difference
 * @property string|null $opening_notes
 * @property string|null $closing_notes
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 */
class CashSession extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_VOIDED = 'voided';

    protected $table = 'cash_sessions';

    protected $fillable = [
        'uuid', 'company_id', 'cash_register_id', 'branch_id',
        'opened_by', 'closed_by', 'status',
        'opening_amount', 'expected_amount', 'counted_amount', 'difference',
        'opening_notes', 'closing_notes',
        'opened_at', 'closed_at',
    ];

    protected $casts = [
        'opening_amount' => 'decimal:2',
        'expected_amount' => 'decimal:2',
        'counted_amount' => 'decimal:2',
        'difference' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_OPEN);
    }

    public function scopeOfRegister(Builder $q, int $registerId): Builder
    {
        return $q->where('cash_register_id', $registerId);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function newFactory(): Factory
    {
        return CashSessionFactory::new();
    }
}
