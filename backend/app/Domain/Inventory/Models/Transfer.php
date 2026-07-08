<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Branch;
use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Database\Factories\TransferFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transferencia inter-sucursal (doc maestro 46.4 y 14.5).
 *
 * Maquina de estados (validada en TransferService):
 *   draft -> sent | cancelled
 *   sent  -> received | returned_to_origin
 *   returned_to_origin -> cancelled
 *   received, cancelled => terminales
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $folio
 * @property int $from_branch_id
 * @property int $to_branch_id
 * @property int|null $from_warehouse_id
 * @property int|null $to_warehouse_id
 * @property string $status
 * @property int|null $sent_by_user_id
 * @property int|null $received_by_user_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $received_at
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancellation_reason
 * @property string|null $transport_method
 * @property string|null $transport_reference
 * @property string|null $notes
 * @property float $total_cost
 */
class Transfer extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_RETURNED_TO_ORIGIN = 'returned_to_origin';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Transiciones permitidas por la maquina de estados (14.5).
     * El TransferService consulta este mapa antes de aplicar cualquier cambio.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SENT, self::STATUS_CANCELLED],
        self::STATUS_SENT => [self::STATUS_RECEIVED, self::STATUS_RETURNED_TO_ORIGIN],
        self::STATUS_RETURNED_TO_ORIGIN => [self::STATUS_CANCELLED],
        self::STATUS_RECEIVED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $table = 'transfers';

    protected $fillable = [
        'uuid', 'company_id', 'folio',
        'from_branch_id', 'to_branch_id',
        'from_warehouse_id', 'to_warehouse_id',
        'status',
        'sent_by_user_id', 'received_by_user_id',
        'sent_at', 'received_at',
        'cancelled_at', 'cancelled_by', 'cancellation_reason',
        'lost_alerted_at',
        'transport_method', 'transport_reference',
        'notes', 'total_cost',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'lost_alerted_at' => 'datetime',
        'total_cost' => 'decimal:2',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // -------------------- Relations --------------------

    public function items(): HasMany
    {
        return $this->hasMany(TransferItem::class);
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    // -------------------- Helpers --------------------

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isReceived(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }

    public function isReturnedToOrigin(): bool
    {
        return $this->status === self::STATUS_RETURNED_TO_ORIGIN;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Indica si se permite la transicion al estado destino desde el actual.
     */
    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    protected static function newFactory(): Factory
    {
        return TransferFactory::new();
    }
}
