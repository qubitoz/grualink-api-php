<?php

declare(strict_types=1);

namespace Grualink;

/**
 * Base class of every error thrown by this library.
 */
class GrualinkError extends \Exception
{
    /** Number of HTTP attempts made before giving up (0 when no request was sent). */
    public int $attempts = 0;

    public function __construct(string $message)
    {
        parent::__construct($message);

        // Call arguments are dropped from the stack trace, so a secret passed to a function can never
        // ride along in an error, whatever zend.exception_ignore_args says.
        $trace = new \ReflectionProperty(\Exception::class, 'trace');
        $frames = [];
        foreach ($this->getTrace() as $frame) {
            unset($frame['args']);
            $frames[] = $frame;
        }
        $trace->setValue($this, $frames);
    }
}
