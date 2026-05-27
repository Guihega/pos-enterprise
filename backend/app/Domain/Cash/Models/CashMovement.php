<?php

declare(strict_types=1);

namespace App\Domain\Cash\Models;

use App\Domain\Identity\Models\User;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CashMovementFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento de caja durante una sesión.
 *
 * INMUTABLE (trigger BD bloquea UPDATE/DELETE).
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $cash_session_id
 * @property string $type
 * @property float $amount
 * @property float $delta_signed
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $reason
 * @property string|null $reference
 * @property int $user_id
 * @property array<string, mixed> $metadata
 * @property \Carbon\Carbon $movement_at
 */
class CashMovement extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const TYPE_SALE_CASH = 'sale_cash';
    public const TYPE_SALE_OTHER = 'sale_other';
    public const TYPE_REFUND_CASH = 'refund_cash';
    public const TYPE_CASH_IN = 'cash_in';
    public const TYPE_CASH_OUT = 'cash_out';
    public const TYPE_TIP = 'tip';
    public const TYPE_ADJUSTMENT = 'adjustment';

    /**
     * Tipos que afectan al efectivo físico en caja (entran al cálculo del cierre).
     *
     * @var array<int, string>
     */
    public const CASH_AFFECTING_TYPES = [
        self::TYPE_SALE_CASH,    // +
        self::TYPE_REFUND_CASH,  // -
        self::TYPE_CASH_IN,      // +
        self::TYPE_CASH_OUT,     // -
        self::TYPE_ADJUSTMENT,   // +/-
    ];

    protected $table = 'cash_movements';

    protected $fillable = [
        'uuid', 'company_id', 'cash_session_id',
        'type', 'amount', 'delta_signed',
        'source_type', 'source_id',
        'reason', 'reference',
        'user_id', 'metadata',
        'movement_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'delta_signed' => 'decimal:2',
        'metadata' => 'array',
        'movement_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Devuelve el signo (+1, -1) según el tipo. Soporta override por
     * el caller (ej. adjustment puede ser +/-).
     */
    public static function signFor(string $type): int
    {
        return match ($type) {
            self::TYPE_SALE_CASH, self::TYPE_CASH_IN => +1,
            self::TYPE_REFUND_CASH, self::TYPE_CASH_OUT => -1,
            self::TYPE_SALE_OTHER, self::TYPE_TIP => 0,
            // adjustment lo asigna el caller
            default => 0,
        };
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function newFactory(): Factory
    {
        return CashMovementFactory::new();
    }
}
