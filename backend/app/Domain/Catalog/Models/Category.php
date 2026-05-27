<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Models\TenantScopedModel;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $icon
 * @property string|null $color
 * @property int $sort_order
 * @property bool $is_active
 */
class Category extends TenantScopedModel
{
    use HasFactory;

    protected $table = 'categories';

    protected $fillable = [
        'uuid',
        'company_id',
        'parent_id',
        'name',
        'slug',
        'description',
        'icon',
        'color',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    // -------------------- Relaciones --------------------

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * Devuelve la cadena de ancestros (padre, abuelo, ...) hasta la raíz.
     *
     * @return array<int, self>
     */
    public function ancestors(): array
    {
        $chain = [];
        $current = $this->parent;
        while ($current !== null) {
            $chain[] = $current;
            $current = $current->parent;
        }

        return $chain;
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Profundidad: 0 si es raíz, 1 si su padre es raíz, etc.
     */
    public function depth(): int
    {
        return count($this->ancestors());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function newFactory(): Factory
    {
        return CategoryFactory::new();
    }
}
