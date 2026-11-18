<?php

declare(strict_types=1);

namespace Grualink;

/**
 * No response within the configured timeout. Retried automatically.
 */
class GrualinkTimeoutError extends GrualinkError
{
}
