# GRUALINK API: ejemplo en PHP

[English](README.en.md)

Ejemplo listo para clonar y correr que integra tu sistema (aseguradora, asistencia, integrador) con la
API de GRUALINK: **pedir una grúa**, **consultar el estatus** y **recibir avisos firmados**.

Guía pública de la API: <https://grualink.mx/api>

Qué trae:

- Un **cliente mínimo** (`src/GrualinkClient.php`): crea solicitudes con `Idempotency-Key`, consulta el
  estatus, errores tipados, reintentos con espera exponencial y tiempo máximo. Usa la extensión cURL de
  PHP; sin dependencias de ejecución.
- Un **receptor de avisos** (`src/functions.php`, `src/WebhookHandler.php`): verifica la firma sobre el
  cuerpo crudo, valida la ventana de tiempo, soporta el cambio de secreto y descarta duplicados.
- Dos **ejemplos ejecutables** (`examples/`).
- El **kit de contrato** (`contract/`): el contrato de la API en español e inglés y los casos de prueba
  de firma que comparten los ejemplos de todos los lenguajes.

No es un SDK: es código pensado para leerse y copiarse a tu proyecto.

## Requisitos

- PHP 8.1 o superior, con las extensiones `curl` y `json`.
- [Composer](https://getcomposer.org/) 2.
- Una **llave de prueba** (`glk_test_…`). Te la da la empresa de grúas con la que trabajas.
- Para los avisos: el **secreto de firma** (`whsec_…`) que te entrega la misma empresa al registrar la
  dirección de tu sistema.

## Instalación

```bash
git clone <la dirección de este repositorio>
cd grualink-api-php
composer install
```

## Variables de entorno

Solo los nombres; los valores van en tu equipo y nunca en el repositorio. Hay una plantilla en
`.env.example`. Los ejemplos leen las variables del entorno del proceso (no cargan archivos `.env`).

| Variable | Para qué |
|---|---|
| `GRUALINK_API_KEY` | Tu llave de **prueba** (`glk_test_…`) |
| `GRUALINK_WEBHOOK_SECRET` | Secreto de firma de los avisos (`whsec_…`), completo, con su prefijo |
| `GRUALINK_WEBHOOK_SECRET_PREVIOUS` | Opcional: el secreto anterior mientras dura un cambio de secreto |
| `GRUALINK_API_BASE_URL` | Opcional: otra dirección base (por omisión `https://grualink.mx/api/v1`) |
| `GRUALINK_WEBHOOK_STATE_DIR` | Opcional: carpeta donde el receptor de ejemplo recuerda los avisos ya procesados |

## Correr los ejemplos

### Pedir una grúa

```bash
export GRUALINK_API_KEY=...   # tu llave de prueba
php examples/request-tow.php
```

Con datos ficticios, el ejemplo envía una solicitud, imprime el precio estimado, la vuelve a enviar con
la misma `Idempotency-Key` para mostrar que no se duplica y consulta su estatus. Solo corre con llaves
de prueba.

Con una llave de prueba, el inicio de `external_ref` decide el estatus final: `TEST-CONFIRMAR`,
`TEST-EN-CURSO`, `TEST-TERMINAR`, `TEST-RECHAZAR`, `TEST-CANCELAR`.

### Recibir avisos

```bash
export GRUALINK_WEBHOOK_SECRET=...   # tu secreto de firma
php -S localhost:8787 examples/webhook-server.php
```

Queda escuchando en `http://localhost:8787/webhooks/grualink` con el servidor web integrado de PHP (solo
para desarrollo). GRUALINK solo entrega a direcciones `https` públicas en el puerto 443: publica el
receptor detrás de tu dominio (o de un túnel `https` mientras pruebas), dale esa dirección a la empresa
y pídele que te mande un aviso de prueba.

## Uso en tu código

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
        idempotencyKey: $miRegistro->claveDeIdempotencia, // guárdala antes de llamar
    );
    $solicitud = $result->serviceRequest;   // arreglo con los nombres del contrato
    echo $solicitud['id'], ' ', $solicitud['status'], ' ', $solicitud['quote']['estimated_price']['total'] ?? '-';
    var_dump($result->replayed);
} catch (GrualinkValidationError $error) {
    print_r($error->details);
}
```

Las solicitudes y las respuestas son arreglos con los mismos nombres de campo del contrato
(`external_ref`, `service_request`…); no se traducen.

### Idempotencia y reintentos

- Cada solicitud lleva una `Idempotency-Key` tuya. Si repites la llamada con la misma clave y el mismo
  cuerpo en 24 horas, la API responde **`200`** con `Idempotent-Replayed: true` y no crea nada
  (`replayed` es `true`). La misma clave con otro cuerpo responde `409`.
- El cliente reintenta solo lo que tiene sentido: errores de red, tiempo agotado, `5xx` y
  `429 rate_limited` (esperando lo que diga `Retry-After`). Usa espera exponencial con jitter y reenvía
  siempre la misma clave y los mismos bytes: el cuerpo se serializa una sola vez.
- No sigue redirecciones y cada intento tiene un tiempo máximo (15 segundos por omisión).
- La llave nunca aparece en errores ni registros, ni al imprimir el cliente (`var_dump`, `print_r`).

### Verificar un aviso

```php
use function Grualink\verifyWebhookSignature;

$result = verifyWebhookSignature(
    rawBody: file_get_contents('php://input'),                    // los bytes tal como llegaron
    signatureHeader: $_SERVER['HTTP_X_GRUALINK_SIGNATURE'] ?? null,
    secret: getenv('GRUALINK_WEBHOOK_SECRET'),                    // completo, con whsec_
);
// $result->valid (bool) y $result->reason ('ok', 'signature_mismatch', ...)
```

Lo importante:

1. Verifica el **cuerpo crudo**, antes de convertirlo a JSON. Si lo conviertes y lo vuelves a
   serializar, la firma ya no coincide.
2. Usa el secreto **completo**, con su prefijo `whsec_`.
3. Rechaza marcas de tiempo con más de 5 minutos de diferencia.
4. Tras un cambio de secreto llegan dos firmas `v1` durante 24 horas: acepta si cualquiera coincide.
5. Los avisos pueden repetirse: guarda el `id` de los ya procesados y responde `2xx` sin reprocesar.
6. Responde `2xx` en menos de 10 segundos y haz el trabajo pesado después.

La comparación se hace con `hash_equals` (tiempo constante). La función nunca lanza excepciones:
siempre devuelve un resultado con su motivo.

### PHP plano

`serveWebhook()` lee la petición actual (`$_SERVER` y `php://input`), la pasa al manejador y escribe la
respuesta. Es lo que usa `examples/webhook-server.php`:

```php
use Grualink\FileDeliveryDeduplicator;

use function Grualink\createWebhookHandler;
use function Grualink\serveWebhook;

$handler = createWebhookHandler(
    secret: getenv('GRUALINK_WEBHOOK_SECRET'),
    onEvent: fn (array $event) => $miCola->encolar($event),   // rápido; el trabajo pesado va después
    deduplicator: new FileDeliveryDeduplicator('/var/lib/mi-app/avisos-grualink'),
);

serveWebhook($handler, path: '/webhooks/grualink');
```

En PHP cada petición empieza de cero, así que la memoria del proceso **no sirve** para recordar qué
avisos ya llegaron. `FileDeliveryDeduplicator` los recuerda en disco (un servidor);
`InMemoryDeliveryDeduplicator` solo sirve en procesos que siguen vivos entre peticiones. Con varios
servidores, implementa `DeliveryDeduplicator` con tu base de datos: basta una clave única sobre el `id`
del aviso.

### Montarlo en Laravel o Symfony

El punto delicado es conseguir el **cuerpo crudo**. `createWebhookHandler` no depende de ningún
framework: recibe los bytes y las cabeceras y devuelve el estatus y el cuerpo de la respuesta.

Laravel: usa `$request->getContent()` (no `$request->all()`, `$request->input()` ni `$request->json()`,
que entregan el cuerpo ya convertido). Declara la ruta en `routes/api.php` para que no pase por la
verificación CSRF.

```php
Route::post('/webhooks/grualink', function (Illuminate\Http\Request $request) use ($handler) {
    $result = $handler->handle($request->getContent(), $request->headers->all());

    return response()->json($result->body, $result->status);
});
```

Para los duplicados puedes apoyarte en la caché compartida, que tiene una operación atómica:

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

Symfony: igual, con `$request->getContent()` (no `$request->toArray()` ni `$request->getPayload()`).

```php
#[Route('/webhooks/grualink', methods: ['POST'])]
public function grualink(Request $request): JsonResponse
{
    $result = $this->handler->handle($request->getContent(), $request->headers->all());

    return new JsonResponse($result->body, $result->status);
}
```

En ambos casos limita el tamaño del cuerpo a 64 KB en tu servidor web y no pongas delante ningún
middleware que reescriba el cuerpo.

## Errores

Todos los errores de la API tienen la forma `{ "error": { "code", "message", "details"? } }`. El `code`
es estable; decide con él, no con el `message`.

Las excepciones de la API (`GrualinkApiError` y sus subclases) llevan `status`, `details`,
`retryAfterSeconds` y `attempts` como propiedades; el `code` se lee con `getCode()` (una cadena, por
ejemplo `validation_error`) y el `message` con `getMessage()`.

| HTTP | `code` | Qué pasó | Clase | ¿Se reintenta? |
|---|---|---|---|---|
| 400 | `invalid_request` | La petición no es válida | `GrualinkInvalidRequestError` | no |
| 400 | `invalid_json` | El cuerpo no es JSON válido | `GrualinkInvalidRequestError` | no |
| 400 | `key_in_query` | La llave se envió en la URL; pide que la revoquen | `GrualinkInvalidRequestError` | no |
| 400 | `idempotency_key_required` | Falta `Idempotency-Key` | `GrualinkInvalidRequestError` | no |
| 400 | `invalid_idempotency_key` | `Idempotency-Key` inválida (1 a 255 caracteres ASCII visibles) | `GrualinkInvalidRequestError` | no |
| 401 | `unauthorized` | Llave ausente, inválida, vencida o revocada | `GrualinkAuthenticationError` | no |
| 403 | `insufficient_scope` | La llave no tiene permiso para esto | `GrualinkPermissionError` | no |
| 403 | `customer_unavailable` | Tu cuenta de cliente con la empresa no está disponible | `GrualinkPermissionError` | no |
| 403 | `live_mode_unavailable` | Las solicitudes reales aún no están habilitadas | `GrualinkPermissionError` | no |
| 403 | `browser_not_allowed` | Llave real usada desde un navegador | `GrualinkPermissionError` | no |
| 404 | `not_found` | No existe o no es tuya | `GrualinkNotFoundError` | no |
| 405 | `method_not_allowed` | Método no permitido para esa ruta | `GrualinkInvalidRequestError` | no |
| 409 | `idempotency_conflict` | Esa `Idempotency-Key` ya se usó con otro cuerpo | `GrualinkIdempotencyConflictError` | no |
| 413 | `payload_too_large` | El cuerpo excede 16 KB | `GrualinkInvalidRequestError` | no |
| 415 | `unsupported_media_type` | El cuerpo debe ir como `application/json` | `GrualinkInvalidRequestError` | no |
| 422 | `validation_error` | Campos inválidos; `details` dice cuáles | `GrualinkValidationError` | no |
| 429 | `rate_limited` | Demasiadas peticiones; espera `Retry-After` | `GrualinkRateLimitError` | sí |
| 429 | `too_many_open_requests` | Demasiadas solicitudes sin resolver con la empresa | `GrualinkRateLimitError` | no |
| 500 | `internal_error` | Error interno | `GrualinkServerError` | sí |

Además: `GrualinkConnectionError` (sin respuesta, se reintenta), `GrualinkTimeoutError` (tiempo agotado,
se reintenta) y `GrualinkConfigError` (configuración o argumento inválido; no se envió nada). Todas
heredan de `GrualinkError`. Una respuesta de error con otra forma usa el `code` `unexpected_response`.

El contrato completo, con campos, límites y el orden de las comprobaciones, está en
[`contract/API.es.md`](contract/API.es.md).

## Pruebas

```bash
composer test     # PHPUnit
composer lint     # estilo PSR-12
composer smoke    # corre los dos ejemplos contra un servidor falso local
```

Las pruebas no llaman a la API real: usan un servidor falso local. Cubren todos los casos de
`contract/signature-vectors.json`, cada código de error, los reintentos, la idempotencia y que la llave
no aparezca en errores ni registros. El receptor se prueba también por HTTP, detrás del servidor web
integrado de PHP.

## Estructura

```
contract/     contrato de la API (es/en), casos de prueba de firma y lista de verificación
src/          cliente, errores, verificación de avisos, receptor y control de duplicados
examples/     request-tow.php y webhook-server.php
tests/        pruebas (PHPUnit), servidor falso y corrida de los ejemplos
```

La carpeta `contract/` es la misma en los ejemplos de todos los lenguajes y no se edita aquí.

## Licencia

[MIT](LICENSE)
