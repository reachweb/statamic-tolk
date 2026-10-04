<?php

namespace Reach\StatamicTolk\Content;

use Closure;
use Reach\StatamicTolk\Exceptions\LeafCountMismatch;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

final class ContentWalker
{
    private array $warnings = [];

    public function __construct(private readonly FieldtypeRegistry $registry) {}

    /**
     * Maps one top-level field. $target marks a walk over a target's value (§A3 "Target-side walks").
     *
     * @param  Closure(Leaf): string  $translate
     */
    public function mapField(Field $field, mixed $value, Closure $translate, bool $target = false): mixed
    {
        $context = new WalkContext($this, $target);

        try {
            return $this->map($field, $value, $translate, $context->with($field->handle(), $field->display()));
        } finally {
            $this->warnings = $context->warnings();
        }
    }

    /**
     * @param  Closure(Leaf): string  $translate
     */
    public function mapFields(Fields $fields, array $values, Closure $translate, WalkContext $context): array
    {
        foreach ($values as $key => $value) {
            $field = $fields->get($key);

            if ($field !== null && $this->maps($field, $value, $context)) {
                $values[$key] = $this->map($field, $value, $translate, $context->with($key, $field->display()));
            }
        }

        return $values;
    }

    /**
     * @return list<Leaf>
     */
    public function leaves(Field $field, mixed $value, bool $target = false): array
    {
        $leaves = [];

        $this->mapField($field, $value, function (Leaf $leaf) use (&$leaves): string {
            $leaves[] = $leaf;

            return $leaf->text;
        }, $target);

        return $leaves;
    }

    public function inject(Field $field, mixed $value, array $translations, bool $target = false): mixed
    {
        $translations = array_values($translations);
        $count = 0;

        $result = $this->mapField($field, $value, function (Leaf $leaf) use ($translations, &$count): string {
            $index = $count++;

            return array_key_exists($index, $translations) ? $translations[$index] : $leaf->text;
        }, $target);

        if ($count !== count($translations)) {
            throw LeafCountMismatch::for($field->handle(), $count, count($translations));
        }

        return $result;
    }

    public function warnings(): array
    {
        return $this->warnings;
    }

    private function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        $handler = $this->registry->for($field);

        return $handler === null ? $value : $handler->map($field, $value, $translate, $context);
    }

    private function maps(Field $field, mixed $value, WalkContext $context): bool
    {
        return $this->registry->translates($field) && ! ($context->target && $this->holdsDefault($field, $value));
    }

    // Set fields added to a blueprint later get their default filled into existing rows by the CP; on a target that text is not a translation.
    private function holdsDefault(Field $field, mixed $value): bool
    {
        return isset($field->config()['default'])
            && ! $field->hasComputedDefault()
            && $value === $field->defaultValue();
    }
}
