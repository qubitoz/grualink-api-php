<?php

declare(strict_types=1);

namespace Grualink;

/**
 * 403: the key is not allowed to do this (scope, customer unavailable, live mode, browser).
 */
class GrualinkPermissionError extends GrualinkApiError
{
}
