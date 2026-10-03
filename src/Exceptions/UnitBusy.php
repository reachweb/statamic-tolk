<?php

namespace Reach\StatamicTolk\Exceptions;

use RuntimeException;

final class UnitBusy extends RuntimeException
{
    public static function for(string $planId, string $unitId): self
    {
        return new self("The unit [{$unitId}] of the plan [{$planId}] is already running.");
    }
}
