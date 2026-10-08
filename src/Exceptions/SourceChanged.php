<?php

namespace Reach\StatamicTolk\Exceptions;

use RuntimeException;

/**
 * The source of a field changed after its translation was planned; the target is planned again.
 */
final class SourceChanged extends RuntimeException
{
    private function __construct(string $message, public readonly string $handle)
    {
        parent::__construct($message);
    }

    public static function field(string $handle): self
    {
        return new self(__('The source of the field [:handle] changed after its translation was planned.', ['handle' => $handle]), $handle);
    }
}
