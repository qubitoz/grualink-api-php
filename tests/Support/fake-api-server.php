<?php

declare(strict_types=1);

// A local stand-in for the API, for the tests and for trying the examples offline. It records what it
// receives and answers what the test queued through the /__control paths. Started with --demo, and with
// nothing queued, it behaves like a tiny in-memory API instead.
//
//   php tests/Support/fake-api-server.php [--demo]
//
// It prints the port it listens on (127.0.0.1 only) as its first line of output.

$demoMode = in_array('--demo', $argv, true);
$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($server === false) {
    fwrite(STDERR, "Could not start the fake API: {$errorMessage}\n");
    exit(1);
}
fwrite(STDOUT, substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1) . "\n");

/** @var list<array<string, mixed>> $requests */
$requests = [];
/** @var list<array<string, mixed>> $queue */
$queue = [];
/** @var array<string, array{body: string, id: string}> $demoKeys */
$demoKeys = [];
/** @var array<string, array<string, mixed>> $demoRequests */
$demoRequests = [];

/**
 * @param resource $connection
 *
 * @return array{method: string, path: string, headers: array<string, string>, body: string}|null
 */
function readRequest($connection): ?array
{
    $requestLine = fgets($connection);
    if ($requestLine === false || trim($requestLine) === '') {
        return null;
    }
    $parts = explode(' ', trim($requestLine));
    $headers = [];
    while (($line = fgets($connection)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            break;
        }
        $pair = explode(':', $line, 2);
        $headers[strtolower(trim($pair[0]))] = trim($pair[1] ?? '');
    }
    $length = (int) ($headers['content-length'] ?? 0);
    $body = '';
    while (strlen($body) < $length) {
        $chunk = fread($connection, $length - strlen($body));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }

    return ['method' => $parts[0], 'path' => $parts[1] ?? '/', 'headers' => $headers, 'body' => $body];
}

/**
 * @param resource              $connection
 * @param array<string, string> $headers
 */
function respond($connection, int $status, array $headers, string $payload): void
{
    $head = "HTTP/1.1 {$status} Fake\r\n";
    foreach ($headers + ['Content-Length' => (string) strlen($payload), 'Connection' => 'close'] as $name => $value) {
        $head .= "{$name}: {$value}\r\n";
    }
    @fwrite($connection, $head . "\r\n" . $payload);
    @fclose($connection);
}

/**
 * @return array<string, mixed>
 */
function demoError(int $status, string $code, string $message): array
{
    return ['status' => $status, 'body' => ['error' => ['code' => $code, 'message' => $message]]];
}

/**
 * A tiny in-memory API: enough of the public contract to run the request example end to end.
 *
 * @param array{method: string, path: string, headers: array<string, string>, body: string} $request
 * @param array<string, array{body: string, id: string}>                                    $keys
 * @param array<string, array<string, mixed>>                                               $stored
 *
 * @return array<string, mixed>
 */
function demoReply(array $request, array &$keys, array &$stored): array
{
    $path = (string) parse_url($request['path'], PHP_URL_PATH);
    parse_str((string) parse_url($request['path'], PHP_URL_QUERY), $query);

    if ($path === '/api/v1/health') {
        return ['status' => 200, 'body' => ['status' => 'ok', 'version' => 'v1']];
    }
    if (!str_starts_with($request['headers']['authorization'] ?? '', 'Bearer glk_test_')) {
        return demoError(401, 'unauthorized', 'Llave inválida.');
    }
    if ($path === '/api/v1/service-requests' && $request['method'] === 'POST') {
        $key = $request['headers']['idempotency-key'] ?? '';
        if ($key === '') {
            return demoError(400, 'idempotency_key_required', 'Falta Idempotency-Key.');
        }
        $input = json_decode($request['body'], true);
        if (!is_array($input)) {
            return demoError(400, 'invalid_json', 'El cuerpo no es JSON válido.');
        }
        if (isset($keys[$key])) {
            if ($keys[$key]['body'] !== $request['body']) {
                return demoError(409, 'idempotency_conflict', 'La llave ya se usó con otro cuerpo.');
            }

            return [
                'status' => 200,
                'headers' => ['Idempotent-Replayed' => 'true'],
                'body' => $stored[$keys[$key]['id']],
            ];
        }
        $reference = isset($input['external_ref']) ? (string) $input['external_ref'] : null;
        $status = 'quoted';
        $prefixes = [
            'TEST-CONFIRMAR' => 'confirmed',
            'TEST-EN-CURSO' => 'in_progress',
            'TEST-TERMINAR' => 'completed',
            'TEST-RECHAZAR' => 'rejected',
            'TEST-CANCELAR' => 'cancelled',
        ];
        foreach ($prefixes as $prefix => $final) {
            if ($reference !== null && str_starts_with($reference, $prefix)) {
                $status = $final;
            }
        }
        $id = sprintf('11111111-2222-4333-8444-%012d', count($stored) + 1);
        $stored[$id] = [
            'id' => $id,
            'object' => 'service_request',
            'status' => $status,
            'mode' => 'test',
            'external_ref' => $reference,
            'created_at' => gmdate('Y-m-d\TH:i:s.000\Z'),
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
        $keys[$key] = ['body' => $request['body'], 'id' => $id];

        return ['status' => 201, 'body' => $stored[$id]];
    }
    if ($path === '/api/v1/service-requests' && $request['method'] === 'GET') {
        $matches = [];
        foreach ($stored as $serviceRequest) {
            if ($serviceRequest['external_ref'] === ($query['external_ref'] ?? null)) {
                $matches[] = $serviceRequest;
            }
        }

        return ['status' => 200, 'body' => ['object' => 'list', 'data' => array_reverse($matches)]];
    }
    $id = substr($path, strlen('/api/v1/service-requests/'));
    if (str_starts_with($path, '/api/v1/service-requests/') && $request['method'] === 'GET' && isset($stored[$id])) {
        return ['status' => 200, 'body' => $stored[$id]];
    }

    return demoError(404, 'not_found', 'No existe.');
}

while (true) {
    $connection = @stream_socket_accept($server, 3600);
    if ($connection === false) {
        continue;
    }
    stream_set_timeout($connection, 2);
    $request = readRequest($connection);
    if ($request === null) {
        fclose($connection);
        continue;
    }

    if (str_starts_with($request['path'], '/__control/')) {
        if ($request['path'] === '/__control/reset') {
            $requests = [];
            $queue = [];
        } elseif ($request['path'] === '/__control/replies') {
            $queue = json_decode($request['body'], true);
        }
        $flags = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE;
        respond($connection, 200, ['Content-Type' => 'application/json'], (string) json_encode($requests, $flags));
        continue;
    }

    $requests[] = $request;
    $answer = count($queue) > 1 ? array_shift($queue) : ($queue[0] ?? null);
    if ($answer === null) {
        $answer = $demoMode
            ? demoReply($request, $demoKeys, $demoRequests)
            : ['status' => 500, 'body' => 'no reply queued'];
    }
    if (!empty($answer['destroy'])) {
        // Close the socket without answering (to exercise network errors).
        fclose($connection);
        continue;
    }
    if (!empty($answer['delayMs'])) {
        // Wait before answering (to exercise the timeout).
        usleep((int) $answer['delayMs'] * 1000);
    }
    $body = $answer['body'] ?? '';
    $isText = is_string($body);
    $payload = $isText ? $body : (string) json_encode($body, JSON_UNESCAPED_UNICODE);
    // Lets a test simulate a server that (wrongly) echoes the Authorization header back.
    $payload = str_replace('{{authorization}}', $request['headers']['authorization'] ?? '', $payload);
    $headers = $answer['headers'] ?? [];
    $headers += ['Content-Type' => $isText ? 'text/html' : 'application/json; charset=utf-8'];
    respond($connection, (int) $answer['status'], $headers, $payload);
}
