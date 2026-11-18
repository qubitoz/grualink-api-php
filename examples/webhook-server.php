<?php

declare(strict_types=1);

// Example webhook receiver in plain PHP, no framework.
//
//   GRUALINK_WEBHOOK_SECRET=<your whsec_ secret> php -S localhost:8787 examples/webhook-server.php
//
// Expose it over https (GRUALINK only delivers to https addresses on port 443) and give that address to
// the towing company. Ask them to send a test event (webhook.test) to check the connection.
//
// Behind Apache or nginx this same file works as the script of the route: it reads the raw body from
// php://input before anything parses it.

use Grualink\FileDeliveryDeduplicator;
use Grualink\WebhookHandlerResult;

use function Grualink\createWebhookHandler;
use function Grualink\serveWebhook;

require __DIR__ . '/../vendor/autoload.php';

$secrets = array_values(array_filter(
    [(string) getenv('GRUALINK_WEBHOOK_SECRET'), (string) getenv('GRUALINK_WEBHOOK_SECRET_PREVIOUS')],
    static fn (string $secret): bool => $secret !== '',
));
if ($secrets === []) {
    error_log('Set GRUALINK_WEBHOOK_SECRET to your signing secret (it starts with whsec_). See .env.example.');
    http_response_code(500);
    exit(1);
}

// Every request starts a fresh PHP process, so the ids already processed are kept on disk. With several
// servers, use a shared store instead (a unique key in your database is enough).
$stateDir = getenv('GRUALINK_WEBHOOK_STATE_DIR') ?: sys_get_temp_dir() . '/grualink-webhook-example';

$handler = createWebhookHandler(
    secret: $secrets,
    // Stand-in for your own queue or database. Answer fast and do the real work elsewhere.
    onEvent: static function (array $event): void {
        $label = '[' . ($event['mode'] ?? '?') . '] ' . $event['type'];
        $serviceRequest = $event['data']['service_request'] ?? null;
        if (is_array($serviceRequest)) {
            $id = $serviceRequest['id'] ?? '?';
            $reference = $serviceRequest['external_ref'] ?? 'no reference';
            $status = $serviceRequest['status'] ?? '?';
            error_log("{$label}: request {$id} ({$reference}) is now \"{$status}\"");
        } else {
            error_log("{$label}: " . ($event['data']['message'] ?? ''));
        }
    },
    deduplicator: new FileDeliveryDeduplicator($stateDir),
);

serveWebhook(
    $handler,
    path: '/webhooks/grualink',
    onResult: static function (WebhookHandlerResult $result): void {
        // The reason stays in your logs; the caller only sees a generic answer.
        if ($result->reason !== 'ok') {
            $delivery = $_SERVER['HTTP_X_GRUALINK_DELIVERY'] ?? '-';
            error_log("Delivery {$delivery}: {$result->status} ({$result->reason})");
        }
    },
);
