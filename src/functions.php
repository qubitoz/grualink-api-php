<?php

declare(strict_types=1);

namespace Grualink;

const SIGNATURE_HEADER = 'x-grualink-signature';
const DELIVERY_HEADER = 'x-grualink-delivery';
const EVENT_HEADER = 'x-grualink-event';
const TIMESTAMP_HEADER = 'x-grualink-timestamp';
const MODE_HEADER = 'x-grualink-mode';

/** Deliveries whose signed timestamp differs more than this from the current time are rejected. */
const DEFAULT_TOLERANCE_SECONDS = 300;

/** A delivery body is never larger than 64 KB. */
const MAX_WEBHOOK_BODY_BYTES = 64 * 1024;

/**
 * HMAC-SHA256, lowercase hex, of `<timestamp>.<raw body>` keyed with the secret exactly as issued.
 */
function computeWebhookSignature(
    #[\SensitiveParameter]
    string $secret,
    int|string $timestamp,
    string $rawBody,
): string {
    return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
}

/**
 * Builds the header the way GRUALINK sends it. Useful to test your own receiver locally.
 *
 * @param list<string> $secrets
 */
function buildSignatureHeader(
    #[\SensitiveParameter]
    array $secrets,
    int $timestamp,
    string $rawBody,
): string {
    $parts = ['t=' . $timestamp];
    foreach ($secrets as $secret) {
        $parts[] = 'v1=' . computeWebhookSignature($secret, $timestamp, $rawBody);
    }

    return implode(',', $parts);
}

/**
 * Verifies `X-GruaLink-Signature`. Pure and total: it never throws and has no side effects.
 *
 * Order of checks (shared by every language; the contract vectors pin it):
 *  1. no secret                                        -> missing_secret
 *  2. header absent or blank                           -> missing_header
 *  3. no `t` of 1-12 digits, or no well-formed `v1`    -> malformed_header
 *  4. |now - t| greater than the tolerance             -> timestamp_out_of_tolerance
 *  5. no `v1` equals the expected signature            -> signature_mismatch
 *
 * The parameters are declared `mixed` on purpose: whatever arrives, the answer is a result, not an error.
 *
 * @param mixed          $rawBody          The RAW request body (string of bytes) exactly as received,
 *                                         before any JSON parsing.
 * @param mixed          $signatureHeader  Value of the `X-GruaLink-Signature` header (string or null).
 * @param mixed          $secret           Your signing secret, whole (with its `whsec_` prefix). Pass a
 *                                         list of two during your own rotation window.
 * @param int|float|null $nowSeconds       Current time in seconds since 1970 (UTC). Defaults to the
 *                                         system clock.
 * @param int|float      $toleranceSeconds Defaults to 300 seconds.
 */
function verifyWebhookSignature(
    mixed $rawBody,
    mixed $signatureHeader,
    #[\SensitiveParameter]
    mixed $secret,
    int|float|null $nowSeconds = null,
    int|float $toleranceSeconds = DEFAULT_TOLERANCE_SECONDS,
): SignatureVerificationResult {
    $fail = static fn (string $reason): SignatureVerificationResult => new SignatureVerificationResult(false, $reason);

    try {
        $secrets = [];
        foreach (is_array($secret) ? $secret : [$secret] as $value) {
            if (is_string($value) && $value !== '') {
                $secrets[] = $value;
            }
        }
        if ($secrets === []) {
            return $fail('missing_secret');
        }

        $whitespace = " \t\n\r\x0B\x0C";
        if (!is_string($signatureHeader) || trim($signatureHeader, $whitespace) === '') {
            return $fail('missing_header');
        }

        $timestamp = null;
        $candidates = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $item = trim($part, $whitespace);
            $separator = strpos($item, '=');
            if ($separator === false) {
                continue;
            }
            $name = substr($item, 0, $separator);
            $value = substr($item, $separator + 1);
            if ($name === 't' && $timestamp === null) {
                $timestamp = $value;
            } elseif ($name === 'v1' && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1) {
                // Anything that is not exactly 64 lowercase hex characters is ignored, as are unknown schemes.
                $candidates[] = $value;
            }
        }
        if ($timestamp === null || preg_match('/\A[0-9]{1,12}\z/', $timestamp) !== 1) {
            return $fail('malformed_header');
        }
        if ($candidates === []) {
            return $fail('malformed_header');
        }

        $now = $nowSeconds ?? time();
        if (abs($now - (int) $timestamp) > $toleranceSeconds) {
            return $fail('timestamp_out_of_tolerance');
        }

        if (!is_string($rawBody)) {
            return $fail('malformed_header');
        }
        $matched = false;
        // Every pair is compared in constant time and the loop never exits early.
        foreach ($secrets as $candidateSecret) {
            $expected = computeWebhookSignature($candidateSecret, $timestamp, $rawBody);
            foreach ($candidates as $candidate) {
                if (hash_equals($expected, $candidate)) {
                    $matched = true;
                }
            }
        }

        return $matched ? new SignatureVerificationResult(true, 'ok') : $fail('signature_mismatch');
    } catch (\Throwable) {
        return $fail('malformed_header');
    }
}

/**
 * Creates the framework-independent handler of deliveries. See `WebhookHandler`.
 *
 * @param string|list<string> $secret     Signing secret, or current and previous during your rotation.
 * @param callable            $onEvent    Called once per verified, non-duplicate delivery.
 * @param callable|null       $nowSeconds Clock in seconds, for tests.
 */
function createWebhookHandler(
    #[\SensitiveParameter]
    string|array $secret,
    callable $onEvent,
    ?DeliveryDeduplicator $deduplicator = null,
    int|float $toleranceSeconds = DEFAULT_TOLERANCE_SECONDS,
    ?callable $nowSeconds = null,
): WebhookHandler {
    return new WebhookHandler($secret, $onEvent, $deduplicator, $toleranceSeconds, $nowSeconds);
}

/**
 * Reads the raw body of the current request with a hard cap.
 *
 * @return string|null The bytes exactly as received, or `null` when the body exceeds `$limit`.
 */
function readRawBody(int $limit = MAX_WEBHOOK_BODY_BYTES): ?string
{
    $declared = $_SERVER['CONTENT_LENGTH'] ?? null;
    if (is_numeric($declared) && (int) $declared > $limit) {
        return null;
    }
    $input = fopen('php://input', 'rb');
    if ($input === false) {
        return '';
    }
    $body = (string) stream_get_contents($input, $limit + 1);
    fclose($input);

    return strlen($body) > $limit ? null : $body;
}

/**
 * Request headers from `$_SERVER`, with lowercase names (`HTTP_X_GRUALINK_SIGNATURE` -> `x-grualink-signature`).
 *
 * @param array<string, mixed> $server
 *
 * @return array<string, string>
 */
function headersFromServer(array $server): array
{
    $headers = [];
    foreach ($server as $name => $value) {
        if (!is_string($value)) {
            continue;
        }
        if (str_starts_with($name, 'HTTP_')) {
            $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
        } elseif ($name === 'CONTENT_TYPE' || $name === 'CONTENT_LENGTH') {
            $headers[strtolower(str_replace('_', '-', $name))] = $value;
        }
    }

    return $headers;
}

/**
 * Receives one GRUALINK webhook in plain PHP, no framework needed: it reads the current request from
 * `$_SERVER` and `php://input`, hands it to the handler and writes the answer.
 *
 * @param string|null   $path     Only this path is served (default: any path).
 * @param callable|null $onResult Called after each delivery with the `WebhookHandlerResult`, for your logs.
 *                                The body and the secret are never passed.
 */
function serveWebhook(WebhookHandler $handler, ?string $path = null, ?callable $onResult = null): void
{
    $respond = static function (int $status, array $body, array $headers = []): void {
        $text = (string) json_encode($body);
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($text));
        header('Cache-Control: no-store');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $text;
    };

    try {
        $pathname = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($path !== null && $pathname !== $path) {
            $respond(404, ['received' => false, 'error' => 'not_found']);

            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $respond(405, ['received' => false, 'error' => 'method_not_allowed'], ['Allow' => 'POST']);

            return;
        }
        $rawBody = readRawBody();
        if ($rawBody === null) {
            $respond(413, ['received' => false, 'error' => 'payload_too_large'], ['Connection' => 'close']);

            return;
        }
        $result = $handler->handle($rawBody, headersFromServer($_SERVER));
        $respond($result->status, $result->body);
        if ($onResult !== null) {
            $onResult($result);
        }
    } catch (\Throwable) {
        if (!headers_sent()) {
            $respond(500, ['received' => false, 'error' => 'internal_error']);
        }
    }
}
