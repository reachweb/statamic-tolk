<?php

namespace Reach\StatamicTolk\Tests\Support\Handlers;

use Closure;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Statamic\Fields\Field;

/**
 * Maps each grid row with the grid's fields, under the path {handle}.{index}.
 */
final class FakeGridHandler implements FieldHandler
{
    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $index => $row) {
            if (is_array($row)) {
                $value[$index] = $context->walker->mapFields($field->fieldtype()->fields($index), $row, $translate, $context->with($index));
            }
        }

        return $value;
    }
}
