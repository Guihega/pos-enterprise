<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Notificacion in-app / multicanal (doc maestro 26.14 y 11.9).
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $type
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property array<string, mixed> $data
 * @property list<string> $channels
 * @property string $severity
 * @property Carbon|null $read_at
 * @property Carbon|null $expires_at
 */
class Notification extends Model
{
    use BelongsToTenant;

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    protected $table = 'notifications';

    protected $fillable = [
        'uuid', 'company_id', 'type',
        'notifiable_type', 'notifiable_id',
        'data', 'channels', 'severity',
        'read_at', 'expires_at',
    ];

    protected $casts = [
        'data' => 'array',
        'channels' => 'array',
        'read_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeForNotifiable(Builder $query, Model $notifiable): Builder
    {
        return $query
            ->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey());
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }
}
