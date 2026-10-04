<?php

namespace Reach\StatamicTolk\Content\Handlers;

use Closure;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Statamic\Fields\Field;

/**
 * Maps a group's map of values with its fields, under {…path, handle}.
 */
final class GroupHandler implements FieldHandler
{
    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            return $value;
        }

        return $context->walker->mapFields($field->fieldtype()->fields(), $value, $translate, $context);
    }
}
