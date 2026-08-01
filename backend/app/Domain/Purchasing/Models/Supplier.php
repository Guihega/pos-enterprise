<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Models\TenantScopedModel;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property string|null $contact_name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $notes
 * @property bool $is_active
 */
class Supplier extends TenantScopedModel
{
    use HasFactory;

    protected $table = 'suppliers';

    protected $fillable = [
        'uuid',
        'company_id',
        'code',
        'name',
        'contact_name',
        'phone',
        'email',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    protected static function newFactory(): Factory
    {
        return SupplierFactory::new();
    }
}
