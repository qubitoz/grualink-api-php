<?php

declare(strict_types=1);

namespace Grualink;

/**
 * Remembers which deliveries were already processed. Deliveries can arrive more than once.
 */
interface DeliveryDeduplicator
{
    /**
     * Returns `true` the first time an id is seen and `false` for a duplicate.
     */
    public function claim(string $deliveryId): bool;

    /**
     * Forgets an id so a later retry is processed again (call it when processing failed).
     */
    public function release(string $deliveryId): void;
}
