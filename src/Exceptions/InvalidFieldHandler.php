<?php

namespace Reach\StatamicTolk\Exceptions;

use InvalidArgumentException;
use Reach\StatamicTolk\Contracts\FieldHandler;

final class InvalidFieldHandler extends InvalidArgumentException
{
    public static function for(string $handle, string $handlerClass): self
    {
        return new self("The handler [{$handlerClass}] for the fieldtype [{$handle}] does not implement ".FieldHandler::class.'.');
    }
}
