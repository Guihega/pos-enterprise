<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Domain\Tenancy\Exceptions\CrossTenantAccessException;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Scopes\TenantScope;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Trait que aporta toda la lógica multi-tenant a un modelo Eloquent.
 *
 * Existe porque algunos modelos (User especialmente) deben extender otra
 * clase base (Authenticatable, etc.) y no pueden heredar de
 * TenantScopedModel. Para esos casos, usar este trait y replicar el
 * comportamiento.
 *
 * Para modelos sin restricción de herencia, preferir extender
 * TenantScopedModel directamente.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model): void {
            // 1. UUID automático si la columna existe
            if (in_array('uuid', $model->getFillable(), true)
                && empty($model->getAttribute('uuid'))
            ) {
                $model->setAttribute('uuid', (string) Str::uuid());
            }

            // 2. Validar / asignar company_id
            $expected = TenantContext::has() ? TenantContext::id() : null;
            $provided = $model->getAttribute('company_id');

            if ($expected === null) {
                if (! TenantContext::isSuperAdmin() && $provided === null) {
                    throw new \LogicException(sprintf(
                        'No se puede crear %s sin contexto de tenant ni company_id explícito.',
                        static::class
                    ));
                }

                return;
            }

            if ($provided === null) {
                $model->setAttribute('company_id', $expected);

                return;
            }

            if ((int) $provided !== $expected) {
                throw CrossTenantAccessException::forModel(static::class, $expected, (int) $provided);
            }
        });

        static::updating(function (self $model): void {
            if ($model->isDirty('company_id')) {
                throw CrossTenantAccessException::forModel(
                    static::class,
                    (int) $model->getOriginal('company_id'),
                    (int) $model->getAttribute('company_id'),
                );
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
