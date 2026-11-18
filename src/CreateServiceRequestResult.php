<?php

declare(strict_types=1);

namespace Grualink;

/**
 * Result of `GrualinkClient::createServiceRequest()`.
 */
final class CreateServiceRequestResult
{
    /**
     * @param array<string, mixed> $serviceRequest The service request, with the wire names (snake_case).
     * @param bool                 $replayed       `true` when the API answered 200 with
     *                                             `Idempotent-Replayed: true` (nothing new was created).
     * @param string               $idempotencyKey The key that was sent (yours or the generated one).
     */
    public function __construct(
        public readonly array $serviceRequest,
        public readonly bool $replayed,
        public readonly string $idempotencyKey,
    ) {
    }
}
