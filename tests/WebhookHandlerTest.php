<?php

declare(strict_types=1);

namespace Grualink\Tests;

use Grualink\FileDeliveryDeduplicator;
use Grualink\InMemoryDeliveryDeduplicator;
use Grualink\WebhookHandler;
use PHPUnit\Framework\TestCase;

use function Grualink\buildSignatureHeader;
use function Grualink\createWebhookHandler;
use function Grualink\headersFromServer;

final class WebhookHandlerTest extends TestCase
{
    private const SECRET = 'whsec_TEST_ONLY_FAKE_SECRET_0001';
    private const NOW = 1795000000;
    private const ID = 'bbbbbbbb-0000-4000-8000-000000000001';
    // Deliberately not what json_encode would produce, and with non-ASCII text.
    private const BODY = '{"id": "bbbbbbbb-0000-4000-8000-000000000001", "data": {"message": "Señal de prueba"},'
        . ' "mode": "test", "type": "webhook.test", "object": "event", "created_at": "2026-11-18T10:05:01.000Z"}';

    /** @var \ArrayObject<int, array<string, mixed>> */
    private \ArrayObject $received;

    protected function setUp(): void
    {
        $this->received = new \ArrayObject();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function handler(array $overrides = []): WebhookHandler
    {
        $received = $this->received;
        $options = $overrides + [
            'secret' => self::SECRET,
            'onEvent' => static function (array $event) use ($received): void {
                $received->append($event);
            },
            'nowSeconds' => static fn (): int => self::NOW,
        ];

        return createWebhookHandler(...$options);
    }

    /**
     * @return array<string, string>
     */
    private static function headers(string $body = self::BODY, string $id = self::ID): array
    {
        return [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-GruaLink-Event' => 'webhook.test',
            'X-GruaLink-Delivery' => $id,
            'X-GruaLink-Signature' => buildSignatureHeader([self::SECRET], self::NOW, $body),
        ];
    }

    public function testAVerifiedDeliveryIsHandedToOnEventOnceAndARepetitionIsAnsweredWithoutProcessing(): void
    {
        $handler = $this->handler();

        $first = $handler->handle(self::BODY, self::headers());
        self::assertSame(200, $first->status);
        self::assertSame(['received' => true], $first->body);
        self::assertSame('ok', $first->reason);

        $again = $handler(self::BODY, self::headers());
        self::assertSame(200, $again->status);
        self::assertSame(['received' => true, 'duplicate' => true], $again->body);
        self::assertSame('duplicate', $again->reason);

        self::assertCount(1, $this->received);
        self::assertSame(self::ID, $this->received[0]['id']);
        self::assertSame('Señal de prueba', $this->received[0]['data']['message']);
    }

    public function testHeaderNamesAreMatchedInAnyCaseAndInTheShapesFrameworksUse(): void
    {
        $signature = buildSignatureHeader([self::SECRET], self::NOW, self::BODY);

        // Lowercase names with a list of values, as Symfony and Laravel expose them.
        $lists = ['x-grualink-delivery' => [self::ID], 'x-grualink-signature' => [$signature]];
        self::assertSame('ok', $this->handler()->handle(self::BODY, $lists)->reason);

        // Straight from $_SERVER.
        $server = [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GRUALINK_DELIVERY' => self::ID,
            'HTTP_X_GRUALINK_SIGNATURE' => $signature,
            'argv' => ['ignored'],
        ];
        $headers = headersFromServer($server);
        self::assertSame('application/json', $headers['content-type']);
        self::assertArrayNotHasKey('request-method', $headers);
        self::assertSame('ok', $this->handler()->handle(self::BODY, $headers)->reason);
    }

    public function testAFailedHandlerReleasesTheIdSoTheRetryIsProcessed(): void
    {
        $received = $this->received;
        $failures = new \ArrayObject([true]);
        $handler = $this->handler([
            'onEvent' => static function (array $event) use ($received, $failures): void {
                if (count($failures) > 0) {
                    $failures->exchangeArray([]);
                    throw new \RuntimeException('database is down');
                }
                $received->append($event);
            },
        ]);

        $first = $handler->handle(self::BODY, self::headers());
        self::assertSame(500, $first->status);
        self::assertSame(['received' => false, 'error' => 'handler_failed'], $first->body);
        self::assertSame('handler_failed', $first->reason);
        self::assertCount(0, $this->received);

        $retry = $handler->handle(self::BODY, self::headers());
        self::assertSame(200, $retry->status);
        self::assertSame(['received' => true], $retry->body);
        self::assertCount(1, $this->received);
    }

    public function testABadSignatureGets401WithNoDetailAndNothingIsProcessed(): void
    {
        $handler = $this->handler();
        $unsigned = self::headers();
        unset($unsigned['X-GruaLink-Signature']);
        $attempts = [
            'signature_mismatch' => [str_replace('prueba', 'verdad', self::BODY), self::headers()],
            'missing_header' => [self::BODY, $unsigned],
            'malformed_header' => [self::BODY, ['X-GruaLink-Signature' => 'v1=abc'] + self::headers()],
        ];
        foreach ($attempts as $reason => [$body, $headers]) {
            $result = $handler->handle($body, $headers);
            self::assertSame(401, $result->status);
            self::assertSame(['received' => false, 'error' => 'invalid_signature'], $result->body);
            self::assertSame($reason, $result->reason);
        }

        $late = $this->handler(['nowSeconds' => static fn (): int => self::NOW + 301]);
        self::assertSame('timestamp_out_of_tolerance', $late->handle(self::BODY, self::headers())->reason);
        $noSecret = $this->handler(['secret' => []]);
        self::assertSame('missing_secret', $noSecret->handle(self::BODY, self::headers())->reason);
        self::assertSame(401, $noSecret->handle(self::BODY, self::headers())->status);

        self::assertCount(0, $this->received);
    }

    public function testTheBodyParsedAndSerializedAgainDoesNotVerify(): void
    {
        $reserialized = (string) json_encode(json_decode(self::BODY, true), JSON_UNESCAPED_UNICODE);
        self::assertNotSame(self::BODY, $reserialized);
        self::assertSame(401, $this->handler()->handle($reserialized, self::headers())->status);
    }

    public function testDuringARotationTheReceiverAcceptsEitherOfItsSecrets(): void
    {
        $next = 'whsec_TEST_ONLY_FAKE_SECRET_NEXT';
        $both = ['X-GruaLink-Signature' => buildSignatureHeader([$next, self::SECRET], self::NOW, self::BODY)];
        self::assertSame('ok', $this->handler()->handle(self::BODY, $both + self::headers())->reason);

        $onlyNext = ['X-GruaLink-Signature' => buildSignatureHeader([$next], self::NOW, self::BODY)];
        self::assertSame(401, $this->handler()->handle(self::BODY, $onlyNext + self::headers())->status);
        $holdsBoth = $this->handler(['secret' => [$next, self::SECRET]]);
        self::assertSame('ok', $holdsBoth->handle(self::BODY, $onlyNext + self::headers())->reason);
    }

    public function testADeliveryHeaderThatDisagreesWithTheSignedIdIsRejected(): void
    {
        $headers = ['X-GruaLink-Delivery' => 'cccccccc-0000-4000-8000-000000000000'] + self::headers();
        $result = $this->handler()->handle(self::BODY, $headers);

        self::assertSame(400, $result->status);
        self::assertSame('delivery_id_mismatch', $result->reason);
        self::assertCount(0, $this->received);
    }

    public function testASignedBodyThatIsNotAnEventIsA400(): void
    {
        foreach (['[1, 2, 3]', 'not json', '{"id": 7, "type": "webhook.test"}', "\xff\xfe"] as $body) {
            $result = $this->handler()->handle($body, self::headers($body, 'x'));
            self::assertSame(400, $result->status);
            self::assertSame('invalid_payload', $result->reason);
        }
        self::assertCount(0, $this->received);
    }

    public function testThePrintedFormOfTheHandlerDoesNotShowTheSecret(): void
    {
        $printed = print_r($this->handler(), true);
        self::assertStringNotContainsString(self::SECRET, $printed);
    }

    public function testInMemoryDeliveryDeduplicatorClaimReleaseExpiryAndSizeCap(): void
    {
        $clock = new \ArrayObject(['now' => 0]);
        $now = static fn (): int => $clock['now'];
        $dedup = new InMemoryDeliveryDeduplicator(ttlSeconds: 1000, maxEntries: 3, now: $now);

        self::assertTrue($dedup->claim('a'));
        self::assertFalse($dedup->claim('a'));
        $dedup->release('a');
        self::assertTrue($dedup->claim('a'));

        $clock['now'] = 1001;
        self::assertTrue($dedup->claim('a'), 'an expired id can be claimed again');

        self::assertTrue($dedup->claim('b'));
        self::assertTrue($dedup->claim('c'));
        self::assertTrue($dedup->claim('d'), 'the oldest entry is evicted when the cap is reached');
        self::assertFalse($dedup->claim('d'));
        self::assertTrue($dedup->claim('a'), 'the evicted id is no longer remembered');
    }

    public function testFileDeliveryDeduplicatorRemembersBetweenInstances(): void
    {
        $directory = sys_get_temp_dir() . '/grualink-dedup-' . bin2hex(random_bytes(6));
        $clock = new \ArrayObject(['now' => 0]);
        $now = static fn (): int => $clock['now'];
        try {
            $dedup = new FileDeliveryDeduplicator($directory, ttlSeconds: 1000, now: $now);
            self::assertTrue($dedup->claim('a'));
            self::assertFalse($dedup->claim('a'));

            // Another request, another instance: the id is still remembered.
            $other = new FileDeliveryDeduplicator($directory, ttlSeconds: 1000, now: $now);
            self::assertFalse($other->claim('a'));
            self::assertTrue($other->claim('../../outside'), 'an id is never used as a path');
            self::assertCount(2, (array) glob($directory . '/*.delivery'));

            $other->release('a');
            self::assertTrue($dedup->claim('a'));

            self::assertSame(0, $dedup->purgeExpired());
            $clock['now'] = 1001;
            self::assertTrue($dedup->claim('a'), 'an expired id can be claimed again');
            self::assertSame(1, $dedup->purgeExpired(), 'only the expired marker is deleted');
            self::assertFalse($dedup->claim('a'));
        } finally {
            array_map('unlink', (array) glob($directory . '/*.delivery'));
            @rmdir($directory);
        }
    }
}
