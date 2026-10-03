<?php

namespace Reach\StatamicTolk\Tests\Support\Handlers;

use Closure;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Statamic\Fields\Field;

/**
 * Maps each replicator row with the fields of its set, under the path {handle}.{index}.
 */
final class FakeReplicatorHandler implements FieldHandler
{
    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $index => $row) {
            if (is_array($row) && is_string($row['type'] ?? null)) {
                $value[$index] = $context->walker->mapFields($field->fieldtype()->fields($row['type'], $index), $row, $translate, $context->with($index));
            }
        }

        return $value;
    }
}
