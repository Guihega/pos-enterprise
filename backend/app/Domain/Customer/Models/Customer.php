<?php

declare(strict_types=1);

namespace App\Domain\Customer\Models;

use App\Models\TenantScopedModel;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Cliente.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string|null $code
 * @property string $type
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $tax_id
 * @property array<string, mixed> $tax_data
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $mobile
 * @property string|null $address_line
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postal_code
 * @property string|null $country_code
 * @property float $credit_limit
 * @property float $credit_balance
 * @property bool $is_active
 * @property bool $is_blocked
 * @property string|null $blocked_reason
 * @property string|null $notes
 */
class Customer extends TenantScopedModel
{
    use HasFactory;

    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_BUSINESS = 'business';

    protected $table = 'customers';

    protected $fillable = [
        'uuid', 'company_id',
        'code', 'type', 'name', 'legal_name',
        'tax_id', 'tax_data',
        'email', 'phone', 'mobile',
        'address_line', 'city', 'state', 'postal_code', 'country_code',
        'credit_limit', 'credit_balance',
        'is_active', 'is_blocked', 'blocked_reason',
        'notes',
    ];

    protected $casts = [
        'tax_data' => 'array',
        'credit_limit' => 'decimal:2',
        'credit_balance' => 'decimal:2',
        'is_active' => 'boolean',
        'is_blocked' => 'boolean',
    ];

    /**
     * ¿El cliente puede comprar a crédito?
     */
    public function canBuyOnCredit(float $additionalAmount = 0): bool
    {
        if ($this->is_blocked || ! $this->is_active) {
            return false;
        }

        $available = (float) $this->credit_limit - (float) $this->credit_balance;

        return $additionalAmount <= $available;
    }

    /**
     * Crédito disponible para comprar.
     */
    public function availableCredit(): float
    {
        return max(0.0, (float) $this->credit_limit - (float) $this->credit_balance);
    }

    public function isBusiness(): bool
    {
        return $this->type === self::TYPE_BUSINESS;
    }

    // -------------------- Scopes --------------------

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->where('is_blocked', false);
    }

    public function scopeSearch(Builder $q, string $term): Builder
    {
        $needle = "%{$term}%";

        return $q->where(function (Builder $sub) use ($needle): void {
            $sub->where('name', 'ilike', $needle)
                ->orWhere('email', 'ilike', $needle)
                ->orWhere('phone', 'ilike', $needle)
                ->orWhere('mobile', 'ilike', $needle)
                ->orWhere('tax_id', 'ilike', $needle)
                ->orWhere('code', 'ilike', $needle);
        });
    }

    public function scopeWithCredit(Builder $q): Builder
    {
        return $q->where('credit_limit', '>', 0);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function newFactory(): Factory
    {
        return CustomerFactory::new();
    }
}
