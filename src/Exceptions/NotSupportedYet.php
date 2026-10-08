<?php

namespace Reach\StatamicTolk\Exceptions;

use LogicException;
use Reach\StatamicTolk\Enums\ContentType;

final class NotSupportedYet extends LogicException
{
    public static function contentType(ContentType $type): self
    {
        return new self(__('Translating content of the type [:type] is not supported yet.', ['type' => $type->value]));
    }
}
