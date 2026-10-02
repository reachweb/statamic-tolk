<?php

namespace Reach\StatamicTolk\Exceptions;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Reach\StatamicTolk\Ai\ModelRef;

final class InvalidModelRef extends InvalidArgumentException
{
    public static function for(string $reference): self
    {
        $providers = Arr::join(ModelRef::PROVIDERS, ', ', ' or ');

        return new self("Invalid model reference [{$reference}]. Use provider:model, where the provider is {$providers}.");
    }
}
