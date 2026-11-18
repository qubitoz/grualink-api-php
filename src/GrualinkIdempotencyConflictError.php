<?php

declare(strict_types=1);

namespace Grualink;

/**
 * 409 `idempotency_conflict`: the Idempotency-Key was already used with a different body.
 */
class GrualinkIdempotencyConflictError extends GrualinkApiError
{
}
