<?php

declare(strict_types=1);

namespace App\Domain\Sync\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Branch;
use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Database\Factories\SyncDeviceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dispositivo cliente de sync (doc maestro 26.12 sync_devices).
 *
 * Registrado via POST /api/v1/sync/registration; last_seen_at se
 * actualiza en cada heartbeat con device_id y habilita RN-194.
 * stale_alerted_at: idempotencia RN-194 patron EX-042 (se limpia al
 * volver el dispositivo).
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $branch_id
 * @property int|null $user_id
 * @property string $device_id
 * @property string|null $name
 * @property string $type
 * @property string|null $fingerprint
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $last_sync_at
 * @property Carbon|null $stale_alerted_at
 * @property bool $is_active
 * @property array $settings
 */
class SyncDevice extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const TYPE_POS = 'pos';

    public const TYPE_MOBILE = 'mobile';

    public const TYPE_KIOSK = 'kiosk';

    public const TYPES = [self::TYPE_POS, self::TYPE_MOBILE, self::TYPE_KIOSK];

    protected $table = 'sync_devices';

    protected $fillable = [
        'uuid', 'company_id', 'branch_id', 'user_id',
        'device_id', 'name', 'type', 'fingerprint',
        'last_seen_at', 'last_sync_at', 'stale_alerted_at',
        'is_active', 'settings',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'stale_alerted_at' => 'datetime',
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // -------------------- Relations --------------------

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // -------------------- Scopes --------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): Factory
    {
        return SyncDeviceFactory::new();
    }
}
