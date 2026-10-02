<?php

namespace Reach\StatamicTolk\Exceptions;

use InvalidArgumentException;

final class InvalidTargetRef extends InvalidArgumentException
{
    public static function for(string $reference): self
    {
        return new self("Invalid target reference [{$reference}]. Use entry::{id}, term::{taxonomy}::{slug}::{site}, globals::{handle}::{site} or nav::{handle}::{site}.");
    }
}
