# GRUALINK API: PHP example

[Español](README.md)

A ready-to-clone, runnable example that integrates your system (insurer, roadside assistance,
integrator) with the GRUALINK API: **request a tow truck**, **check its status** and **receive signed
webhooks**.

Public API guide: <https://grualink.mx/api>

What is inside:

- A **minimal client** (`src/GrualinkClient.php`): creates requests with an `Idempotency-Key`, reads the
  status, typed errors, retries with exponential backoff and a timeout. It uses the cURL extension of
  PHP; no runtime dependencies.
- A **webhook receiver** (`src/functions.php`, `src/WebhookHandler.php`): verifies the signature over the
  raw body, checks the time window, supports secret rotation and discards duplicates.
- Two **runnable examples** (`examples/`).
- The **contract kit** (`contract/`): the API contract in Spanish and English and the signature test
  cases shared by the examples of every language.

It is not an SDK: it is code meant to be read and copied into your project.

## Requirements

- PHP 8.1 or later, with the `curl` and `json` extensions.
- [Composer](https://getcomposer.org/) 2.
- A **test key** (`glk_test_…`). The towing company you work with gives it to you.
- For webhooks: the **signing secret** (`whsec_…`) the same company gives you when it registers the
  address of your system.

## Installation

```bash
git clone <the address of this repository>
cd grualink-api-php
composer install
```

## Environment variables

Names only; the values live on your machine and never in the repository. There is a template in
`.env.example`. The examples read the variables from the process environment (they do not load `.env`
files).

| Variable | Purpose |
|---|---|
| `GRUALINK_API_KEY` | Your **test** key (`glk_test_…`) |
| `GRUALINK_WEBHOOK_SECRET` | Webhook signing secret (`whsec_…`), whole, prefix included |
| `GRUALINK_WEBHOOK_SECRET_PREVIOUS` | Optional: the previous secret while a rotation is in progress |
| `GRUALINK_API_BASE_URL` | Optional: another base URL (default `https://grualink.mx/api/v1`) |
| `GRUALINK_WEBHOOK_STATE_DIR` | Optional: folder where the example receiver remembers the deliveries it processed |

## Running the examples

### Request a tow truck

```bash
export GRUALINK_API_KEY=...   # your test key
php examples/request-tow.php
```

With fictitious data, the example sends a request, prints the estimated price, sends it again with the
same `Idempotency-Key` to show it is not duplicated, and reads its status. It only runs with test keys.

With a test key, the start of `external_ref` decides the final status: `TEST-CONFIRMAR`,
`TEST-EN-CURSO`, `TEST-TERMINAR`, `TEST-RECHAZAR`, `TEST-CANCELAR`.

### Receive webhooks

```bash
export GRUALINK_WEBHOOK_SECRET=...   # your signing secret
php -S localhost:8787 examples/webhook-server.php
```

It listens on `http://localhost:8787/webhooks/grualink` with the built-in web server of PHP (development
only). GRUALINK only delivers to public `https` addresses on port 443: publish the receiver behind your
domain (or an `https` tunnel while testing), give that address to the company and ask for a test event.

## Using it in your code

```php
use Grualink\GrualinkClient;
use Grualink\GrualinkValidationError;

$client = new GrualinkClient(apiKey: getenv('GRUALINK_API_KEY'));

try {
    $result = $client->createServiceRequest(
        [
            'external_ref' => 'TEST-CONFIRMAR-0001',
            'contact' => ['name' => 'Persona de Ejemplo', 'phone' => '+525500000000'],
            'pickup' => ['address' => 'Av. Juárez 100, Centro, Guadalajara, Jal.', 'lat' => 20.6767, 'lng' => -103.3475],
            'destination' => ['address' => 'Av. Patria 500, Zapopan, Jal.', 'lat' => 20.7214, 'lng' => -103.3918],
        ],
        idempotencyKey: $myRecord->idempotencyKey, // store it before calling
    );
    $request = $result->serviceRequest;   // an array with the names of the contract
    echo $request['id'], ' ', $request['status'], ' ', $request['quote']['estimated_price']['total'] ?? '-';
    var_dump($result->replayed);
} catch (GrualinkValidationError $error) {
    print_r($error->details);
}
```

Requests and answers are arrays with the same field names as the contract (`external_ref`,
`service_request`…); they are never translated.

### Idempotency and retries

- Every request carries an `Idempotency-Key` of yours. Repeating the call with the same key and the same
  body within 24 hours makes the API answer **`200`** with `Idempotent-Replayed: true` and create nothing
  (`replayed` is `true`). The same key with another body answers `409`.
- The client retries only what makes sense: network errors, timeouts, `5xx` and `429 rate_limited`
  (waiting what `Retry-After` says). It uses exponential backoff with jitter and always resends the same
  key and the same bytes: the body is serialized only once.
- It does not follow redirects and every attempt has a timeout (15 seconds by default).
- The key never appears in errors or logs, nor in the printed form of the client (`var_dump`,
  `print_r`).

### Verifying a webhook

```php
use function Grualink\verifyWebhookSignature;

$result = verifyWebhookSignature(
    rawBody: file_get_contents('php://input'),                    // the bytes exactly as received
    signatureHeader: $_SERVER['HTTP_X_GRUALINK_SIGNATURE'] ?? null,
    secret: getenv('GRUALINK_WEBHOOK_SECRET'),                    // whole, with whsec_
);
// $result->valid (bool) and $result->reason ('ok', 'signature_mismatch', ...)
```

What matters:

1. Verify the **raw body**, before turning it into JSON. If you parse it and serialize it again, the
   signature no longer matches.
2. Use the **whole** secret, with its `whsec_` prefix.
3. Reject timestamps more than 5 minutes away.
4. After a secret rotation, two `v1` signatures arrive for 24 hours: accept if either matches.
5. Deliveries can repeat: keep the `id` of the ones you processed and answer `2xx` without processing
   again.
6. Answer `2xx` within 10 seconds and do the heavy work afterwards.

The comparison uses `hash_equals` (constant time). The function never throws: it always returns a result
with its reason.

### Plain PHP

`serveWebhook()` reads the current request (`$_SERVER` and `php://input`), hands it to the handler and
writes the answer. It is what `examples/webhook-server.php` uses:

```php
use Grualink\FileDeliveryDeduplicator;

use function Grualink\createWebhookHandler;
use function Grualink\serveWebhook;

$handler = createWebhookHandler(
    secret: getenv('GRUALINK_WEBHOOK_SECRET'),
    onEvent: fn (array $event) => $myQueue->push($event),   // fast; the heavy work comes later
    deduplicator: new FileDeliveryDeduplicator('/var/lib/my-app/grualink-deliveries'),
);

serveWebhook($handler, path: '/webhooks/grualink');
```

In PHP every request starts from scratch, so the memory of the process is **no use** to remember which
deliveries already arrived. `FileDeliveryDeduplicator` remembers them on disk (one server);
`InMemoryDeliveryDeduplicator` only works in processes that stay alive between requests. With several
servers, implement `DeliveryDeduplicator` with your database: a unique key on the `id` of the event is
enough.

### Mounting it on Laravel or Symfony

The delicate part is getting the **raw body**. `createWebhookHandler` depends on no framework: it takes
the bytes and the headers and returns the status and the body of the answer.

Laravel: use `$request->getContent()` (not `$request->all()`, `$request->input()` or `$request->json()`,
which hand you the body already parsed). Declare the route in `routes/api.php` so it does not go through
CSRF verification.

```php
Route::post('/webhooks/grualink', function (Illuminate\Http\Request $request) use ($handler) {
    $result = $handler->handle($request->getContent(), $request->headers->all());

    return response()->json($result->body, $result->status);
});
```

For duplicates you can rely on the shared cache, which has an atomic operation:

```php
final class CacheDeliveryDeduplicator implements Grualink\DeliveryDeduplicator
{
    public function claim(string $deliveryId): bool
    {
        return Cache::add('grualink:delivery:' . $deliveryId, true, now()->addHours(48));
    }

    public function release(string $deliveryId): void
    {
        Cache::forget('grualink:delivery:' . $deliveryId);
    }
}
```

Symfony: the same, with `$request->getContent()` (not `$request->toArray()` or `$request->getPayload()`).

```php
#[Route('/webhooks/grualink', methods: ['POST'])]
public function grualink(Request $request): JsonResponse
{
    $result = $this->handler->handle($request->getContent(), $request->headers->all());

    return new JsonResponse($result->body, $result->status);
}
```

In both cases cap the body at 64 KB in your web server and put no middleware in front that rewrites the
body.

## Errors

Every API error has the shape `{ "error": { "code", "message", "details"? } }`. `code` is stable; branch
on it, not on `message`.

API exceptions (`GrualinkApiError` and its subclasses) carry `status`, `details`, `retryAfterSeconds`
and `attempts` as properties; read the `code` with `getCode()` (a string such as `validation_error`) and
the `message` with `getMessage()`.

| HTTP | `code` | What happened | Class | Retried? |
|---|---|---|---|---|
| 400 | `invalid_request` | The request is not valid | `GrualinkInvalidRequestError` | no |
| 400 | `invalid_json` | The body is not valid JSON | `GrualinkInvalidRequestError` | no |
| 400 | `key_in_query` | The key was sent in the URL; have it revoked | `GrualinkInvalidRequestError` | no |
| 400 | `idempotency_key_required` | `Idempotency-Key` missing | `GrualinkInvalidRequestError` | no |
| 400 | `invalid_idempotency_key` | Invalid `Idempotency-Key` (1 to 255 visible ASCII characters) | `GrualinkInvalidRequestError` | no |
| 401 | `unauthorized` | Key missing, invalid, expired or revoked | `GrualinkAuthenticationError` | no |
| 403 | `insufficient_scope` | The key is not allowed to do this | `GrualinkPermissionError` | no |
| 403 | `customer_unavailable` | Your customer account with the company is not available | `GrualinkPermissionError` | no |
| 403 | `live_mode_unavailable` | Live requests are not enabled yet | `GrualinkPermissionError` | no |
| 403 | `browser_not_allowed` | A live key was used from a browser | `GrualinkPermissionError` | no |
| 404 | `not_found` | It does not exist or is not yours | `GrualinkNotFoundError` | no |
| 405 | `method_not_allowed` | Method not allowed for that path | `GrualinkInvalidRequestError` | no |
| 409 | `idempotency_conflict` | That `Idempotency-Key` was already used with another body | `GrualinkIdempotencyConflictError` | no |
| 413 | `payload_too_large` | The body exceeds 16 KB | `GrualinkInvalidRequestError` | no |
| 415 | `unsupported_media_type` | The body must be sent as `application/json` | `GrualinkInvalidRequestError` | no |
| 422 | `validation_error` | Invalid fields; `details` says which | `GrualinkValidationError` | no |
| 429 | `rate_limited` | Too many requests; wait `Retry-After` | `GrualinkRateLimitError` | yes |
| 429 | `too_many_open_requests` | Too many unresolved requests with the company | `GrualinkRateLimitError` | no |
| 500 | `internal_error` | Internal error | `GrualinkServerError` | yes |

Also: `GrualinkConnectionError` (no answer, retried), `GrualinkTimeoutError` (timed out, retried) and
`GrualinkConfigError` (invalid configuration or argument; nothing was sent). All of them extend
`GrualinkError`. An error answer with another shape uses the `code` `unexpected_response`.

The full contract, with fields, limits and the order of checks, is in
[`contract/API.md`](contract/API.md).

## Tests

```bash
composer test     # PHPUnit
composer lint     # PSR-12 style
composer smoke    # runs both examples against a local fake server
```

The tests never call the real API: they use a local fake server. They cover every case of
`contract/signature-vectors.json`, every error code, retries, idempotency and that the key appears in no
error or log. The receiver is also tested over HTTP, behind the built-in web server of PHP.

## Layout

```
contract/     API contract (es/en), signature test cases and checklist
src/          client, errors, webhook verification, receiver and duplicate control
examples/     request-tow.php and webhook-server.php
tests/        tests (PHPUnit), fake server and the run of the examples
```

The `contract/` folder is the same in the examples of every language and is not edited here.

## License

[MIT](LICENSE)
