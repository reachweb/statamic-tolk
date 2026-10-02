<?php

namespace Reach\StatamicTolk\Exceptions;

use RuntimeException;

final class LeafCountMismatch extends RuntimeException
{
    public static function for(string $handle, int $leaves, int $translations): self
    {
        return new self("The field [{$handle}] has {$leaves} leaves, but {$translations} translations were given.");
    }
}
