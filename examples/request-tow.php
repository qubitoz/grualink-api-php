<?php

declare(strict_types=1);

// End-to-end example: request a tow truck with fictitious data, print the quote and check the status.
//
//   GRUALINK_API_KEY=<your TEST key> php examples/request-tow.php
//
// Use a TEST key (glk_test_...). Test requests never become real services.

use Grualink\GrualinkApiError;
use Grualink\GrualinkClient;
use Grualink\GrualinkError;
use Grualink\GrualinkValidationError;
use Grualink\RetryInfo;

require __DIR__ . '/../vendor/autoload.php';

$apiKey = (string) getenv('GRUALINK_API_KEY');
if ($apiKey === '') {
    fwrite(STDERR, "Set GRUALINK_API_KEY to your TEST key (it starts with glk_test_). See .env.example.\n");
    exit(1);
}
if (!str_starts_with($apiKey, 'glk_test_')) {
    fwrite(STDERR, "This example only runs with a TEST key (glk_test_...).\n");
    exit(1);
}

/**
 * @param array<string, mixed> $serviceRequest
 */
function printQuote(array $serviceRequest): void
{
    $quote = $serviceRequest['quote'] ?? [];
    $price = $quote['estimated_price'] ?? null;
    if ($price === null) {
        $reason = $quote['unpriced_reason'] ?? 'unknown reason';
        echo "  No estimated price ({$reason}); the company will quote it.\n";

        return;
    }
    printf("  Subtotal:  %.2f %s\n", $price['subtotal'], $price['currency']);
    foreach ($price['taxes'] as $tax) {
        $sign = $tax['type'] === 'retention' ? '-' : '+';
        printf("  %s %s (%s%%): %.2f\n", $sign, $tax['name'], $tax['rate'], $tax['amount']);
    }
    printf("  Total:     %.2f %s (estimate, extras not included)\n", $price['total'], $price['currency']);
}

try {
    $client = new GrualinkClient(
        apiKey: $apiKey,
        baseUrl: getenv('GRUALINK_API_BASE_URL') ?: null,
        onRetry: static function (RetryInfo $info): void {
            $name = (new ReflectionClass($info->error))->getShortName();
            fwrite(STDERR, "Attempt {$info->attempt} failed ({$name}); retrying in {$info->delayMs} ms\n");
        },
    );

    // With a test key, the prefix of external_ref decides the final status: TEST-CONFIRMAR -> confirmed,
    // TEST-EN-CURSO -> in_progress, TEST-TERMINAR -> completed, TEST-RECHAZAR -> rejected,
    // TEST-CANCELAR -> cancelled.
    $reference = 'TEST-CONFIRMAR-' . bin2hex(random_bytes(4));
    $request = [
        'external_ref' => $reference,
        'contact' => ['name' => 'Persona de Ejemplo', 'phone' => '+525500000000'],
        'vehicle' => [
            'make' => 'Nissan',
            'model' => 'Versa',
            'year' => 2019,
            'color' => 'Gris',
            'plate' => 'ABC123A',
            'type' => 'auto',
        ],
        'pickup' => ['address' => 'Av. Juárez 100, Centro, Guadalajara, Jal.', 'lat' => 20.6767, 'lng' => -103.3475],
        'destination' => ['address' => 'Av. Patria 500, Zapopan, Jal.', 'lat' => 20.7214, 'lng' => -103.3918],
        'service' => ['reason' => 'No arranca', 'category' => 'vial', 'required_class' => 'A'],
        'notes' => 'Datos ficticios de ejemplo.',
    ];

    $health = $client->health();
    echo "API status: {$health['status']} ({$health['version']})\n";

    // In your system, store this key next to your own record BEFORE calling, and reuse it if you have to
    // retry. Any unique value works (a UUID is fine).
    $idempotencyKey = 'example-' . bin2hex(random_bytes(16));

    $created = $client->createServiceRequest($request, idempotencyKey: $idempotencyKey);
    $serviceRequest = $created->serviceRequest;
    echo "\nCreated {$serviceRequest['id']} ({$serviceRequest['mode']}) with status "
        . "\"{$serviceRequest['status']}\", reference {$serviceRequest['external_ref']}\n";
    printQuote($serviceRequest);

    // Sending it again with the same key and the same body creates nothing new.
    $again = $client->createServiceRequest($request, idempotencyKey: $idempotencyKey);
    $replayed = $again->replayed ? 'true' : 'false';
    $sameId = $again->serviceRequest['id'] === $serviceRequest['id'] ? 'true' : 'false';
    echo "\nSent again with the same Idempotency-Key: replayed={$replayed}, same id={$sameId}\n";

    $current = $client->getServiceRequest($serviceRequest['id']);
    $folio = isset($current['service']['folio']) ? ", folio {$current['service']['folio']}" : '';
    echo "\nStatus now: \"{$current['status']}\"{$folio}\n";

    $byReference = $client->findServiceRequestsByExternalRef($reference);
    echo "Requests with reference {$reference}: " . count($byReference) . "\n";
} catch (GrualinkValidationError $error) {
    $fields = [];
    foreach ($error->details as $detail) {
        $fields[] = "{$detail['field']} ({$detail['issue']})";
    }
    fwrite(STDERR, 'Invalid fields: ' . implode(', ', $fields) . "\n");
    exit(1);
} catch (GrualinkApiError $error) {
    fwrite(STDERR, "API error {$error->status} {$error->getCode()}: {$error->getMessage()}\n");
    exit(1);
} catch (GrualinkError $error) {
    fwrite(STDERR, (new ReflectionClass($error))->getShortName() . ": {$error->getMessage()}\n");
    exit(1);
}
