<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Models\TenantScopedModel;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $logo_url
 * @property string|null $website
 * @property bool $is_active
 */
class Brand extends TenantScopedModel
{
    use HasFactory;

    protected $table = 'brands';

    protected $fillable = [
        'uuid',
        'company_id',
        'name',
        'slug',
        'description',
        'logo_url',
        'website',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function newFactory(): Factory
    {
        return BrandFactory::new();
    }
}
