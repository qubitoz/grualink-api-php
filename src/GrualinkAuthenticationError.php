<?php

declare(strict_types=1);

namespace Grualink;

/**
 * 401 `unauthorized`: key missing, invalid, expired or revoked (always the same answer).
 */
class GrualinkAuthenticationError extends GrualinkApiError
{
}
