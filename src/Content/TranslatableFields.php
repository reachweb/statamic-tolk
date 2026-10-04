<?php

namespace Reach\StatamicTolk\Content;

use Illuminate\Support\Collection;
use Reach\StatamicTolk\Content\Handlers\TextHandler;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Grid;
use Statamic\Fieldtypes\Group;
use Statamic\Fieldtypes\Replicator;
use Statamic\Support\Str;

final class TranslatableFields
{
    private const array TECHNICAL_WORDS = ['class', 'id', 'anchor', 'url', 'link', 'icon', 'color', 'handle', 'slug', 'key', 'code', 'embed', 'video', 'layout'];

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

    /**
     * Nested fields that would be translated although their handle names data rather than text, as suggestions to
     * switch translation off. Only the handle's last word counts: `css_class` and `anchor` look technical, `link_text`
     * and `key_points` do not. Containers (Bard, Replicator, Grid, Group) are walked, never listed; set handles are
     * part of the path, Grid rows are not.
     *
     * @return list<array{path: string, handle: string, type: string, label: string}>
     */
    public function nestedTechnicalFields(Blueprint $blueprint): array
    {
        $found = [];

        foreach ($blueprint->fields()->all() as $field) {
            if (in_array($this->reason($field, []), ['ok', 'not_localizable'], true)) {
                $this->collectTechnical($this->nestedFields($field) ?? [], [$field->handle()], [$field->display()], $found);
            }
        }

        return $found;
    }

    /**
     * @param  list<array{0: list<string>, 1: list<string>, 2: Fields}>  $nested
     */
    private function collectTechnical(array $nested, array $path, array $labels, array &$found): void
    {
        foreach ($nested as [$segments, $setLabels, $fields]) {
            foreach ($fields->all() as $field) {
                if (! $this->registry->translates($field)) {
                    continue;
                }

                $handle = $field->handle();
                $fieldPath = [...$path, ...$segments, $handle];
                $fieldLabels = [...$labels, ...$setLabels, $field->display()];

                if (($children = $this->nestedFields($field)) !== null) {
                    $this->collectTechnical($children, $fieldPath, $fieldLabels, $found);
                } elseif ($this->looksTechnical($handle) && $this->holdsText($field)) {
                    $found[] = [
                        'path' => implode('.', $fieldPath),
                        'handle' => $handle,
                        'type' => $field->type(),
                        'label' => implode(' › ', $fieldLabels),
                    ];
                }
            }
        }
    }

    /**
     * The fields of a container by set (Replicator and Bard) or as one group (Grid and Group), or null for a field that
     * holds no fields.
     *
     * @return ?list<array{0: list<string>, 1: list<string>, 2: Fields}>
     */
    private function nestedFields(Field $field): ?array
    {
        $fieldtype = $field->fieldtype();

        if ($fieldtype instanceof Replicator) {
            return $fieldtype->flattenedSetsConfig()
                ->filter(fn ($config) => is_array($config))
                ->map(fn (array $config, int|string $set) => [[(string) $set], [$config['display'] ?? __(Str::slugToTitle((string) $set))], $fieldtype->fields($set)])
                ->values()
                ->all();
        }

        return $fieldtype instanceof Grid || $fieldtype instanceof Group ? [[[], [], $fieldtype->fields()]] : null;
    }

    private function looksTechnical(string $handle): bool
    {
        return in_array(Str::afterLast(strtolower($handle), '_'), self::TECHNICAL_WORDS, true);
    }

    // A text field with another input type (url, email) is never translated, so there is nothing to switch off.
    private function holdsText(Field $field): bool
    {
        return ! $this->registry->for($field) instanceof TextHandler || TextHandler::holdsProse($field->fieldtype()->config());
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
