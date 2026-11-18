<?php

declare(strict_types=1);

namespace Grualink;

/**
 * Minimal client of the GRUALINK public API: create a service request, read it, find it by your own
 * reference. Requests and answers are plain arrays with the wire names (snake_case), exactly as in the
 * API contract.
 */
final class GrualinkClient
{
    public const DEFAULT_BASE_URL = 'https://grualink.mx/api/v1';
    public const DEFAULT_TIMEOUT_MS = 15000;
    public const DEFAULT_MAX_RETRIES = 3;

    private const API_KEY_PATTERN = '/\Aglk_(test|live)_\S+\z/';
    private const IDEMPOTENCY_KEY_PATTERN = '/\A[\x21-\x7e]{1,255}\z/';
    private const MAX_BODY_BYTES = 16 * 1024;
    /** The API never asks to wait more than 60 seconds. */
    private const MAX_RETRY_AFTER_SECONDS = 60;
    private const REDACTED = '[REDACTED]';

    /**
     * The key lives outside the instance, so var_dump, print_r, var_export, an (array) cast, json_encode
     * and serialize of the client cannot show it.
     *
     * @var \WeakMap<GrualinkClient, string>|null
     */
    private static ?\WeakMap $apiKeys = null;

    private string $mode;
    private string $baseUrl;
    private int $timeoutMs;
    private int $maxRetries;
    private int $retryBaseDelayMs;
    private int $retryMaxDelayMs;
    private ?\Closure $onRetry;
    private \Closure $transport;
    private \Closure $sleep;
    private \Closure $random;

    /**
     * @param string        $apiKey           `glk_test_...` or `glk_live_...`. Read it from the environment;
     *                                        never hard-code it.
     * @param string|null   $baseUrl          Defaults to `https://grualink.mx/api/v1`.
     * @param int           $timeoutMs        Maximum time for each attempt, in milliseconds.
     * @param int           $maxRetries       Retries after the first attempt for 429 `rate_limited`, 5xx,
     *                                        timeouts and network errors.
     * @param int           $retryBaseDelayMs First backoff step in milliseconds.
     * @param int           $retryMaxDelayMs  Ceiling of the exponential backoff in milliseconds.
     * @param callable|null $onRetry          Called with a `RetryInfo` before each wait. Use it for your
     *                                        own logging.
     * @param callable|null $transport        Injection point for tests; see `CurlTransport`.
     * @param callable|null $sleep            Injection point for tests; receives milliseconds.
     * @param callable|null $random           Injection point for tests; returns a float in [0, 1).
     *
     * @throws GrualinkConfigError
     */
    public function __construct(
        #[\SensitiveParameter]
        string $apiKey,
        ?string $baseUrl = null,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        int $retryBaseDelayMs = 500,
        int $retryMaxDelayMs = 8000,
        ?callable $onRetry = null,
        ?callable $transport = null,
        ?callable $sleep = null,
        ?callable $random = null,
    ) {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new GrualinkConfigError('apiKey is required (set GRUALINK_API_KEY).');
        }
        if (preg_match(self::API_KEY_PATTERN, $apiKey) !== 1) {
            throw new GrualinkConfigError('apiKey must start with glk_test_ or glk_live_.');
        }

        $baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new GrualinkConfigError('baseUrl is not a valid URL.');
        }
        $scheme = strtolower($parts['scheme']);
        $local = in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '[::1]'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
            throw new GrualinkConfigError('baseUrl must use https (http is only accepted for localhost).');
        }
        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new GrualinkConfigError('baseUrl must not contain credentials, query or fragment.');
        }
        self::assertPositive($timeoutMs, 'timeoutMs');
        self::assertPositive($retryBaseDelayMs, 'retryBaseDelayMs');
        self::assertPositive($retryMaxDelayMs, 'retryMaxDelayMs');
        if ($maxRetries < 0) {
            throw new GrualinkConfigError('maxRetries must be a non-negative integer.');
        }

        self::$apiKeys ??= new \WeakMap();
        self::$apiKeys[$this] = $apiKey;
        $this->mode = str_starts_with($apiKey, 'glk_live_') ? 'live' : 'test';
        $this->baseUrl = $baseUrl;
        $this->timeoutMs = $timeoutMs;
        $this->maxRetries = $maxRetries;
        $this->retryBaseDelayMs = $retryBaseDelayMs;
        $this->retryMaxDelayMs = $retryMaxDelayMs;
        $this->onRetry = $onRetry !== null ? \Closure::fromCallable($onRetry) : null;
        $this->transport = \Closure::fromCallable($transport ?? new CurlTransport());
        $this->sleep = $sleep !== null
            ? \Closure::fromCallable($sleep)
            : static function (int $milliseconds): void {
                usleep($milliseconds * 1000);
            };
        $this->random = $random !== null
            ? \Closure::fromCallable($random)
            : static fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /**
     * `test` or `live`, from the key prefix.
     */
    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * `GET /health`. Sent without the key.
     *
     * @return array{status: string, version: string}
     *
     * @throws GrualinkError
     */
    public function health(): array
    {
        return $this->request('GET', '/health', null, [], false)['body'];
    }

    /**
     * `POST /service-requests`. The body is serialized once and the exact same bytes and Idempotency-Key
     * are reused on every retry, so a retry can never create a second request.
     *
     * @param array<string, mixed> $input          The service request, with the wire names (see the
     *                                             contract, section 3).
     * @param string|null          $idempotencyKey Your key for this request. Store it with your record
     *                                             before calling: sending it again with the same body
     *                                             returns the original answer instead of a duplicate.
     *                                             Generated (UUID v4) when omitted.
     *
     * @throws GrualinkError
     */
    public function createServiceRequest(array $input, ?string $idempotencyKey = null): CreateServiceRequestResult
    {
        $idempotencyKey ??= self::uuid4();
        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey) !== 1) {
            throw new GrualinkConfigError('idempotencyKey must be 1 to 255 visible ASCII characters.');
        }
        if (array_is_list($input)) {
            throw new GrualinkConfigError('The service request must be an object (an array with string keys).');
        }
        try {
            $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION;
            $body = json_encode($input, $flags);
        } catch (\JsonException) {
            throw new GrualinkConfigError('The service request cannot be encoded as JSON.');
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new GrualinkConfigError('The service request exceeds 16 KB.');
        }

        $response = $this->request(
            'POST',
            '/service-requests',
            $body,
            ['Content-Type' => 'application/json', 'Idempotency-Key' => $idempotencyKey],
        );

        return new CreateServiceRequestResult(
            $response['body'],
            $response['status'] === 200 && ($response['headers']['idempotent-replayed'] ?? null) === 'true',
            $idempotencyKey,
        );
    }

    /**
     * `GET /service-requests/{id}`.
     *
     * @return array<string, mixed>
     *
     * @throws GrualinkError
     */
    public function getServiceRequest(string $id): array
    {
        if (trim($id) === '') {
            throw new GrualinkConfigError('id is required.');
        }

        return $this->request('GET', '/service-requests/' . rawurlencode(trim($id)))['body'];
    }

    /**
     * `GET /service-requests?external_ref=...`: up to 20 of your requests with that reference, newest first.
     *
     * @return list<array<string, mixed>>
     *
     * @throws GrualinkError
     */
    public function findServiceRequestsByExternalRef(string $externalRef): array
    {
        if (trim($externalRef) === '') {
            throw new GrualinkConfigError('externalRef is required.');
        }
        $query = http_build_query(['external_ref' => $externalRef], '', '&', PHP_QUERY_RFC1738);
        $list = $this->request('GET', '/service-requests?' . $query)['body'];

        return is_array($list['data'] ?? null) ? array_values($list['data']) : [];
    }

    /**
     * The printed form of the client never shows the key.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'mode' => $this->mode,
            'baseUrl' => $this->baseUrl,
            'timeoutMs' => $this->timeoutMs,
            'maxRetries' => $this->maxRetries,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('A GrualinkClient cannot be serialized.');
    }

    private function __clone()
    {
    }

    private function apiKey(): string
    {
        return self::$apiKeys[$this] ?? '';
    }

    /**
     * Replaces the API key with a marker in any text that will leave this class.
     */
    private function redact(string $text): string
    {
        return str_replace($this->apiKey(), self::REDACTED, $text);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: array<mixed>}
     */
    private function request(
        string $method,
        string $path,
        ?string $body = null,
        array $headers = [],
        bool $authenticated = true,
    ): array {
        $headers = ['Accept' => 'application/json'] + $headers;
        if ($authenticated) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey();
        }
        $url = $this->baseUrl . $path;
        $attempt = 0;

        while (true) {
            $attempt++;
            $response = null;
            try {
                $response = ($this->transport)($method, $url, $headers, $body, $this->timeoutMs);
            } catch (GrualinkTimeoutError) {
                $failure = new GrualinkTimeoutError(
                    sprintf('No response from the GRUALINK API within %d ms.', $this->timeoutMs),
                );
            } catch (\Throwable $cause) {
                // The original error is not chained: its message and trace are outside our control.
                $failure = new GrualinkConnectionError(
                    $this->redact('Could not reach the GRUALINK API: ' . $cause->getMessage()),
                );
            }

            if ($response !== null) {
                $status = (int) $response['status'];
                if ($status >= 200 && $status < 300) {
                    $decoded = json_decode((string) $response['body'], true);
                    if (is_array($decoded)) {
                        return ['status' => $status, 'headers' => $response['headers'], 'body' => $decoded];
                    }
                    $failure = new GrualinkApiError(
                        $status,
                        'unexpected_response',
                        'The API answered with a body that is not JSON.',
                    );
                } else {
                    $failure = $this->toApiError($status, $response['headers'], (string) $response['body']);
                }
            }

            $failure->attempts = $attempt;
            if (!self::isRetryable($failure) || $attempt > $this->maxRetries) {
                throw $failure;
            }
            $delayMs = $this->retryDelay($attempt, $failure);
            if ($this->onRetry !== null) {
                ($this->onRetry)(new RetryInfo($attempt, $delayMs, $failure));
            }
            ($this->sleep)($delayMs);
        }
    }

    private static function isRetryable(GrualinkError $error): bool
    {
        if ($error instanceof GrualinkConnectionError || $error instanceof GrualinkTimeoutError) {
            return true;
        }
        if ($error instanceof GrualinkServerError) {
            return true;
        }
        // too_many_open_requests only clears when the company resolves a request: waiting a few seconds
        // does not help.
        if ($error instanceof GrualinkRateLimitError) {
            return $error->getCode() !== 'too_many_open_requests';
        }

        return false;
    }

    /**
     * Exponential backoff with full jitter; `Retry-After` is a floor when the API sends it.
     */
    private function retryDelay(int $attempt, GrualinkError $failure): int
    {
        $ceiling = min($this->retryMaxDelayMs, $this->retryBaseDelayMs * 2 ** ($attempt - 1));
        $jitter = ($this->random)() * $ceiling;
        $retryAfter = $failure instanceof GrualinkApiError ? $failure->retryAfterSeconds : null;
        if ($retryAfter === null) {
            return (int) round($jitter);
        }

        return (int) round(min($retryAfter, self::MAX_RETRY_AFTER_SECONDS) * 1000 + $jitter);
    }

    /**
     * @param array<string, string> $headers
     */
    private function toApiError(int $status, array $headers, string $text): GrualinkApiError
    {
        $code = 'unexpected_response';
        $message = sprintf('The API answered HTTP %d.', $status);
        $details = [];

        // When the body is not JSON (for example an HTML page from a proxy) the generic message stays and
        // the body is not echoed.
        $parsed = json_decode($text, true);
        $error = is_array($parsed) ? ($parsed['error'] ?? null) : null;
        if (is_array($error) && is_string($error['code'] ?? null)) {
            $code = $this->redact($error['code']);
            if (is_string($error['message'] ?? null)) {
                $message = $this->redact($error['message']);
            }
            if (is_array($error['details'] ?? null)) {
                foreach ($error['details'] as $detail) {
                    if (!is_array($detail)) {
                        continue;
                    }
                    $details[] = [
                        'field' => $this->redact(self::text($detail['field'] ?? '')),
                        'issue' => $this->redact(self::text($detail['issue'] ?? '')),
                    ];
                }
            }
        }

        return GrualinkApiError::fromStatus(
            $status,
            $code,
            $message,
            $details,
            self::parseRetryAfter($headers['retry-after'] ?? null, time()),
        );
    }

    private static function assertPositive(int $value, string $name): void
    {
        if ($value <= 0) {
            throw new GrualinkConfigError($name . ' must be a positive number.');
        }
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function parseRetryAfter(?string $value, int $now): ?int
    {
        if ($value === null) {
            return null;
        }
        $text = trim($value);
        if (preg_match('/\A[0-9]{1,9}\z/', $text) === 1) {
            return (int) $text;
        }
        $date = strtotime($text);

        return $date === false ? null : max(0, $date - $now);
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
