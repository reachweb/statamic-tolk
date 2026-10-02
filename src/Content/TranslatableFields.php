<?php

namespace Reach\StatamicTolk\Content;

use Illuminate\Support\Collection;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

final class TranslatableFields
{
    public function __construct(private readonly FieldtypeRegistry $registry) {}

    /**
     * @param  array{auto_title?: bool, excluded_fields?: list<string>}  $context
     * @return Collection<string, Field>
     */
    public function for(Blueprint $blueprint, array $context = []): Collection
    {
        return $blueprint->fields()->all()
            ->filter(fn (Field $field) => $this->reason($field, $context) === 'ok');
    }

    /**
     * @param  array{auto_title?: bool, excluded_fields?: list<string>}  $context
     * @return list<array{handle: string, display: mixed, type: string, in_scope: bool, reason: string}>
     */
    public function explain(Blueprint $blueprint, array $context = []): array
    {
        return $blueprint->fields()->all()
            ->map(function (Field $field) use ($context) {
                $reason = $this->reason($field, $context);

                return [
                    'handle' => $field->handle(),
                    'display' => $field->display(),
                    'type' => $field->type(),
                    'in_scope' => $reason === 'ok',
                    'reason' => $reason,
                ];
            })
            ->values()
            ->all();
    }

    // The first reason that applies wins. `not_localizable` comes last, so it means the field would be in scope if it were localizable (the audit relies on that).
    private function reason(Field $field, array $context): string
    {
        return match (true) {
            $field->handle() === 'slug' => 'slug',
            $field->handle() === 'title' && ($context['auto_title'] ?? false) => 'auto_title',
            ! $this->registry->has($field->type()) => 'unsupported_fieldtype',
            $field->visibility() === 'computed' => 'computed',
            $field->visibility() === 'hidden' => 'hidden',
            $field->fieldtype()->config('tolk_translate') === false => 'disabled',
            in_array($field->handle(), $context['excluded_fields'] ?? [], true) => 'excluded',
            ! $field->isLocalizable() => 'not_localizable',
            default => 'ok',
        };
    }
}
