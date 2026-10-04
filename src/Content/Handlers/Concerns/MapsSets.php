<?php

namespace Reach\StatamicTolk\Content\Handlers\Concerns;

use Closure;
use Reach\StatamicTolk\Content\WalkContext;
use Statamic\Fields\Fieldtype;
use Statamic\Support\Str;

trait MapsSets
{
    /**
     * Maps the values of one Replicator or Bard set with the fields of its set, under {…path, index, …$under}, labelled
     * with the set's display. Values of an unknown set are untouched, and fields() is never asked for them.
     *
     * @param  Fieldtype  $fieldtype  a Replicator, or a fieldtype extending it
     */
    private function mapSet(Fieldtype $fieldtype, array $values, int $index, Closure $translate, WalkContext $context, string ...$under): array
    {
        $handle = $values['type'] ?? null;
        $config = is_string($handle) ? $fieldtype->flattenedSetsConfig()->get($handle) : null;

        if (! is_array($config)) {
            return $values;
        }

        $context = $context->with($index, $config['display'] ?? __(Str::slugToTitle($handle)));

        foreach ($under as $segment) {
            $context = $context->with($segment);
        }

        return $context->walker->mapFields($fieldtype->fields($handle, $index), $values, $translate, $context);
    }
}
