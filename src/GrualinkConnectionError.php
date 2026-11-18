<?php

declare(strict_types=1);

namespace Grualink;

/**
 * The request never got an HTTP response (DNS, connection reset, TLS...). Retried automatically.
 */
class GrualinkConnectionError extends GrualinkError
{
}
