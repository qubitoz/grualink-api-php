<?php

declare(strict_types=1);

// Front controller used by the receiver tests, served by the built-in web server of PHP. Its clock, its
// failures and what it received live in files of GRUALINK_TEST_STATE_DIR, so the test can drive it.

use Grualink\FileDeliveryDeduplicator;
use Grualink\WebhookHandlerResult;

use function Grualink\createWebhookHandler;
use function Grualink\serveWebhook;

require __DIR__ . '/../../vendor/autoload.php';

$stateDir = (string) getenv('GRUALINK_TEST_STATE_DIR');

$handler = createWebhookHandler(
    secret: ['whsec_TEST_ONLY_FAKE_SECRET_0001'],
    onEvent: static function (array $event) use ($stateDir): void {
        if (is_file($stateDir . '/fail-next')) {
            unlink($stateDir . '/fail-next');
            throw new RuntimeException('database is down');
        }
        file_put_contents($stateDir . '/events.jsonl', json_encode($event) . "\n", FILE_APPEND);
    },
    deduplicator: new FileDeliveryDeduplicator($stateDir . '/deliveries'),
    nowSeconds: static fn (): int => (int) file_get_contents($stateDir . '/now'),
);

serveWebhook(
    $handler,
    path: '/webhooks/grualink',
    onResult: static function (WebhookHandlerResult $result) use ($stateDir): void {
        $line = json_encode(['status' => $result->status, 'reason' => $result->reason]) . "\n";
        file_put_contents($stateDir . '/results.jsonl', $line, FILE_APPEND);
    },
);
