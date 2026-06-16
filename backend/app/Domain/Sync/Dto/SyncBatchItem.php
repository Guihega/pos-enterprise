<?php
declare(strict_types=1);
namespace App\Domain\Sync\Dto;

/**
 * Un item de la cola de sync enviado desde el cliente PWA.
 */
final class SyncBatchItem
{
    public function __construct(
        public readonly string $clientUuid,
        public readonly string $entityType,
        public readonly string $entityUuid,
        public readonly string $operation,
        public readonly array  $payload,
        public readonly string $clientTimestamp,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            clientUuid:      (string) $data['client_uuid'],
            entityType:      (string) $data['entity_type'],
            entityUuid:      (string) $data['entity_uuid'],
            operation:       (string) $data['operation'],
            payload:         (array)  $data['payload'],
            clientTimestamp: (string) $data['client_timestamp'],
        );
    }
}
