<?php

namespace Reach\StatamicTolk\Tests\Support\Handlers;

use Closure;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Statamic\Fields\Field;

/**
 * Maps a group's value map with its nested fields.
 */
final class FakeGroupHandler implements FieldHandler
{
    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        return $context->walker->mapFields($field->fieldtype()->fields(), $value, $translate, $context);
    }
}
