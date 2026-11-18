<?php

declare(strict_types=1);

namespace Grualink;

/**
 * In-memory deduplication for a single long-running process (a queue worker, an application server that
 * keeps the script loaded). Retries of one delivery span up to 24 hours, so ids are kept for 48 hours by
 * default.
 *
 * Under the usual PHP model every request starts from scratch and this memory is lost: use a shared store
 * there (a unique key in your database is enough) or `FileDeliveryDeduplicator`.
 */
final class InMemoryDeliveryDeduplicator implements DeliveryDeduplicator
{
    /** @var array<string, int|float> Expiry by delivery id, oldest first. */
    private array $seen = [];

    private \Closure $now;

    /**
     * @param int           $ttlSeconds How long an id is remembered.
     * @param int           $maxEntries Size cap; the oldest ids are evicted beyond it.
     * @param callable|null $now        Clock in seconds, for tests.
     */
    public function __construct(
        private readonly int $ttlSeconds = 48 * 60 * 60,
        private readonly int $maxEntries = 50000,
        ?callable $now = null,
    ) {
        $this->now = $now !== null ? \Closure::fromCallable($now) : static fn (): int => time();
    }

    public function claim(string $deliveryId): bool
    {
        $now = ($this->now)();
        foreach ($this->seen as $id => $expiresAt) {
            if ($expiresAt > $now && count($this->seen) < $this->maxEntries) {
                break;
            }
            unset($this->seen[$id]);
        }

        if (isset($this->seen[$deliveryId]) && $this->seen[$deliveryId] > $now) {
            return false;
        }
        unset($this->seen[$deliveryId]);
        $this->seen[$deliveryId] = $now + $this->ttlSeconds;

        return true;
    }

    public function release(string $deliveryId): void
    {
        unset($this->seen[$deliveryId]);
    }
}
