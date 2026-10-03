<?php

namespace Reach\StatamicTolk\Exceptions;

use InvalidArgumentException;

final class UnknownUnit extends InvalidArgumentException
{
    public static function in(string $planId, string $unitId): self
    {
        return new self("The plan [{$planId}] has no unit [{$unitId}].");
    }
}
