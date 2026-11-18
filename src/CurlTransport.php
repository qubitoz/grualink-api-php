<?php

declare(strict_types=1);

namespace Grualink;

/**
 * Sends one HTTP request with the cURL extension. No redirect is ever followed.
 */
final class CurlTransport
{
    private const CURLE_OPERATION_TIMEDOUT = 28;

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string} Header names are lowercase.
     *
     * @throws GrualinkTimeoutError    when there is no answer within the timeout
     * @throws GrualinkConnectionError when there is no HTTP answer at all
     */
    public function __invoke(string $method, string $url, array $headers, ?string $body, int $timeoutMs): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        // Send the body at once instead of waiting for a "100 Continue".
        $lines[] = 'Expect:';

        $responseHeaders = [];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            // A redirect is never followed: the key must not travel to another address.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    $responseHeaders[$name] = trim(substr($line, $separator + 1));
                }

                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        $handle = curl_init();
        curl_setopt_array($handle, $options);
        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            if (curl_errno($handle) === self::CURLE_OPERATION_TIMEDOUT) {
                throw new GrualinkTimeoutError('The request timed out.');
            }

            throw new GrualinkConnectionError(curl_error($handle));
        }

        return [
            'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'headers' => $responseHeaders,
            'body' => (string) $responseBody,
        ];
    }
}
