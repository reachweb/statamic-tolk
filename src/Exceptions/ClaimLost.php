<?php

namespace Reach\StatamicTolk\Exceptions;

use RuntimeException;

/**
 * The caller no longer holds the run item's claim: it expired and another worker took the item over, or the item moved on.
 */
final class ClaimLost extends RuntimeException
{
    public static function for(string $runId, int $index): self
    {
        return new self("The claim on item [{$index}] of the run [{$runId}] is no longer held by this worker.");
    }
}
