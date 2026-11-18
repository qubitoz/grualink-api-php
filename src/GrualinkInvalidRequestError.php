<?php

declare(strict_types=1);

namespace Grualink;

/**
 * 400, 405, 413, 415: the request is malformed. Fix the request; do not retry.
 */
class GrualinkInvalidRequestError extends GrualinkApiError
{
}
