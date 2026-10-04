<?php

namespace Reach\StatamicTolk\Content\Handlers;

use Closure;
use Reach\StatamicTolk\Content\Handlers\Concerns\MapsSets;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Statamic\Fields\Field;

/**
 * Maps each set with the fields of its set type, disabled sets included. The set's `id`, `type` and `enabled` are no
 * fields, so they are never touched.
 */
final class ReplicatorHandler implements FieldHandler
{
    use MapsSets;

    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return $value;
        }

        $fieldtype = $field->fieldtype();

        foreach ($value as $index => $set) {
            if (is_array($set)) {
                $value[$index] = $this->mapSet($fieldtype, $set, $index, $translate, $context);
            }
        }

        return $value;
    }
}
