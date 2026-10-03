<?php

namespace Reach\StatamicTolk\Exceptions;

use RuntimeException;

/**
 * A provider call that failed for a reason that passes. No outcome is stored; the caller runs the same unit again after
 * `$seconds` (§A8, §A10).
 */
abstract class TransientProviderFailure extends RuntimeException
{
    final protected function __construct(string $message, public readonly string $provider, public readonly int $seconds)
    {
        parent::__construct($message);
    }
}
