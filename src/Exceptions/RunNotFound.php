<?php

namespace Reach\StatamicTolk\Exceptions;

use RuntimeException;

final class RunNotFound extends RuntimeException
{
    public static function for(string $id): self
    {
        return new self("Run [{$id}] not found.");
    }

    public static function item(string $id, int $index): self
    {
        return new self("The run [{$id}] has no item [{$index}].");
    }
}
