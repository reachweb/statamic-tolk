<?php

namespace Reach\StatamicTolk\Exceptions;

use RuntimeException;

/**
 * Another process held the write lock for longer than the writer waits (§A11).
 */
final class WriterBusy extends RuntimeException
{
    public static function waited(int $seconds): self
    {
        return new self("Another process is saving content; the write lock was not free within {$seconds} seconds.");
    }
}
