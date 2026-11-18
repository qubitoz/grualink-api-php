<?php

declare(strict_types=1);

namespace Grualink;

/**
 * The API answered with an error.
 *
 * `getCode()` returns the stable machine-readable code from `{ "error": { "code", "message", "details"? } }`
 * as a string (for example `validation_error`); `getMessage()` is written for people and may change. When
 * the body is not the documented error shape, the code is `unexpected_response`.
 *
 * @method string getCode()
 */
class GrualinkApiError extends GrualinkError
{
    /** HTTP status of the answer. */
    public readonly int $status;

    /**
     * Invalid fields of a 400/422 answer. `field` is a dotted path such as `contact.phone`; `issue` is one of
     * required, invalid_type, too_long, invalid_format, out_of_range, unknown_field, invalid_value, duplicate.
     *
     * @var list<array{field: string, issue: string}>
     */
    public readonly array $details;

    /** Value of the `Retry-After` header in seconds, when present. */
    public readonly ?int $retryAfterSeconds;

    /**
     * @param list<array{field: string, issue: string}> $details
     */
    final public function __construct(
        int $status,
        string $code,
        string $message,
        array $details = [],
        ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
        $this->status = $status;
        $this->code = $code;
        $this->details = $details;
        $this->retryAfterSeconds = $retryAfterSeconds;
    }

    /**
     * Maps an HTTP status to the error class that represents it.
     *
     * @param list<array{field: string, issue: string}> $details
     */
    public static function fromStatus(
        int $status,
        string $code,
        string $message,
        array $details = [],
        ?int $retryAfterSeconds = null,
    ): self {
        $class = match (true) {
            $status === 401 => GrualinkAuthenticationError::class,
            $status === 403 => GrualinkPermissionError::class,
            $status === 404 => GrualinkNotFoundError::class,
            $status === 409 => GrualinkIdempotencyConflictError::class,
            $status === 422 => GrualinkValidationError::class,
            $status === 429 => GrualinkRateLimitError::class,
            $status >= 500 => GrualinkServerError::class,
            in_array($status, [400, 405, 413, 415], true) => GrualinkInvalidRequestError::class,
            default => self::class,
        };

        return new $class($status, $code, $message, $details, $retryAfterSeconds);
    }
}
