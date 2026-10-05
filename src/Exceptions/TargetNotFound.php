<?php

namespace Reach\StatamicTolk\Exceptions;

use RuntimeException;

final class TargetNotFound extends RuntimeException
{
    public static function entry(string $id): self
    {
        return new self("Entry [{$id}] not found.");
    }

    public static function term(string $id, ?string $site = null): self
    {
        return new self($site === null ? "Term [{$id}] not found." : "Term [{$id}] not found in the site [{$site}].");
    }
}
