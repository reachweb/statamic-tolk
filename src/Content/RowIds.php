<?php

namespace Reach\StatamicTolk\Content;

use Facades\Statamic\Fieldtypes\RowId;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Replicator;

/**
 * A value rebuilt from the source structure has the source's row and set ids, and none where the source stores none
 * (content written by code). The CP's pre-processing gives such a row a new random id on every save, so the target's
 * own ids are carried over first (§A11 step 3).
 */
final class RowIds
{
    /**
     * The value with the id of the target's row or set at the same position, of the same set type, on each Replicator
     * or Grid row and Bard set that has none, at any depth.
     */
    public static function keep(Field $field, mixed $value, mixed $existing): mixed
    {
        if (! is_array($value) || ! is_array($existing)) {
            return $value;
        }

        $fieldtype = $field->fieldtype();

        return match (true) {
            ! array_is_list($value) => $fieldtype instanceof Group ? self::fields($fieldtype->fields(), $value, $existing) : $value,
            $fieldtype instanceof Bard => self::bard($fieldtype, $value, $existing),
            $fieldtype instanceof Replicator => self::replicator($fieldtype, $value, $existing),
            $fieldtype instanceof Grid => self::grid($fieldtype, $value, $existing),
            default => $value,
        };
    }

    private static function replicator(Replicator $fieldtype, array $rows, array $existing): array
    {
        foreach ($rows as $index => $row) {
            $old = $existing[$index] ?? null;
            $type = is_array($row) ? $row['type'] ?? null : null;

            if (! is_string($type) || ! is_array($old) || ($old['type'] ?? null) !== $type) {
                continue;
            }

            $rows[$index] = self::withId($row, $old);

            if (is_array($fieldtype->flattenedSetsConfig()->get($type))) {
                $rows[$index] = self::fields($fieldtype->fields($type, $index), $rows[$index], $old);
            }
        }

        return $rows;
    }

    private static function grid(Grid $fieldtype, array $rows, array $existing): array
    {
        foreach ($rows as $index => $row) {
            $old = $existing[$index] ?? null;

            if (is_array($row) && is_array($old)) {
                $rows[$index] = self::fields($fieldtype->fields($index), self::withId($row, $old), $old);
            }
        }

        return $rows;
    }

    // Sets are matched by their position among the sets, so a paragraph added or removed around them moves none.
    private static function bard(Bard $fieldtype, array $nodes, array $existing): array
    {
        $sets = array_values(array_filter($existing, fn (mixed $node) => is_array($node) && ($node['type'] ?? null) === 'set'));
        $count = 0;

        foreach ($nodes as $index => $node) {
            if (! is_array($node) || ($node['type'] ?? null) !== 'set') {
                continue;
            }

            $old = $sets[$count++] ?? null;
            $type = $node['attrs']['values']['type'] ?? null;

            if (! is_string($type) || ($old['attrs']['values']['type'] ?? null) !== $type) {
                continue;
            }

            if (! isset($node['attrs']['id']) && isset($old['attrs']['id'])) {
                $nodes[$index]['attrs'] = ['id' => $old['attrs']['id']] + $node['attrs'];
            }

            if (is_array($fieldtype->flattenedSetsConfig()->get($type))) {
                $nodes[$index]['attrs']['values'] = self::fields($fieldtype->fields($type, $index), $node['attrs']['values'], $old['attrs']['values']);
            }
        }

        return $nodes;
    }

    private static function fields(Fields $fields, array $values, array $existing): array
    {
        foreach ($values as $handle => $value) {
            if (isset($existing[$handle]) && ($field = $fields->get($handle)) !== null) {
                $values[$handle] = self::keep($field, $value, $existing[$handle]);
            }
        }

        return $values;
    }

    private static function withId(array $row, array $old): array
    {
        $key = RowId::handle();

        return isset($row[$key]) || ! isset($old[$key]) ? $row : [$key => $old[$key]] + $row;
    }
}
