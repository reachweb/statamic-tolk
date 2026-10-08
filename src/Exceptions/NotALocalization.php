<?php

namespace Reach\StatamicTolk\Exceptions;

use InvalidArgumentException;

final class NotALocalization extends InvalidArgumentException
{
    public static function for(string $reference): self
    {
        return new self(__('[:reference] has no origin, so it is not a localization that can be translated into.', ['reference' => $reference]));
    }
}
