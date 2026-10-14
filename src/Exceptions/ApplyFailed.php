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
        return new self(__('Saving [:target] was cancelled by a listener.', ['target' => $target]));
    }

    // Core's validateTree() rejected a navigation's tree, which it would otherwise have saved and then failed to read.
    public static function invalidTree(string $target, Throwable $previous): self
    {
        return new self(__('The tree of [:target] was not saved: :reason', ['target' => $target, 'reason' => $previous->getMessage()]), previous: $previous);
    }

    public static function notWritten(string $file, Throwable $previous): self
    {
        return new self("The translation file [{$file}] could not be written: {$previous->getMessage()}", previous: $previous);
    }
}
