<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tax;
use App\Domain\Customer\Models\Customer;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\TaxResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
    /**
     * Entidades soportadas: modelo, relaciones a eager-load (para que los
     * Resources con whenLoaded las incluyan) y la clase Resource.
     *
     * @var array<string, array{model: class-string<Model>, with: string[], resource: class-string<JsonResource>}>
     */
    private const ENTITY_CONFIG = [
        'products' => [
            'model' => Product::class,
            'with' => ['category', 'unit', 'tax'],
            'resource' => ProductResource::class,
        ],
        'taxes' => [
            'model' => Tax::class,
            'with' => [],
            'resource' => TaxResource::class,
        ],
        'customers' => [
            'model' => Customer::class,
            'with' => [],
            'resource' => CustomerResource::class,
        ],
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
            $config = self::ENTITY_CONFIG[$entity] ?? null;
            if ($config === null) {
                continue;
            }
            $data[$entity] = $this->changesForEntity($config, $sinceCarbon);
        }

        return [
            'data' => $data,
            'meta' => [
                'snapshot_timestamp' => $snapshot,
                // Fase 2: sin paginacion por pagina (catalogo acotado).
                // El snapshot inicial paginado vive en sec. 38.6 (otro endpoint).
                'has_more' => false,
                'next_cursor' => null,
            ],
        ];
    }

    /**
     * @param  array{model: class-string<Model>, with: string[], resource: class-string<JsonResource>}  $config
     * @return array{created: array<int, mixed>, updated: array<int, mixed>, deleted: array<int, array{uuid: string}>}
     */
    private function changesForEntity(array $config, ?Carbon $since): array
    {
        $modelClass = $config['model'];
        $with = $config['with'];
        $resource = $config['resource'];

        // Sin since => snapshot completo: todo es created.
        if ($since === null) {
            $created = $modelClass::query()->with($with)->get();

            return [
                'created' => $this->serializeMany($created, $resource),
                'updated' => [],
                'deleted' => [],
            ];
        }

        $created = $modelClass::query()->with($with)
            ->where('created_at', '>', $since)
            ->get();

        $updated = $modelClass::query()->with($with)
            ->where('updated_at', '>', $since)
            ->where('created_at', '<=', $since)
            ->get();

        $deleted = $modelClass::query()
            ->onlyTrashed()
            ->where('deleted_at', '>', $since)
            ->get()
            ->map(fn (Model $m) => ['uuid' => $m->uuid])
            ->values()
            ->all();

        return [
            'created' => $this->serializeMany($created, $resource),
            'updated' => $this->serializeMany($updated, $resource),
            'deleted' => $deleted,
        ];
    }

    /**
     * Serializa una coleccion de modelos con su Resource, devolviendo el
     * mismo shape que el endpoint REST (simetria de contrato cliente-servidor).
     *
     * @param  Collection<int, Model>  $models
     * @param  class-string<JsonResource>  $resource
     * @return array<int, mixed>
     */
    private function serializeMany($models, string $resource): array
    {
        $request = request();

        return $models
            ->map(fn (Model $m) => (new $resource($m))->toArray($request))
            ->values()
            ->all();
    }
}
