<?php

namespace Reach\StatamicTolk\Exceptions;

use LogicException;
use Reach\StatamicTolk\Enums\ContentType;

final class NotSupportedYet extends LogicException
{
    public static function contentType(ContentType $type): self
    {
        return new self("Translating content of the type [{$type->value}] is not supported yet.");
    }

    public static function revisions(string $collection): self
    {
        return new self("Saving translations into the collection [{$collection}], which has revisions enabled, is not supported yet.");
    }
}
