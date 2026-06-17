<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Calcula los cambios del catalogo desde un timestamp dado (pull).
 *
 * Doc maestro sec. 38.5: GET /api/v1/sync/changes?since=...&entities=...
 *
 * Para cada entidad devuelve created / updated / deleted:
 *   - created: created_at > since
 *   - updated: updated_at > since AND created_at <= since
 *   - deleted: deleted_at > since (soft-deletes, via withTrashed)
 *
 * Fase 2: entidades soportadas = products, taxes, customers (las unicas
 * con modelo en el backend; prices/promotions/inventory_lots son de
 * fases posteriores).
 */
final class SyncChangesService
{
    /** Entidades soportadas y su modelo asociado. */
    private const ENTITY_MODELS = [
        'products'  => Product::class,
        'taxes'     => Tax::class,
        'customers' => Customer::class,
    ];

    /**
     * @param  string[]  $entities  Lista de entidades solicitadas.
     * @return array{data: array<string, array{created: array<int, array<string, mixed>>, updated: array<int, array<string, mixed>>, deleted: array<int, array{uuid: string}>}>, meta: array{snapshot_timestamp: string, has_more: bool, next_cursor: string|null}}
     */
    public function changesSince(?string $since, array $entities): array
    {
        $snapshot = Carbon::now()->toIso8601ZuluString();
        $sinceCarbon = $since !== null ? Carbon::parse($since) : null;

        $data = [];
        foreach ($entities as $entity) {
            $modelClass = self::ENTITY_MODELS[$entity] ?? null;
            if ($modelClass === null) {
                continue;
            }
            $data[$entity] = $this->changesForModel($modelClass, $sinceCarbon);
        }

        return [
            'data' => $data,
            'meta' => [
                'snapshot_timestamp' => $snapshot,
                // Fase 2: sin paginacion por pagina (catalogo acotado).
                // El snapshot inicial paginado vive en sec. 38.6 (otro endpoint).
                'has_more'    => false,
                'next_cursor' => null,
            ],
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array{created: array<int, array<string, mixed>>, updated: array<int, array<string, mixed>>, deleted: array<int, array{uuid: string}>}
     */
    private function changesForModel(string $modelClass, ?Carbon $since): array
    {
        // Sin since => snapshot completo: todo es created.
        if ($since === null) {
            $created = $modelClass::query()
                ->get()
                ->map(fn (Model $m) => $this->serialize($m))
                ->all();

            return ['created' => $created, 'updated' => [], 'deleted' => []];
        }

        $created = $modelClass::query()
            ->where('created_at', '>', $since)
            ->get()
            ->map(fn (Model $m) => $this->serialize($m))
            ->all();

        $updated = $modelClass::query()
            ->where('updated_at', '>', $since)
            ->where('created_at', '<=', $since)
            ->get()
            ->map(fn (Model $m) => $this->serialize($m))
            ->all();

        $deleted = $modelClass::query()
            ->onlyTrashed()
            ->where('deleted_at', '>', $since)
            ->get()
            ->map(fn (Model $m) => ['uuid' => $m->uuid])
            ->all();

        return ['created' => $created, 'updated' => $updated, 'deleted' => $deleted];
    }

    /**
     * Serializa un modelo a array para el cliente.
     * @return array<string, mixed>
     */
    private function serialize(Model $model): array
    {
        return $model->toArray();
    }
}
