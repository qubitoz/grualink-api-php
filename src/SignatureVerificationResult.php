<?php

declare(strict_types=1);

namespace Grualink;

/**
 * Outcome of `verifyWebhookSignature()`.
 */
final class SignatureVerificationResult
{
    /**
     * @param bool   $valid  Whether to accept the delivery.
     * @param string $reason One of: ok, missing_secret, missing_header, malformed_header,
     *                       timestamp_out_of_tolerance, signature_mismatch.
     */
    public function __construct(
        public readonly bool $valid,
        public readonly string $reason,
    ) {
    }

    /**
     * @return array{valid: bool, reason: string}
     */
    public function toArray(): array
    {
        return ['valid' => $this->valid, 'reason' => $this->reason];
    }
}
