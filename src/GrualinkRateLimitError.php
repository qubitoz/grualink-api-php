<?php

declare(strict_types=1);

namespace Grualink;

/**
 * 429 `rate_limited` (retried automatically) or `too_many_open_requests` (not retried).
 */
class GrualinkRateLimitError extends GrualinkApiError
{
}
