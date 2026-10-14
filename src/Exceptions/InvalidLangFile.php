<?php

namespace Reach\StatamicTolk\Exceptions;

use RuntimeException;
use Throwable;

final class InvalidLangFile extends RuntimeException
{
    public static function unreadable(string $name, ?Throwable $previous = null): self
    {
        return new self("The translation file [{$name}] could not be read: it must ".(str_ends_with($name, '.json') ? 'hold a JSON object.' : 'return an array.'), previous: $previous);
    }

    public static function unexportable(string $name, string $key): self
    {
        return new self("The translation file [{$name}] cannot be written: the value of [{$key}] is not a string, number, boolean, null or array.");
    }

    public static function unholdable(string $name, string $key): self
    {
        return new self("The translation file [{$name}] cannot be written: it cannot hold [{$key}] next to the other keys.");
    }
}
