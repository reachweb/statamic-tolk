<?php

namespace Reach\StatamicTolk\Exceptions;

use RuntimeException;
use Throwable;

final class ApplyFailed extends RuntimeException
{
    /**
     * save() or publish() returned false: a listener of EntryCreating, EntrySaving, RevisionSaving, TermSaving,
     * GlobalVariablesCreating or GlobalVariablesSaving vetoed the save.
     */
    public static function vetoed(string $target): self
    {
        return new self("Saving [{$target}] was cancelled by a listener.");
    }

    public static function notWritten(string $file, Throwable $previous): self
    {
        return new self("The translation file [{$file}] could not be written: {$previous->getMessage()}", previous: $previous);
    }
}
