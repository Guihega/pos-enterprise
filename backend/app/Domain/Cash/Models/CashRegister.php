<?php

declare(strict_types=1);

namespace App\Domain\Cash\Models;

use App\Domain\Tenancy\Models\Branch;
use App\Models\TenantScopedModel;
use Database\Factories\CashRegisterFactory;
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
 * @property bool $is_active
 */
class CashRegister extends TenantScopedModel
{
    use HasFactory;

    protected $table = 'cash_registers';

    protected $fillable = [
        'uuid', 'company_id', 'branch_id',
        'code', 'name', 'description', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CashSession::class);
    }

    public function activeSession(): ?CashSession
    {
        return $this->sessions()->where('status', CashSession::STATUS_OPEN)->first();
    }

    public function hasOpenSession(): bool
    {
        return $this->sessions()->where('status', CashSession::STATUS_OPEN)->exists();
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
        return CashRegisterFactory::new();
    }
}
