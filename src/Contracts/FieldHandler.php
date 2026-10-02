<?php

namespace Reach\StatamicTolk\Contracts;

use Closure;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\WalkContext;
use Statamic\Fields\Field;

interface FieldHandler
{
    /**
     * Returns $value with each leaf replaced by $translate(Leaf), and nothing else changed.
     * When $translate returns each leaf's own text, the result must be identical (===) to $value.
     *
     * @param  Closure(Leaf): string  $translate
     */
    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed;
}
