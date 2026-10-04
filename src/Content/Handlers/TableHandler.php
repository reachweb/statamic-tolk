<?php

namespace Reach\StatamicTolk\Content\Handlers;

use Closure;
use Reach\StatamicTolk\Content\Handlers\Concerns\MakesLeaves;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Statamic\Fields\Field;

/**
 * Each string cell of `[{cells: […]}]` is a `plain` leaf, under {…path, row, 'cells', column}.
 */
final class TableHandler implements FieldHandler
{
    use MakesLeaves;

    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return $value;
        }

        $hint = $this->hint($field->fieldtype());

        foreach ($value as $row => $item) {
            $cells = is_array($item) ? ($item['cells'] ?? null) : null;

            if (! is_array($cells) || ! array_is_list($cells)) {
                continue;
            }

            $cellsContext = $context->with($row)->with('cells');

            foreach ($cells as $column => $cell) {
                if (is_string($cell)) {
                    $value[$row]['cells'][$column] = $this->plain($cell, $hint, $translate, $cellsContext->with($column));
                }
            }
        }

        return $value;
    }
}
