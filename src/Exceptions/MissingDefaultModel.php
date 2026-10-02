<?php

namespace Reach\StatamicTolk\Exceptions;

use LogicException;
use Reach\StatamicTolk\Enums\Tier;

final class MissingDefaultModel extends LogicException
{
    public static function for(string $provider, Tier $tier): self
    {
        return new self("The model catalog (tolk.models) has no {$tier->value} model for [{$provider}].");
    }
}
