<?php

namespace Reach\StatamicTolk\Tests\Support\Handlers;

use Closure;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Reach\StatamicTolk\Enums\SegmentKind;
use Statamic\Fields\Field;

/**
 * One plain leaf per string value; warns about any other non-null value.
 */
final class FakeTextHandler implements FieldHandler
{
    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        if (! is_string($value)) {
            if ($value !== null) {
                $context->warn("{$context->label()} is not a string.");
            }

            return $value;
        }

        return $translate(new Leaf($value, SegmentKind::Plain, $context->path(), $context->label()));
    }
}
