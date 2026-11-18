<?php

declare(strict_types=1);

namespace Grualink;

/**
 * Framework-independent handling of one delivery: verify the signature over the raw body, parse,
 * deduplicate and dispatch. Mount it on anything that can give you the raw bytes of the request.
 */
final class WebhookHandler
{
    /** @var list<string> */
    private array $secrets;

    private \Closure $onEvent;

    private DeliveryDeduplicator $deduplicator;

    private ?\Closure $nowSeconds;

    /**
     * @param string|list<string> $secret           Signing secret (`whsec_...`), or current and previous
     *                                              during your rotation.
     * @param callable            $onEvent          Called once per verified, non-duplicate delivery with the
     *                                              event as an array. Keep it fast (GRUALINK waits 10
     *                                              seconds): store the event or enqueue it and do the heavy
     *                                              work later. If it throws, the answer is 500 and the
     *                                              delivery is retried.
     * @param callable|null       $nowSeconds       Clock in seconds, for tests.
     */
    public function __construct(
        #[\SensitiveParameter]
        string|array $secret,
        callable $onEvent,
        ?DeliveryDeduplicator $deduplicator = null,
        private readonly int|float $toleranceSeconds = DEFAULT_TOLERANCE_SECONDS,
        ?callable $nowSeconds = null,
    ) {
        $this->secrets = array_values(array_filter(
            is_array($secret) ? $secret : [$secret],
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));
        $this->onEvent = \Closure::fromCallable($onEvent);
        $this->deduplicator = $deduplicator ?? new InMemoryDeliveryDeduplicator();
        $this->nowSeconds = $nowSeconds !== null ? \Closure::fromCallable($nowSeconds) : null;
    }

    /**
     * @param string                                  $rawBody The RAW body bytes, exactly as received.
     * @param array<string, string|list<string>|null> $headers Request headers; names in any case.
     */
    public function handle(string $rawBody, array $headers): WebhookHandlerResult
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $first = is_array($value) ? ($value[0] ?? null) : $value;
            if (is_string($first)) {
                $normalized[strtolower((string) $name)] = $first;
            }
        }

        $verification = verifyWebhookSignature(
            rawBody: $rawBody,
            signatureHeader: $normalized[SIGNATURE_HEADER] ?? null,
            secret: $this->secrets,
            nowSeconds: $this->nowSeconds !== null ? ($this->nowSeconds)() : null,
            toleranceSeconds: $this->toleranceSeconds,
        );
        if (!$verification->valid) {
            return new WebhookHandlerResult(
                401,
                ['received' => false, 'error' => 'invalid_signature'],
                $verification->reason,
            );
        }

        // Only now, with the signature verified, is the body parsed.
        $event = json_decode($rawBody, true);
        if (!is_array($event) || !is_string($event['id'] ?? null) || !is_string($event['type'] ?? null)) {
            return new WebhookHandlerResult(
                400,
                ['received' => false, 'error' => 'invalid_payload'],
                'invalid_payload',
            );
        }

        // The header is not covered by the signature; the `id` inside the signed body is. They must agree.
        $deliveryId = $normalized[DELIVERY_HEADER] ?? null;
        if ($deliveryId !== $event['id']) {
            return new WebhookHandlerResult(
                400,
                ['received' => false, 'error' => 'delivery_id_mismatch'],
                'delivery_id_mismatch',
            );
        }

        if (!$this->deduplicator->claim($deliveryId)) {
            return new WebhookHandlerResult(200, ['received' => true, 'duplicate' => true], 'duplicate');
        }
        try {
            ($this->onEvent)($event);
        } catch (\Throwable) {
            $this->deduplicator->release($deliveryId);

            return new WebhookHandlerResult(
                500,
                ['received' => false, 'error' => 'handler_failed'],
                'handler_failed',
            );
        }

        return new WebhookHandlerResult(200, ['received' => true], 'ok');
    }

    /**
     * @param array<string, string|list<string>|null> $headers
     */
    public function __invoke(string $rawBody, array $headers): WebhookHandlerResult
    {
        return $this->handle($rawBody, $headers);
    }

    /**
     * The printed form of the handler never shows the secret.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['secrets' => count($this->secrets), 'toleranceSeconds' => $this->toleranceSeconds];
    }
}
