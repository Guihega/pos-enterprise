<?php

declare(strict_types=1);

namespace App\Domain\Sync\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Sales\Dto\CheckoutRequest;
use App\Domain\Sales\Exceptions\InsufficientCreditException;
use App\Domain\Sales\Exceptions\PaymentMismatchException;
use App\Domain\Sales\Services\SalesService;
use App\Domain\Sync\Dto\SyncBatchItem;
use App\Domain\Sync\Models\SyncBatch;
use App\Domain\Sync\Models\SyncDevice;
use App\Domain\Sync\Models\SyncOperation;
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
    public function process(array $items, User $user, string $batchUuid, ?string $deviceId = null): array
    {
        // Idempotencia real por batch_uuid (contrato 38.3, salda el TODO
        // del controller): un replay devuelve la respuesta ya calculada
        // sin reprocesar (las ventas no se duplican).
        $existing = SyncBatch::query()->where('uuid', $batchUuid)->first();
        if ($existing !== null && $existing->status === SyncBatch::STATUS_COMPLETED) {
            return $existing->response_payload ?? [];
        }

        $batch = $existing ?? SyncBatch::query()->create([
            'uuid' => $batchUuid,
            'device_id' => $deviceId,
            'operations_count' => count($items),
            'status' => SyncBatch::STATUS_PROCESSING,
            'request_payload' => array_map(
                fn (SyncBatchItem $i): array => [
                    'client_uuid' => $i->clientUuid,
                    'entity_type' => $i->entityType,
                    'entity_uuid' => $i->entityUuid,
                    'operation' => $i->operation,
                    'payload' => $i->payload,
                    'client_timestamp' => $i->clientTimestamp,
                ],
                $items,
            ),
        ]);

        $results = [];

        foreach ($items as $item) {
            $results[] = match (true) {
                $item->entityType === 'sale' && $item->operation === 'create' => $this->processSaleCreate($item, $user, $deviceId),
                default => [
                    'client_uuid' => $item->clientUuid,
                    'status' => 'error',
                    'error' => "Tipo de operacion no soportado: {$item->entityType}.{$item->operation}",
                ],
            };
        }

        $counts = ['success' => 0, 'conflict' => 0, 'error' => 0];

        foreach ($items as $i => $item) {
            $result = $results[$i];
            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;

            SyncOperation::query()->create([
                'batch_id' => $batch->id,
                'client_uuid' => $item->clientUuid,
                'entity_type' => $item->entityType,
                'entity_uuid' => $item->entityUuid,
                'operation' => $item->operation,
                'client_timestamp' => $item->clientTimestamp,
                'payload' => $item->payload,
                'status' => $result['status'],
                'server_uuid' => $result['data']['uuid'] ?? null,
                'response' => $result,
                'error_message' => $result['error'] ?? null,
            ]);
        }

        $batch->update([
            'success_count' => $counts['success'] ?? 0,
            'conflict_count' => $counts['conflict'] ?? 0,
            'error_count' => $counts['error'] ?? 0,
            'status' => SyncBatch::STATUS_COMPLETED,
            'completed_at' => now(),
            'response_payload' => $results,
        ]);

        if ($deviceId !== null) {
            SyncDevice::query()->where('device_id', $deviceId)->update([
                'last_sync_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        return $results;
    }

    /** @return array{client_uuid: string, status: string, data?: mixed, error?: string} */
    private function processSaleCreate(SyncBatchItem $item, User $user, ?string $deviceId = null): array
    {
        try {
            // ADR-0009 paso 3: el device_id viaja a nivel batch (contrato
            // 38.3), no por item. Se inyecta al payload para que checkout
            // valide el number_value del cliente contra el rango reservado
            // del dispositivo. Sin device_id el checkout usa el generador
            // central (comportamiento previo intacto).
            $payload = $item->payload;
            if ($deviceId !== null && ! isset($payload['device_id'])) {
                $payload['device_id'] = $deviceId;
            }
            $dto = CheckoutRequest::fromArray($payload);
            $sale = $this->sales->checkout($dto, $user);

            return [
                'client_uuid' => $item->clientUuid,
                'entity_uuid' => $item->entityUuid,
                'status' => 'success',
                'data' => [
                    'uuid' => $sale->uuid,
                    // Contrato 38.3 linea 7062: el cliente espera folio_server para
                    // actualizar su entidad local. Sale no tiene atributo folio
                    // (sus campos son number/series/number_value); $sale->folio
                    // devolvia null desde el epic Sync sin que ningun test lo
                    // asertara. Se conserva la clave 'folio' del contrato.
                    'folio' => $sale->number,
                ],
            ];
        } catch (PaymentMismatchException $e) {
            return ['client_uuid' => $item->clientUuid, 'status' => 'conflict', 'error' => $e->getMessage()];
        } catch (InsufficientCreditException $e) {
            return ['client_uuid' => $item->clientUuid, 'status' => 'conflict', 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            return ['client_uuid' => $item->clientUuid, 'status' => 'error', 'error' => $e->getMessage()];
        }
    }
}
