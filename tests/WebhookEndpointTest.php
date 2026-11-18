<?php

declare(strict_types=1);

namespace Grualink\Tests;

use Grualink\CurlTransport;
use PHPUnit\Framework\TestCase;

use function Grualink\buildSignatureHeader;

/**
 * The receiver over real HTTP: `serveWebhook()` behind the built-in web server of PHP, one process per
 * request, as in production.
 */
final class WebhookEndpointTest extends TestCase
{
    private const SECRET = 'whsec_TEST_ONLY_FAKE_SECRET_0001';
    private const PREVIOUS = 'whsec_TEST_ONLY_FAKE_SECRET_0000';
    private const NOW = 1795000000;

    /** @var resource */
    private static $server;

    private static string $stateDir;

    private static string $url;

    private static int $counter = 0;

    public static function setUpBeforeClass(): void
    {
        self::$stateDir = sys_get_temp_dir() . '/grualink-endpoint-' . bin2hex(random_bytes(6));
        mkdir(self::$stateDir, 0700, true);

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/Support/webhook-endpoint.php'],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', self::$stateDir . '/server.log', 'a'],
                2 => ['file', self::$stateDir . '/server.log', 'a'],
            ],
            $pipes,
            null,
            ['GRUALINK_TEST_STATE_DIR' => self::$stateDir] + getenv(),
        );
        self::assertIsResource($server);
        self::$server = $server;
        self::$url = 'http://127.0.0.1:' . $port . '/webhooks/grualink';

        for ($try = 0; $try < 100; $try++) {
            $connection = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.2);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(50000);
        }
        self::fail('The built-in web server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$server);
        proc_close(self::$server);
        foreach ([self::$stateDir . '/deliveries', self::$stateDir] as $directory) {
            array_map('unlink', array_filter((array) glob($directory . '/*'), 'is_file'));
            @rmdir($directory);
        }
    }

    protected function setUp(): void
    {
        foreach (['events.jsonl', 'results.jsonl', 'fail-next'] as $file) {
            @unlink(self::$stateDir . '/' . $file);
        }
        $this->setClock(self::NOW);
    }

    private function setClock(int $now): void
    {
        file_put_contents(self::$stateDir . '/now', (string) $now);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function lines(string $file): array
    {
        $path = self::$stateDir . '/' . $file;
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

        return array_map(static fn (string $line): array => json_decode($line, true), $lines ?: []);
    }

    /**
     * @return list<string>
     */
    private static function reasons(): array
    {
        return array_column(self::lines('results.jsonl'), 'reason');
    }

    /**
     * @return array{id: string, type: string, body: string}
     */
    private static function delivery(): array
    {
        $id = sprintf('bbbbbbbb-0000-4000-8000-%012d', ++self::$counter);
        $type = 'service_request.confirmed';
        // Deliberately not what json_encode would produce, and with non-ASCII text.
        $body = '{"id": "' . $id . '", "data": {"previous_status": "quoted", "service_request": {"id": '
            . '"11111111-2222-4333-8444-555555555555", "status": "confirmed", "external_ref": '
            . '"TEST-CONFIRMAR-ÑU"}}, "mode": "test", "type": "' . $type . '", "object": "event", '
            . '"created_at": "2026-11-18T10:05:01.000Z"}';

        return ['id' => $id, 'type' => $type, 'body' => $body];
    }

    /**
     * @param array{id: string, type: string, body: string} $delivery
     * @param list<string>                                  $secrets
     *
     * @return array<string, string>
     */
    private static function signedHeaders(array $delivery, array $secrets = [self::SECRET], int $t = self::NOW): array
    {
        return [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-GruaLink-Event' => $delivery['type'],
            'X-GruaLink-Delivery' => $delivery['id'],
            'X-GruaLink-Timestamp' => (string) $t,
            'X-GruaLink-Mode' => 'test',
            'X-GruaLink-Signature' => buildSignatureHeader($secrets, $t, $delivery['body']),
        ];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, json: mixed}
     */
    private static function send(string $body, array $headers, string $method = 'POST', ?string $url = null): array
    {
        $payload = $method === 'POST' ? $body : null;
        $response = (new CurlTransport())($method, $url ?? self::$url, $headers, $payload, 10000);

        return [
            'status' => $response['status'],
            'headers' => $response['headers'],
            'json' => json_decode($response['body'], true),
        ];
    }

    public function testASignedDeliveryIsAcceptedAndHandedToOnEvent(): void
    {
        $delivery = self::delivery();
        $response = self::send($delivery['body'], self::signedHeaders($delivery));

        self::assertSame(200, $response['status']);
        self::assertSame(['received' => true], $response['json']);
        self::assertStringStartsWith('application/json', $response['headers']['content-type']);
        $events = self::lines('events.jsonl');
        self::assertCount(1, $events);
        self::assertSame($delivery['id'], $events[0]['id']);
        self::assertSame('TEST-CONFIRMAR-ÑU', $events[0]['data']['service_request']['external_ref']);
        self::assertSame(['ok'], self::reasons());
    }

    public function testARetryOfTheSameDeliveryAnswers200ButIsProcessedOnce(): void
    {
        $delivery = self::delivery();
        self::assertSame(200, self::send($delivery['body'], self::signedHeaders($delivery))['status']);

        // The retry is signed again, one minute later, and reaches a brand-new PHP process.
        $again = self::send($delivery['body'], self::signedHeaders($delivery, [self::SECRET], self::NOW + 60));
        self::assertSame(200, $again['status']);
        self::assertSame(['received' => true, 'duplicate' => true], $again['json']);
        self::assertCount(1, self::lines('events.jsonl'));
        self::assertSame(['ok', 'duplicate'], self::reasons());
    }

    public function testWhenOnEventFailsTheAnswerIs500AndTheRetryIsProcessed(): void
    {
        $delivery = self::delivery();
        touch(self::$stateDir . '/fail-next');

        $first = self::send($delivery['body'], self::signedHeaders($delivery));
        self::assertSame(500, $first['status']);
        self::assertCount(0, self::lines('events.jsonl'));

        $retry = self::send($delivery['body'], self::signedHeaders($delivery));
        self::assertSame(200, $retry['status']);
        self::assertSame(['received' => true], $retry['json']);
        self::assertCount(1, self::lines('events.jsonl'));
        self::assertSame(['handler_failed', 'ok'], self::reasons());
    }

    public function testDuringARotationADeliverySignedWithBothSecretsIsAccepted(): void
    {
        $delivery = self::delivery();
        $both = self::signedHeaders($delivery, ['whsec_TEST_ONLY_FAKE_SECRET_NEXT', self::SECRET]);
        self::assertSame(200, self::send($delivery['body'], $both)['status']);

        $old = self::delivery();
        self::assertSame(401, self::send($old['body'], self::signedHeaders($old, [self::PREVIOUS]))['status']);
    }

    public function testATamperedBodyAMissingSignatureAndAStaleTimestampAreRejectedWith401(): void
    {
        $delivery = self::delivery();
        $headers = self::signedHeaders($delivery);

        $tampered = self::send(str_replace('confirmed', 'completed', $delivery['body']), $headers);
        self::assertSame(401, $tampered['status']);
        self::assertSame(['received' => false, 'error' => 'invalid_signature'], $tampered['json']);

        $unsigned = $headers;
        unset($unsigned['X-GruaLink-Signature']);
        self::assertSame(401, self::send($delivery['body'], $unsigned)['status']);

        $this->setClock(self::NOW + 301);
        $stale = self::send($delivery['body'], $headers);
        self::assertSame(401, $stale['status']);
        self::assertSame(['received' => false, 'error' => 'invalid_signature'], $stale['json']);

        self::assertSame(['signature_mismatch', 'missing_header', 'timestamp_out_of_tolerance'], self::reasons());
        self::assertCount(0, self::lines('events.jsonl'));
    }

    public function testTheBodyParsedAndSerializedAgainDoesNotVerify(): void
    {
        $delivery = self::delivery();
        $reserialized = (string) json_encode(json_decode($delivery['body'], true), JSON_UNESCAPED_UNICODE);

        self::assertNotSame($delivery['body'], $reserialized);
        self::assertSame(401, self::send($reserialized, self::signedHeaders($delivery))['status']);
    }

    public function testADeliveryHeaderThatDisagreesWithTheSignedIdIsRejected(): void
    {
        $delivery = self::delivery();
        $headers = ['X-GruaLink-Delivery' => 'cccccccc-0000-4000-8000-000000000000'] + self::signedHeaders($delivery);
        $response = self::send($delivery['body'], $headers);

        self::assertSame(400, $response['status']);
        self::assertSame(['delivery_id_mismatch'], self::reasons());
        self::assertCount(0, self::lines('events.jsonl'));
    }

    public function testASignedBodyThatIsNotAnEventIsA400(): void
    {
        $body = '[1, 2, 3]';
        $headers = self::signedHeaders(['id' => 'x', 'type' => 'webhook.test', 'body' => $body]);

        self::assertSame(400, self::send($body, $headers)['status']);
        self::assertSame(['invalid_payload'], self::reasons());
    }

    public function testOtherMethodsOtherPathsAndOversizedBodiesAreRefused(): void
    {
        $get = self::send('', [], 'GET');
        self::assertSame(405, $get['status']);
        self::assertSame('POST', $get['headers']['allow']);

        $elsewhere = str_replace('/webhooks/grualink', '/other', self::$url);
        self::assertSame(404, self::send('{}', [], 'POST', $elsewhere)['status']);

        $big = self::send(str_repeat(' ', 64 * 1024 + 1), ['Content-Type' => 'application/json']);
        self::assertSame(413, $big['status']);
        self::assertSame(['received' => false, 'error' => 'payload_too_large'], $big['json']);

        $atTheLimit = self::send(str_repeat(' ', 64 * 1024), ['Content-Type' => 'application/json']);
        self::assertSame(401, $atTheLimit['status'], 'a body of exactly 64 KB is read (and then fails verification)');

        self::assertCount(0, self::lines('events.jsonl'));
    }
}
