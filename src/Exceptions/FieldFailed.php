<?php

namespace Reach\StatamicTolk\Exceptions;

use RuntimeException;

/**
 * With `strict_fields` on, a field with a part that could not be translated is not delivered (D28).
 */
final class FieldFailed extends RuntimeException
{
    /**
     * @param  array<string, string>  $reasons  segment id → why it failed
     */
    private function __construct(string $message, public readonly string $handle, public readonly array $reasons)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, string>  $reasons
     */
    public static function for(string $handle, array $reasons): self
    {
        $parts = implode(', ', array_map(fn (string $id, string $reason) => "{$id} ({$reason})", array_keys($reasons), $reasons));

        return new self("The field [{$handle}] could not be translated completely: {$parts}.", $handle, $reasons);
    }
}
