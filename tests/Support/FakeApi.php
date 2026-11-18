<?php

declare(strict_types=1);

namespace Grualink\Tests\Support;

use Grualink\CurlTransport;

/**
 * Starts the local stand-in for the API in another process and talks to it.
 */
final class FakeApi
{
    public const SERVICE_REQUEST = [
        'id' => '11111111-2222-4333-8444-555555555555',
        'object' => 'service_request',
        'status' => 'quoted',
        'mode' => 'test',
        'external_ref' => 'TEST-CONFIRMAR-0001',
        'created_at' => '2026-11-18T10:00:00.000Z',
        'resolved_at' => null,
        'rejection_reason' => null,
        'service' => null,
        'quote' => [
            'estimated_price' => [
                'currency' => 'MXN',
                'subtotal' => 864.33,
                'taxes' => [['name' => 'IVA', 'type' => 'tax', 'rate' => 16, 'amount' => 138.29]],
                'tax_total' => 138.29,
                'retention_total' => 0,
                'total' => 1002.62,
            ],
            'includes_extras' => false,
            'approximate_distance' => true,
            'unpriced_reason' => null,
            'note' => 'Precio estimado.',
        ],
        'assignment' => null,
        'timeline' => null,
    ];

    /** @var resource */
    private $process;

    private string $origin;

    public string $baseUrl;

    private function __construct()
    {
    }

    public static function start(): self
    {
        $api = new self();
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fake-api-server.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('The fake API could not be started.');
        }
        $port = (int) trim((string) fgets($pipes[1]));
        if ($port <= 0) {
            throw new \RuntimeException('The fake API did not report its port.');
        }
        $api->process = $process;
        $api->origin = 'http://127.0.0.1:' . $port;
        $api->baseUrl = $api->origin . '/api/v1';

        return $api;
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }

    /**
     * Forgets the recorded requests and the queued answers.
     */
    public function reset(): void
    {
        $this->control('/__control/reset');
    }

    /**
     * Queues answers; the last one repeats once the queue is exhausted. Each answer is an array with
     * `status` and optionally `headers`, `body` (arrays are sent as JSON; strings as they are), `delayMs`
     * (wait before answering) and `destroy` (close the socket without answering).
     *
     * @param array<string, mixed> ...$responses
     */
    public function reply(array ...$responses): void
    {
        $this->control('/__control/replies', (string) json_encode($responses));
    }

    /**
     * @return list<array{method: string, path: string, headers: array<string, string>, body: string}>
     */
    public function requests(): array
    {
        return $this->control('/__control/requests');
    }

    /**
     * @param list<array{field: string, issue: string}>|null $details
     * @param array<string, string>                          $headers
     *
     * @return array<string, mixed>
     */
    public static function error(
        int $status,
        string $code,
        string $message = 'Mensaje de ejemplo.',
        ?array $details = null,
        array $headers = [],
    ): array {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }

        return ['status' => $status, 'body' => ['error' => $error], 'headers' => $headers];
    }

    /**
     * @return list<array{method: string, path: string, headers: array<string, string>, body: string}>
     */
    private function control(string $path, ?string $body = null): array
    {
        $response = (new CurlTransport())('POST', $this->origin . $path, [], $body ?? '', 10000);

        return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    }
}
