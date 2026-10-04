<?php

namespace Reach\StatamicTolk\Content\Handlers;

use Closure;
use Reach\StatamicTolk\Content\Handlers\Concerns\MakesLeaves;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Statamic\Fields\Field;

/**
 * Each string value is a `plain` leaf, labelled with its key: under {…path, key} in the dynamic and keyed shapes
 * (`{key: value}`), under {…path, index, 'value'} in the expanded one (`[{key, value}]`). Keys are free text that may
 * hold dots, so the value is walked directly and keys are never translated.
 */
final class ArrayHandler implements FieldHandler
{
    use MakesLeaves;

    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $fieldtype = $field->fieldtype();
        $hint = $this->hint($fieldtype);
        $labels = $this->labels($fieldtype->config('keys'));

        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $value[$key] = $this->plain($item, $hint, $translate, $context->with($key, $this->label($key, $labels)));
            } elseif (is_array($item) && is_string($item['value'] ?? null)) {
                $item['value'] = $this->plain($item['value'], $hint, $translate, $context->with($key, $this->label($item['key'] ?? null, $labels))->with('value'));
                $value[$key] = $item;
            }
        }

        return $value;
    }

    /**
     * The labels a keyed array configures, as `key: label` or as `[{key, value}]`.
     */
    private function labels(mixed $keys): array
    {
        $labels = [];

        foreach (is_array($keys) ? $keys : [] as $key => $label) {
            if (is_array($label)) {
                [$key, $label] = [$label['key'] ?? null, $label['value'] ?? null];
            }

            if ((is_string($key) || is_int($key)) && is_string($label) && trim($label) !== '') {
                $labels[$key] = trim($label);
            }
        }

        return $labels;
    }

    private function label(mixed $key, array $labels): ?string
    {
        if (! is_string($key) && ! is_int($key)) {
            return null;
        }

        return $labels[$key] ?? (string) $key;
    }
}
