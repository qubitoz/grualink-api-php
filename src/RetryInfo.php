<?php

declare(strict_types=1);

namespace Grualink;

/**
 * What the client passes to `onRetry` before each wait.
 */
final class RetryInfo
{
    /**
     * @param int           $attempt Attempt that just failed, starting at 1.
     * @param int           $delayMs Time the client will wait before the next attempt.
     * @param GrualinkError $error   Why it failed. Never contains the API key.
     */
    public function __construct(
        public readonly int $attempt,
        public readonly int $delayMs,
        public readonly GrualinkError $error,
    ) {
    }
}
