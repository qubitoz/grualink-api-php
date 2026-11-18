<?php

declare(strict_types=1);

namespace Grualink;

/**
 * What to answer to one webhook delivery.
 */
final class WebhookHandlerResult
{
    /**
     * @param int                  $status HTTP status to answer with.
     * @param array<string, mixed> $body   JSON body to answer with.
     * @param string               $reason Internal detail for your logs; never send it back to the caller.
     *                                     A signature verification reason, or one of: invalid_payload,
     *                                     delivery_id_mismatch, duplicate, handler_failed.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly string $reason,
    ) {
    }
}
