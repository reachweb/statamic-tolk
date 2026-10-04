<?php

namespace Reach\StatamicTolk\Content\Handlers;

use Closure;
use Reach\StatamicTolk\Content\Handlers\Concerns\MakesLeaves;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Statamic\Fields\Field;

/**
 * Each string item is a `plain` leaf, under {…path, index}. The CP stores numeric items as numbers, which stay untouched.
 */
final class ListHandler implements FieldHandler
{
    use MakesLeaves;

    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return $value;
        }

        $hint = $this->hint($field->fieldtype());

        foreach ($value as $index => $item) {
            if (is_string($item)) {
                $value[$index] = $this->plain($item, $hint, $translate, $context->with($index));
            }
        }

        return $value;
    }
}
