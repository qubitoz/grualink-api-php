<?php

declare(strict_types=1);

namespace Grualink;

/**
 * Deduplication that survives between requests on one machine: one small file per delivery id, created
 * atomically, so two simultaneous deliveries of the same event cannot both win.
 *
 * It fits a single server. With several servers, use a shared store instead (a unique key in your
 * database is enough).
 */
final class FileDeliveryDeduplicator implements DeliveryDeduplicator
{
    private \Closure $now;

    /**
     * @param string        $directory  Folder for the markers; created when missing. Keep it out of the web root.
     * @param int           $ttlSeconds How long an id is remembered (48 hours by default).
     * @param callable|null $now        Clock in seconds, for tests.
     */
    public function __construct(
        private readonly string $directory,
        private readonly int $ttlSeconds = 48 * 60 * 60,
        ?callable $now = null,
    ) {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('The deduplication folder could not be created.');
        }
        $this->now = $now !== null ? \Closure::fromCallable($now) : static fn (): int => time();
    }

    public function claim(string $deliveryId): bool
    {
        $path = $this->path($deliveryId);
        $now = ($this->now)();

        for ($try = 0; $try < 2; $try++) {
            // Mode "x" fails when the file already exists: creating it is the claim.
            $handle = @fopen($path, 'x');
            if ($handle !== false) {
                fwrite($handle, (string) ($now + $this->ttlSeconds));
                fclose($handle);

                return true;
            }

            $expiresAt = @file_get_contents($path);
            if ($expiresAt === false) {
                // It vanished in between (released or purged): try to claim it again.
                continue;
            }
            // An empty marker is being written right now by another request.
            if ($expiresAt === '' || (int) $expiresAt > $now) {
                return false;
            }
            @unlink($path);
        }

        return false;
    }

    public function release(string $deliveryId): void
    {
        @unlink($this->path($deliveryId));
    }

    /**
     * Deletes the markers that already expired. Call it now and then (a scheduled task is fine).
     *
     * @return int Number of markers deleted.
     */
    public function purgeExpired(): int
    {
        $now = ($this->now)();
        $deleted = 0;
        foreach (glob($this->directory . '/*.delivery') ?: [] as $path) {
            $expiresAt = @file_get_contents($path);
            if ($expiresAt !== false && $expiresAt !== '' && (int) $expiresAt <= $now && @unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function path(string $deliveryId): string
    {
        // The id is hashed so it can never be used to reach another path.
        return $this->directory . '/' . hash('sha256', $deliveryId) . '.delivery';
    }
}
