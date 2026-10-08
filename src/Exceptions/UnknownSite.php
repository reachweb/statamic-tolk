<?php

namespace Reach\StatamicTolk\Exceptions;

use InvalidArgumentException;

final class UnknownSite extends InvalidArgumentException
{
    public static function for(string $handle): self
    {
        return new self(__('Unknown site [:site].', ['site' => $handle]));
    }
}
