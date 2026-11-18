<?php

declare(strict_types=1);

// Sends locally signed deliveries to a running webhook receiver and checks its answers: accepted once,
// acknowledged as a duplicate the second time, refused when the body was altered.
//
//   GRUALINK_WEBHOOK_SECRET=<the secret of the receiver> php tests/smoke/send-signed-delivery.php <url>

use Grualink\CurlTransport;

use function Grualink\buildSignatureHeader;

require __DIR__ . '/../../vendor/autoload.php';

$url = $argv[1] ?? '';
$secret = (string) getenv('GRUALINK_WEBHOOK_SECRET');
if ($url === '' || $secret === '') {
    fwrite(STDERR, "Usage: GRUALINK_WEBHOOK_SECRET=... php tests/smoke/send-signed-delivery.php <url>\n");
    exit(2);
}

$id = sprintf('dddddddd-0000-4000-8000-%012d', random_int(1, 999999999999));
$body = '{"id": "' . $id . '", "object": "event", "type": "webhook.test", "mode": "test", '
    . '"created_at": "2026-11-18T10:05:01.000Z", "data": {"message": "Aviso de prueba"}}';

$send = static function (string $payload) use ($url, $secret, $id, $body): array {
    $response = (new CurlTransport())(
        'POST',
        $url,
        [
            'Content-Type' => 'application/json; charset=utf-8',
            'User-Agent' => 'GRUALINK-Avisos/1.0',
            'X-GruaLink-Event' => 'webhook.test',
            'X-GruaLink-Delivery' => $id,
            'X-GruaLink-Timestamp' => (string) time(),
            'X-GruaLink-Mode' => 'test',
            // Always the signature of the original body, so an altered payload must be refused.
            'X-GruaLink-Signature' => buildSignatureHeader([$secret], time(), $body),
        ],
        $payload,
        10000,
    );

    return [$response['status'], $response['body']];
};

$expectations = [
    'first delivery' => [$body, 200, '{"received":true}'],
    'same delivery again' => [$body, 200, '{"received":true,"duplicate":true}'],
    'altered body' => [str_replace('prueba', 'verdad', $body), 401, '{"received":false,"error":"invalid_signature"}'],
];
$failed = false;
foreach ($expectations as $label => [$payload, $expectedStatus, $expectedBody]) {
    [$status, $answer] = $send($payload);
    $ok = $status === $expectedStatus && $answer === $expectedBody;
    $failed = $failed || !$ok;
    echo ($ok ? 'ok  ' : 'FAIL') . " {$label}: {$status} {$answer}\n";
}
exit($failed ? 1 : 0);
