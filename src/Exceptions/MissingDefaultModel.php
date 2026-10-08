<?php

namespace Reach\StatamicTolk\Exceptions;

use LogicException;
use Reach\StatamicTolk\Enums\Tier;

final class MissingDefaultModel extends LogicException
{
    public static function for(string $provider, Tier $tier): self
    {
        return new self(__('The model catalog (tolk.models) has no :tier model for [:provider].', ['tier' => $tier->value, 'provider' => $provider]));
    }
}
