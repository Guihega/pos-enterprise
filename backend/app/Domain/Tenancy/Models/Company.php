<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $tax_id
 * @property string $country_code
 * @property string $currency_code
 * @property string $timezone
 * @property string $locale
 * @property string $plan
 * @property string $status
 * @property array<string, mixed> $settings
 * @property array<string, mixed> $limits
 */
class Company extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * Companies NO usa el TenantScopedModel: ES la fuente de verdad de los
     * tenants y solo se accede desde contexto admin / super_admin.
     */
    protected $table = 'companies';

    protected $fillable = [
        'uuid',
        'slug',
        'name',
        'legal_name',
        'tax_id',
        'country_code',
        'currency_code',
        'timezone',
        'locale',
        'plan',
        'status',
        'suspension_reason',
        'trial_ends_at',
        'logo_url',
        'primary_color',
        'settings',
        'limits',
    ];

    protected $casts = [
        'settings' => 'array',
        'limits' => 'array',
        'trial_ends_at' => 'immutable_datetime',
        'suspended_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
    ];

    protected $hidden = [];

    /** Estados de ciclo de vida del tenant. */
    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_DELETED = 'deleted';

    /** Planes disponibles. */
    public const PLAN_FREE = 'free';

    public const PLAN_STARTER = 'starter';

    public const PLAN_BUSINESS = 'business';

    public const PLAN_ENTERPRISE = 'enterprise';

    /**
     * Boot: generar UUID al crear si no se proporcionó.
     */
    protected static function booted(): void
    {
        static::creating(function (self $company): void {
            if (empty($company->uuid)) {
                $company->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Acceso conveniente: ¿está operacional?
     */
    public function isOperational(): bool
    {
        return in_array($this->status, [self::STATUS_TRIAL, self::STATUS_ACTIVE], true);
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * Lookup por slug o UUID — útil al resolver tenant por subdominio o header.
     */
    public static function findByIdentifier(string $identifier): ?self
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier)) {
            return self::where('uuid', $identifier)->first();
        }

        return self::where('slug', $identifier)->first();
    }

    /**
     * Helper de configuración — lee con default seguro.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Helper de límites del plan — devuelve null si no hay límite.
     */
    public function limit(string $key, ?int $default = null): ?int
    {
        $value = data_get($this->limits, $key, $default);

        return is_int($value) ? $value : $default;
    }

    /**
     * Para Eloquent: usar UUID en route binding.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Como el modelo vive fuera de App\Models, Eloquent no encuentra
     * la factory por convención. Le decimos explícitamente cuál es.
     */
    protected static function newFactory(): Factory
    {
        return CompanyFactory::new();
    }
}
