<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Branch;
use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Database\Factories\TransferRequestFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Solicitud de transferencia inter-sucursal (doc maestro CU-GER-003).
 *
 * Un gerente que ve stock en otra sucursal (RN-233) solicita mercancia.
 * El gerente de la sucursal ORIGEN aprueba o rechaza. Al aprobar se crea
 * el Transfer (draft) y la FSM 14.5 de Transfer manda desde ahi.
 *
 * Maquina de estados propia (validada en TransferRequestService):
 *   pending -> approved | rejected | cancelled
 *   approved, rejected, cancelled => terminales
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $folio
 * @property int $from_branch_id
 * @property int $to_branch_id
 * @property string $status
 * @property int $requested_by_user_id
 * @property int|null $resolved_by_user_id
 * @property Carbon|null $resolved_at
 * @property string|null $rejection_reason
 * @property int|null $transfer_id
 * @property string|null $notes
 */
class TransferRequest extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Transiciones permitidas por la maquina de estados.
     * El TransferRequestService consulta este mapa antes de aplicar cambios.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $table = 'transfer_requests';

    protected $fillable = [
        'uuid', 'company_id', 'folio',
        'from_branch_id', 'to_branch_id',
        'status',
        'requested_by_user_id',
        'resolved_by_user_id', 'resolved_at', 'rejection_reason',
        'transfer_id',
        'notes',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // -------------------- Relations --------------------

    public function items(): HasMany
    {
        return $this->hasMany(TransferRequestItem::class);
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class, 'transfer_id');
    }

    // -------------------- Helpers --------------------

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
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
        return TransferRequestFactory::new();
    }
}
