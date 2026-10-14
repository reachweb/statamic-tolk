<?php

namespace Reach\StatamicTolk\Exceptions;

use Reach\StatamicTolk\Translation\UnitOutcome;
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
        $parts = implode(', ', array_map(fn (string $id, string $reason) => $id.' ('.UnitOutcome::reasonLabel($reason).')', array_keys($reasons), $reasons));

        return new self(__('The field [:handle] could not be translated completely: :parts.', ['handle' => $handle, 'parts' => $parts]), $handle, $reasons);
    }
}
