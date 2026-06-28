<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Inventory\Models\Warehouse;
use App\Models\TenantScopedModel;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property string|null $tax_id
 * @property string $series
 * @property string|null $country_code
 * @property string|null $state
 * @property string|null $city
 * @property string|null $postal_code
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $timezone
 * @property array<string, mixed> $settings
 * @property bool $is_active
 * @property bool $is_default
 */
class Branch extends TenantScopedModel
{
    use HasFactory;

    protected $table = 'branches';

    protected $fillable = [
        'uuid',
        'company_id',
        'code',
        'name',
        'tax_id',
        'series',
        'country_code',
        'state',
        'city',
        'postal_code',
        'address',
        'latitude',
        'longitude',
        'phone',
        'email',
        'timezone',
        'settings',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Todos los almacenes asociados a la sucursal.
     */
    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    /**
     * Almacen marcado como default para esta sucursal. La integridad
     * "max 1 default por branch" la garantiza un partial unique index
     * a nivel de BD (ver migracion create_warehouses_table).
     */
    public function defaultWarehouse(): HasOne
    {
        return $this->hasOne(Warehouse::class)->where('is_default', true);
    }

    /**
     * Devuelve el timezone efectivo: el de la sucursal si está, si no el de la company.
     */
    protected function effectiveTimezone(): Attribute
    {
        return Attribute::get(
            fn (): string => $this->timezone ?? $this->company?->timezone ?? config('app.timezone')
        );
    }

    protected static function newFactory(): Factory
    {
        return BranchFactory::new();
    }
}
