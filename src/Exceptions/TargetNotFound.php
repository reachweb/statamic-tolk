<?php

namespace Reach\StatamicTolk\Exceptions;

use RuntimeException;

final class TargetNotFound extends RuntimeException
{
    public static function entry(string $id): self
    {
        return new self("Entry [{$id}] not found.");
    }
}
