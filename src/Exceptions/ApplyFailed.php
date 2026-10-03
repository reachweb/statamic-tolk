<?php

namespace Reach\StatamicTolk\Exceptions;

use RuntimeException;

final class ApplyFailed extends RuntimeException
{
    /**
     * save() returned false: a listener of EntryCreating or EntrySaving vetoed the save.
     */
    public static function vetoed(string $target): self
    {
        return new self("Saving [{$target}] was cancelled by a listener.");
    }
}
