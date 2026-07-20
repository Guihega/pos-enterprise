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
use Illuminate\Support\Collection;

/**
 * Snapshot inicial del catalogo, paginado por cursor (doc maestro sec. 38.6).
 *
 * Divergencia documentada respecto al maestro: 38.6 describe un job async,
 * pero con paginacion keyset no hay pre-generacion que justifique cola +
 * polling de estado. El POST responde de inmediato con un manifest (total,
 * per_page, cursor inicial) y el GET entrega paginas. El job en cola se
 * difiere hasta que exista evidencia de necesidad (catalogos masivos).
 *
 * Cursor: keyset por id ascendente (el cursor es el ultimo id entregado).
 * Si el total es multiplo exacto de PER_PAGE la ultima pagina util devuelve
 * cursor no nulo y la siguiente llega vacia con cursor null (documentado,
 * costo aceptado por simplicidad).
 *
 * Solo estado vigente: los soft-deleted NO se incluyen (un dispositivo
 * recien instalado no necesita historicos borrados; los borrados futuros
 * llegan por /sync/changes).
 *
 * Entidades: mismas que SyncChangesService (products, taxes, customers)
 * con los mismos Resources (simetria de contrato cliente-servidor).
 * last_full_sync lo marca el cliente localmente (sin columna en servidor,
 * diferido documentado en el PR).
 */
final class SyncSnapshotService
{
    public const PER_PAGE = 500;

    /**
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
     * @return string[]
     */
    public static function supportedEntities(): array
    {
        return array_keys(self::ENTITY_CONFIG);
    }

    /**
     * Manifest del snapshot: total actual y cursor inicial.
     *
     * @return array{entity: string, total: int, per_page: int, next_cursor: string|null}
     */
    public function manifest(string $entity): array
    {
        $config = self::ENTITY_CONFIG[$entity];
        $total = $config['model']::query()->count();

        return [
            'entity' => $entity,
            'total' => $total,
            'per_page' => self::PER_PAGE,
            'next_cursor' => $total > 0 ? '0' : null,
        ];
    }

    /**
     * Pagina keyset: registros con id > cursor, orden ascendente.
     *
     * @return array{entity: string, data: array<int, mixed>, next_cursor: string|null}
     */
    public function page(string $entity, ?string $cursor): array
    {
        $config = self::ENTITY_CONFIG[$entity];
        $afterId = $cursor !== null ? (int) $cursor : 0;

        $models = $config['model']::query()
            ->with($config['with'])
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(self::PER_PAGE)
            ->get();

        $nextCursor = $models->count() === self::PER_PAGE
            ? (string) $models->last()->getKey()
            : null;

        return [
            'entity' => $entity,
            'data' => $this->serializeMany($models, $config['resource']),
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * Mismo criterio que SyncChangesService::serializeMany (shape REST).
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
