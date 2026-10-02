<?php

namespace Reach\StatamicTolk\Exceptions;

use InvalidArgumentException;

final class InvalidModelRef extends InvalidArgumentException
{
    public static function for(string $reference): self
    {
        return new self("Invalid model reference [{$reference}]. Use provider:model, where the provider is anthropic, openai or gemini.");
    }
}
