<?php

namespace Reach\StatamicTolk\Content\Handlers;

use Closure;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Statamic\Fields\Field;

/**
 * Maps each row with the grid's fields, under {…path, index}. The row `id` is no field, so it is never touched.
 */
final class GridHandler implements FieldHandler
{
    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return $value;
        }

        $fieldtype = $field->fieldtype();

        foreach ($value as $index => $row) {
            if (is_array($row)) {
                $value[$index] = $context->walker->mapFields($fieldtype->fields($index), $row, $translate, $context->with($index));
            }
        }

        return $value;
    }
}
