<?php

declare(strict_types=1);

namespace Grualink;

/**
 * 5xx: retried automatically; safe because the same Idempotency-Key is reused.
 */
class GrualinkServerError extends GrualinkApiError
{
}
