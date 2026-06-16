<?php
declare(strict_types=1);
namespace App\Domain\Sync\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Sales\Dto\CheckoutRequest;
use App\Domain\Sales\Services\SalesService;
use App\Domain\Sync\Dto\SyncBatchItem;
use Throwable;

/**
 * Procesa un batch de operaciones de sync enviadas desde el cliente PWA.
 *
 * Doc maestro sec. 38.3: el servidor procesa en orden recibido.
 * Cada item devuelve status: success | conflict | error.
 *
 * Fase 2 inicial: solo soporta entity_type=sale, operation=create.
 * Otros tipos se rechazan con status=error (sin bloquear el batch).
 */
final class SyncBatchService
{
    public function __construct(
        private readonly SalesService $sales,
    ) {}

    /**
     * @param  SyncBatchItem[]  $items
     * @return array<int, array{client_uuid: string, status: string, data?: mixed, error?: string}>
     */
    public function process(array $items, User $user): array
    {
        $results = [];

        foreach ($items as $item) {
            $results[] = match (true) {
                $item->entityType === 'sale' && $item->operation === 'create'
                    => $this->processSaleCreate($item, $user),
                default
                    => [
                        'client_uuid' => $item->clientUuid,
                        'status'      => 'error',
                        'error'       => "Tipo de operacion no soportado: {$item->entityType}.{$item->operation}",
                    ],
            };
        }

        return $results;
    }

    /** @return array{client_uuid: string, status: string, data?: mixed, error?: string} */
    private function processSaleCreate(SyncBatchItem $item, User $user): array
    {
        try {
            $dto  = CheckoutRequest::fromArray($item->payload);
            $sale = $this->sales->checkout($dto, $user);

            return [
                'client_uuid'  => $item->clientUuid,
                'entity_uuid'  => $item->entityUuid,
                'status'       => 'success',
                'data'         => [
                    'uuid'  => $sale->uuid,
                    'folio' => $sale->folio,
                ],
            ];
        } catch (\App\Domain\Sales\Exceptions\PaymentMismatchException $e) {
            return ['client_uuid' => $item->clientUuid, 'status' => 'conflict', 'error' => $e->getMessage()];
        } catch (\App\Domain\Sales\Exceptions\InsufficientCreditException $e) {
            return ['client_uuid' => $item->clientUuid, 'status' => 'conflict', 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            return ['client_uuid' => $item->clientUuid, 'status' => 'error', 'error' => $e->getMessage()];
        }
    }
}
