<?php

declare(strict_types=1);

namespace Grualink\Tests;

use Grualink\GrualinkApiError;
use Grualink\GrualinkAuthenticationError;
use Grualink\GrualinkClient;
use Grualink\GrualinkConfigError;
use Grualink\GrualinkConnectionError;
use Grualink\GrualinkError;
use Grualink\GrualinkIdempotencyConflictError;
use Grualink\GrualinkInvalidRequestError;
use Grualink\GrualinkNotFoundError;
use Grualink\GrualinkPermissionError;
use Grualink\GrualinkRateLimitError;
use Grualink\GrualinkServerError;
use Grualink\GrualinkTimeoutError;
use Grualink\GrualinkValidationError;
use Grualink\RetryInfo;
use Grualink\Tests\Support\FakeApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    // Obviously fake: it only has the prefix the client checks.
    private const TEST_KEY = 'glk_test_FAKE_KEY_FOR_UNIT_TESTS_ONLY';

    private const INPUT = [
        'external_ref' => 'TEST-CONFIRMAR-0001',
        'contact' => ['name' => 'Persona de Ejemplo', 'phone' => '+525500000000'],
        'pickup' => ['address' => 'Calle Ficticia 123, Ciudad Ejemplo', 'lat' => 20.6767, 'lng' => -103.3475],
        'destination' => ['address' => 'Taller Ficticio, Avenida Ejemplo 456', 'lat' => 20.7214, 'lng' => -103.3918],
    ];

    private static FakeApi $api;

    /** @var \ArrayObject<int, int> */
    private \ArrayObject $sleeps;

    /** @var \ArrayObject<int, RetryInfo> */
    private \ArrayObject $retries;

    public static function setUpBeforeClass(): void
    {
        self::$api = FakeApi::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$api->stop();
    }

    protected function setUp(): void
    {
        // Call arguments in stack traces are what a leaked key would hide in: make sure they are recorded.
        ini_set('zend.exception_ignore_args', '0');
        self::$api->reset();
        $this->sleeps = new \ArrayObject();
        $this->retries = new \ArrayObject();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function client(array $overrides = []): GrualinkClient
    {
        $sleeps = $this->sleeps;
        $retries = $this->retries;
        $options = $overrides + [
            'apiKey' => self::TEST_KEY,
            'baseUrl' => self::$api->baseUrl,
            'sleep' => static function (int $milliseconds) use ($sleeps): void {
                $sleeps->append($milliseconds);
            },
            'random' => static fn (): float => 0.5,
            'onRetry' => static function (RetryInfo $info) use ($retries): void {
                $retries->append($info);
            },
        ];

        return new GrualinkClient(...$options);
    }

    private static function rejection(callable $call): GrualinkError
    {
        try {
            $call();
        } catch (GrualinkError $error) {
            return $error;
        }
        self::fail('expected the call to fail');
    }

    // ── Success ──────────────────────────────────────────────────────────────────────────────────────

    public function testCreateServiceRequestSendsTheKeyTheIdempotencyKeyAndTheJsonBody(): void
    {
        self::$api->reply(['status' => 201, 'body' => FakeApi::SERVICE_REQUEST]);
        $result = $this->client()->createServiceRequest(self::INPUT, idempotencyKey: 'order-0001');

        self::assertSame(FakeApi::SERVICE_REQUEST['id'], $result->serviceRequest['id']);
        self::assertSame(1002.62, $result->serviceRequest['quote']['estimated_price']['total']);
        self::assertFalse($result->replayed);
        self::assertSame('order-0001', $result->idempotencyKey);

        $requests = self::$api->requests();
        self::assertCount(1, $requests);
        $sent = $requests[0];
        self::assertSame('POST', $sent['method']);
        self::assertSame('/api/v1/service-requests', $sent['path']);
        self::assertSame('Bearer ' . self::TEST_KEY, $sent['headers']['authorization']);
        self::assertSame('order-0001', $sent['headers']['idempotency-key']);
        self::assertSame('application/json', $sent['headers']['content-type']);
        self::assertSame(self::INPUT, json_decode($sent['body'], true));
        self::assertStringContainsString('"address":"Taller Ficticio, Avenida Ejemplo 456"', $sent['body']);
    }

    public function testCreateServiceRequestGeneratesAnIdempotencyKeyWhenNoneIsGiven(): void
    {
        self::$api->reply(['status' => 201, 'body' => FakeApi::SERVICE_REQUEST]);
        $result = $this->client()->createServiceRequest(self::INPUT);

        self::assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $result->idempotencyKey,
        );
        self::assertSame($result->idempotencyKey, self::$api->requests()[0]['headers']['idempotency-key']);
    }

    public function testARepeatedRequestIsA200WithIdempotentReplayedAndIsReportedAsReplayed(): void
    {
        self::$api->reply(
            ['status' => 201, 'body' => FakeApi::SERVICE_REQUEST],
            ['status' => 200, 'body' => FakeApi::SERVICE_REQUEST, 'headers' => ['Idempotent-Replayed' => 'true']],
        );
        $client = $this->client();
        $first = $client->createServiceRequest(self::INPUT, idempotencyKey: 'order-0002');
        $second = $client->createServiceRequest(self::INPUT, idempotencyKey: 'order-0002');

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed);
        self::assertSame($first->serviceRequest, $second->serviceRequest);
        $requests = self::$api->requests();
        self::assertSame($requests[0]['body'], $requests[1]['body'], 'the same bytes are sent both times');
    }

    public function testAPlain200WithoutTheReplayHeaderIsNotReportedAsReplayed(): void
    {
        self::$api->reply(['status' => 200, 'body' => FakeApi::SERVICE_REQUEST]);
        self::assertFalse($this->client()->createServiceRequest(self::INPUT)->replayed);
    }

    public function testGetServiceRequestAndFindServiceRequestsByExternalRef(): void
    {
        $id = FakeApi::SERVICE_REQUEST['id'];
        self::$api->reply(['status' => 200, 'body' => FakeApi::SERVICE_REQUEST]);
        $one = $this->client()->getServiceRequest($id);
        self::assertSame('quoted', $one['status']);

        self::$api->reply(['status' => 200, 'body' => ['object' => 'list', 'data' => [FakeApi::SERVICE_REQUEST]]]);
        $list = $this->client()->findServiceRequestsByExternalRef('SIN 2026/001 ñ');
        self::assertCount(1, $list);

        $requests = self::$api->requests();
        self::assertSame('GET', $requests[0]['method']);
        self::assertSame('/api/v1/service-requests/' . $id, $requests[0]['path']);
        self::assertSame('/api/v1/service-requests?external_ref=SIN+2026%2F001+%C3%B1', $requests[1]['path']);
    }

    public function testHealthIsSentWithoutTheKey(): void
    {
        self::$api->reply(['status' => 200, 'body' => ['status' => 'ok', 'version' => 'v1']]);

        self::assertSame(['status' => 'ok', 'version' => 'v1'], $this->client()->health());
        $sent = self::$api->requests()[0];
        self::assertSame('/api/v1/health', $sent['path']);
        self::assertArrayNotHasKey('authorization', $sent['headers']);
    }

    public function testModeComesFromTheKeyPrefix(): void
    {
        self::assertSame('test', $this->client()->mode());
        self::assertSame('live', $this->client(['apiKey' => 'glk_live_FAKE_KEY_FOR_UNIT_TESTS_ONLY'])->mode());
    }

    // ── Every documented error ───────────────────────────────────────────────────────────────────────

    /**
     * @return iterable<string, array{int, string, class-string<GrualinkApiError>}>
     */
    public static function errorTable(): iterable
    {
        $table = [
            [400, 'invalid_request', GrualinkInvalidRequestError::class],
            [400, 'invalid_json', GrualinkInvalidRequestError::class],
            [400, 'key_in_query', GrualinkInvalidRequestError::class],
            [400, 'idempotency_key_required', GrualinkInvalidRequestError::class],
            [400, 'invalid_idempotency_key', GrualinkInvalidRequestError::class],
            [401, 'unauthorized', GrualinkAuthenticationError::class],
            [403, 'insufficient_scope', GrualinkPermissionError::class],
            [403, 'customer_unavailable', GrualinkPermissionError::class],
            [403, 'live_mode_unavailable', GrualinkPermissionError::class],
            [403, 'browser_not_allowed', GrualinkPermissionError::class],
            [404, 'not_found', GrualinkNotFoundError::class],
            [405, 'method_not_allowed', GrualinkInvalidRequestError::class],
            [409, 'idempotency_conflict', GrualinkIdempotencyConflictError::class],
            [413, 'payload_too_large', GrualinkInvalidRequestError::class],
            [415, 'unsupported_media_type', GrualinkInvalidRequestError::class],
            [422, 'validation_error', GrualinkValidationError::class],
            [429, 'too_many_open_requests', GrualinkRateLimitError::class],
        ];
        foreach ($table as $row) {
            yield $row[0] . ' ' . $row[1] => $row;
        }
    }

    /**
     * @param class-string<GrualinkApiError> $class
     */
    #[DataProvider('errorTable')]
    public function testEachErrorOfTheTableBecomesItsClassAndIsNotRetried(
        int $status,
        string $code,
        string $class,
    ): void {
        self::$api->reply(FakeApi::error($status, $code, 'Mensaje de ejemplo.'));
        $error = self::rejection(fn () => $this->client()->createServiceRequest(self::INPUT));

        self::assertSame($class, $error::class);
        self::assertInstanceOf(GrualinkApiError::class, $error);
        self::assertSame($status, $error->status);
        self::assertSame($code, $error->getCode());
        self::assertSame('Mensaje de ejemplo.', $error->getMessage());
        self::assertSame(1, $error->attempts);
        self::assertCount(1, self::$api->requests());
        self::assertSame([], $this->sleeps->getArrayCopy());
    }

    public function test422ExposesTheInvalidFieldsInDetails(): void
    {
        $details = [
            ['field' => 'contact.phone', 'issue' => 'invalid_format'],
            ['field' => 'pickup', 'issue' => 'invalid_value'],
        ];
        self::$api->reply(FakeApi::error(422, 'validation_error', 'Hay campos inválidos.', $details));
        $error = self::rejection(fn () => $this->client()->createServiceRequest(self::INPUT));

        self::assertInstanceOf(GrualinkValidationError::class, $error);
        self::assertSame($details, $error->details);
    }

    public function testAnErrorBodyThatIsNotJsonBecomesUnexpectedResponseWithoutEchoingTheBody(): void
    {
        self::$api->reply(['status' => 404, 'body' => '<html>proxy page</html>']);
        $error = self::rejection(fn () => $this->client()->getServiceRequest('abc'));

        self::assertInstanceOf(GrualinkNotFoundError::class, $error);
        self::assertSame('unexpected_response', $error->getCode());
        self::assertStringNotContainsString('proxy page', $error->getMessage());
    }

    public function testASuccessfulAnswerThatIsNotJsonIsAnUnexpectedResponse(): void
    {
        self::$api->reply(['status' => 200, 'body' => '<html>proxy page</html>']);
        $error = self::rejection(fn () => $this->client()->getServiceRequest('abc'));

        self::assertSame(GrualinkApiError::class, $error::class);
        self::assertSame('unexpected_response', $error->getCode());
        self::assertCount(1, self::$api->requests());
    }

    public function testARedirectIsNotFollowedAndTheKeyDoesNotTravelToTheNewAddress(): void
    {
        self::$api->reply(['status' => 302, 'headers' => ['Location' => self::$api->baseUrl . '/elsewhere']]);
        $error = self::rejection(fn () => $this->client()->getServiceRequest(FakeApi::SERVICE_REQUEST['id']));

        self::assertSame(GrualinkApiError::class, $error::class);
        self::assertSame(302, $error->status);
        self::assertCount(1, self::$api->requests());
    }

    // ── Retries ──────────────────────────────────────────────────────────────────────────────────────

    public function test429RateLimitedWaitsAtLeastRetryAfterAndRetriesWithTheSameKeyAndBody(): void
    {
        self::$api->reply(
            FakeApi::error(429, 'rate_limited', 'Demasiadas peticiones.', null, ['Retry-After' => '7']),
            ['status' => 201, 'body' => FakeApi::SERVICE_REQUEST],
        );
        $result = $this->client()->createServiceRequest(self::INPUT, idempotencyKey: 'order-0003');

        self::assertSame(FakeApi::SERVICE_REQUEST['id'], $result->serviceRequest['id']);
        $requests = self::$api->requests();
        self::assertCount(2, $requests);
        // 7 s from the header plus half (random = 0.5) of the first backoff step (500 ms).
        self::assertSame([7250], $this->sleeps->getArrayCopy());
        self::assertSame(1, $this->retries[0]->attempt);
        self::assertSame(7250, $this->retries[0]->delayMs);
        self::assertInstanceOf(GrualinkRateLimitError::class, $this->retries[0]->error);
        self::assertSame(7, $this->retries[0]->error->retryAfterSeconds);
        self::assertSame('order-0003', $requests[0]['headers']['idempotency-key']);
        self::assertSame('order-0003', $requests[1]['headers']['idempotency-key']);
        self::assertSame($requests[0]['body'], $requests[1]['body']);
    }

    public function testRetryAfterIsCappedAtSixtySeconds(): void
    {
        self::$api->reply(
            FakeApi::error(429, 'rate_limited', 'Demasiadas peticiones.', null, ['Retry-After' => '3600']),
            ['status' => 200, 'body' => FakeApi::SERVICE_REQUEST],
        );
        $this->client()->getServiceRequest(FakeApi::SERVICE_REQUEST['id']);

        self::assertSame([60250], $this->sleeps->getArrayCopy());
    }

    public function test5xxIsRetriedWithExponentialBackoffAndJitterThenSucceeds(): void
    {
        self::$api->reply(
            FakeApi::error(500, 'internal_error'),
            ['status' => 502, 'body' => '<html>bad gateway</html>'],
            FakeApi::error(503, 'internal_error'),
            ['status' => 201, 'body' => FakeApi::SERVICE_REQUEST],
        );
        $result = $this->client()->createServiceRequest(self::INPUT);

        self::assertSame(FakeApi::SERVICE_REQUEST['id'], $result->serviceRequest['id']);
        $requests = self::$api->requests();
        self::assertCount(4, $requests);
        // random = 0.5 over ceilings of 500, 1000 and 2000 ms.
        self::assertSame([250, 500, 1000], $this->sleeps->getArrayCopy());
        $keys = array_map(static fn (array $request): string => $request['headers']['idempotency-key'], $requests);
        self::assertCount(1, array_unique($keys));
        self::assertCount(1, array_unique(array_column($requests, 'body')));
    }

    public function testTheBackoffIsCappedAndTheJitterComesFromTheInjectedRandomSource(): void
    {
        self::$api->reply(FakeApi::error(500, 'internal_error'));
        $client = $this->client(['maxRetries' => 6, 'random' => static fn (): float => 1.0]);
        $error = self::rejection(fn () => $client->getServiceRequest(FakeApi::SERVICE_REQUEST['id']));

        self::assertInstanceOf(GrualinkServerError::class, $error);
        self::assertSame([500, 1000, 2000, 4000, 8000, 8000], $this->sleeps->getArrayCopy());
    }

    public function testItGivesUpAfterMaxRetriesAndReportsHowManyAttemptsWereMade(): void
    {
        self::$api->reply(FakeApi::error(500, 'internal_error', 'Error interno.'));
        $client = $this->client(['maxRetries' => 2]);
        $error = self::rejection(fn () => $client->createServiceRequest(self::INPUT));

        self::assertInstanceOf(GrualinkServerError::class, $error);
        self::assertSame('internal_error', $error->getCode());
        self::assertSame(3, $error->attempts);
        self::assertCount(3, self::$api->requests());
        self::assertCount(2, $this->sleeps);
    }

    public function testMaxRetriesZeroDisablesRetries(): void
    {
        self::$api->reply(FakeApi::error(500, 'internal_error'));
        $client = $this->client(['maxRetries' => 0]);
        $error = self::rejection(fn () => $client->getServiceRequest(FakeApi::SERVICE_REQUEST['id']));

        self::assertSame(1, $error->attempts);
        self::assertCount(1, self::$api->requests());
    }

    public function testANetworkErrorIsRetriedAndSurfacesAsGrualinkConnectionError(): void
    {
        $id = FakeApi::SERVICE_REQUEST['id'];
        self::$api->reply(['status' => 0, 'destroy' => true], ['status' => 200, 'body' => FakeApi::SERVICE_REQUEST]);
        $found = $this->client()->getServiceRequest($id);
        self::assertSame($id, $found['id']);
        self::assertInstanceOf(GrualinkConnectionError::class, $this->retries[0]->error);

        self::$api->reply(['status' => 0, 'destroy' => true]);
        $client = $this->client(['maxRetries' => 1]);
        $error = self::rejection(fn () => $client->getServiceRequest($id));
        self::assertInstanceOf(GrualinkConnectionError::class, $error);
        self::assertSame(2, $error->attempts);
    }

    public function testASlowAnswerHitsTheTimeoutIsRetriedAndSurfacesAsGrualinkTimeoutError(): void
    {
        self::$api->reply(['status' => 200, 'body' => FakeApi::SERVICE_REQUEST, 'delayMs' => 400]);
        $client = $this->client(['timeoutMs' => 50, 'maxRetries' => 1]);
        $error = self::rejection(fn () => $client->getServiceRequest(FakeApi::SERVICE_REQUEST['id']));

        self::assertInstanceOf(GrualinkTimeoutError::class, $error);
        self::assertSame(2, $error->attempts);
        self::assertCount(2, self::$api->requests());
    }

    // ── Local validation: nothing is sent ────────────────────────────────────────────────────────────

    public function testInvalidConfigurationAndArgumentsFailBeforeAnyRequest(): void
    {
        $invalidOptions = [
            ['apiKey' => ''],
            ['apiKey' => 'not-a-key'],
            ['baseUrl' => 'http://example.com/api/v1'],
            ['baseUrl' => 'https://example.com/api/v1?key=' . self::TEST_KEY],
            ['baseUrl' => 'not a url'],
            ['timeoutMs' => 0],
            ['maxRetries' => -1],
        ];
        foreach ($invalidOptions as $options) {
            $error = self::rejection(fn () => $this->client($options));
            self::assertInstanceOf(GrualinkConfigError::class, $error, (string) json_encode(array_keys($options)));
        }

        $client = $this->client();
        foreach (['', 'with space', 'ñ', str_repeat('x', 256), "line\nbreak"] as $idempotencyKey) {
            $error = self::rejection(fn () => $client->createServiceRequest(self::INPUT, $idempotencyKey));
            self::assertInstanceOf(GrualinkConfigError::class, $error);
        }
        $tooLarge = self::INPUT + ['notes' => str_repeat('x', 17 * 1024)];
        $invalidCalls = [
            fn () => $client->createServiceRequest($tooLarge),
            fn () => $client->createServiceRequest([]),
            fn () => $client->createServiceRequest(['a', 'b']),
            fn () => $client->getServiceRequest(' '),
            fn () => $client->findServiceRequestsByExternalRef(''),
        ];
        foreach ($invalidCalls as $call) {
            self::assertInstanceOf(GrualinkConfigError::class, self::rejection($call));
        }
        self::assertCount(0, self::$api->requests());
    }

    public function testABadKeyIsNeverEchoedByTheConfigurationError(): void
    {
        $almost = 'glk_prod_FAKE_BUT_SHOULD_NOT_BE_ECHOED';
        $error = self::rejection(static fn () => new GrualinkClient($almost));

        self::assertInstanceOf(GrualinkConfigError::class, $error);
        self::assertSame([], self::framesWithArguments($error), 'the error carries call arguments');
        $printed = print_r($error, true) . var_export($error, true) . $error . json_encode($error->getTrace());
        self::assertFalse(str_contains($printed, $almost), 'the rejected key was echoed');
        self::assertFalse(str_contains($printed, substr($almost, 0, 12)), 'part of the rejected key was echoed');
    }

    public function testCallArgumentsAreRecordedInStackTracesDuringTheseTests(): void
    {
        // The canary of the two tests around it: an ordinary exception does carry the arguments of the
        // calls that led to it, so "no arguments in our errors" is a property of the library and not of
        // the PHP configuration of this run.
        $thrower = static function (string $secret): \RuntimeException {
            return new \RuntimeException('canary');
        };
        $canary = $thrower('canary-argument');

        self::assertNotSame([], self::framesWithArguments($canary));
        self::assertStringContainsString('canary-argument', (string) json_encode($canary->getTrace()[0]['args']));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function framesWithArguments(\Throwable $error): array
    {
        return array_values(array_filter(
            $error->getTrace(),
            static fn (array $frame): bool => array_key_exists('args', $frame),
        ));
    }

    // ── The key never leaks ──────────────────────────────────────────────────────────────────────────

    public function testTheApiKeyNeverAppearsInErrorsLogsOrTheClientItself(): void
    {
        $id = FakeApi::SERVICE_REQUEST['id'];
        /** @var list<GrualinkError> $errors */
        $errors = [];

        ob_start();
        try {
            $client = $this->client([
                'maxRetries' => 1,
                'onRetry' => static function (RetryInfo $info): void {
                    echo 'retrying ';
                    print_r($info);
                    var_dump($info);
                },
            ]);
            var_dump($client);
            print_r($client);
            var_export($client);
            print_r((array) $client);
            echo json_encode($client), "\n";
            try {
                echo serialize($client);
            } catch (\Throwable $notSerializable) {
                echo $notSerializable, "\n";
            }

            // A server that (wrongly) echoes the Authorization header back in every field of the error.
            $echo = '{{authorization}}';
            foreach ([400, 401, 403, 404, 409, 422, 429, 500] as $status) {
                self::$api->reply(FakeApi::error(
                    $status,
                    'code ' . $echo,
                    'message ' . $echo,
                    [['field' => 'field ' . $echo, 'issue' => 'issue ' . $echo]],
                ));
                $errors[] = self::rejection(fn () => $client->createServiceRequest(self::INPUT));
            }
            self::$api->reply(['status' => 0, 'destroy' => true]);
            $errors[] = self::rejection(fn () => $client->getServiceRequest($id));
            self::$api->reply(['status' => 200, 'body' => FakeApi::SERVICE_REQUEST, 'delayMs' => 300]);
            $impatient = $this->client(['timeoutMs' => 30, 'maxRetries' => 0]);
            $errors[] = self::rejection(fn () => $impatient->getServiceRequest($id));
            // A transport that throws an error mentioning the key.
            $leaky = $this->client([
                'maxRetries' => 0,
                'transport' => static function (string $method, string $url, array $headers): array {
                    throw new \RuntimeException('connect failed for ' . $headers['Authorization']);
                },
            ]);
            $errors[] = self::rejection(fn () => $leaky->getServiceRequest($id));

            foreach ($errors as $error) {
                // Checked before printing: with arguments in the trace, the dumps below would walk the
                // whole test runner.
                self::assertSame([], self::framesWithArguments($error), 'the error carries call arguments');
                echo $error, "\n", $error->getMessage(), "\n", $error->getTraceAsString(), "\n";
                print_r($error);
                var_dump($error);
                var_export($error);
                echo json_encode($error), json_encode($error->getTrace()), "\n";
            }
        } finally {
            $written = (string) ob_get_clean();
        }

        self::assertCount(11, $errors);
        $sentKeys = array_map(
            static fn (array $request): ?string => $request['headers']['authorization'] ?? null,
            self::$api->requests(),
        );
        self::assertContains('Bearer ' . self::TEST_KEY, $sentKeys, 'the key was really sent');
        self::assertGreaterThan(5000, strlen($written), 'the output was really captured');
        // str_contains instead of assertStringNotContainsString: a failure must not print the captured output.
        self::assertFalse(str_contains($written, self::TEST_KEY), 'the API key leaked');
        self::assertFalse(str_contains($written, substr(self::TEST_KEY, 9)), 'part of the API key leaked');
        self::assertFalse(str_contains($written, substr(self::TEST_KEY, 0, 15)), 'the start of the key leaked');
        self::assertTrue(str_contains($written, '[REDACTED]'), 'echoed keys are replaced by a marker');
        self::assertSame('code Bearer [REDACTED]', $errors[0]->getCode());
        self::assertSame('field Bearer [REDACTED]', $errors[5]->details[0]['field']);
        self::assertStringContainsString('connect failed for Bearer [REDACTED]', $errors[10]->getMessage());
    }
}
