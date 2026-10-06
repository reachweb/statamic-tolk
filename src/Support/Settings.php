<?php

namespace Reach\StatamicTolk\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Enums\Formality;
use Statamic\Facades\Addon;

class Settings
{
    public const string PACKAGE = 'reachweb/statamic-tolk';

    public const array NEW_LOCALIZATION_STATUSES = ['draft', 'mirror'];

    public const array DEFAULTS = [
        'translator_model' => null,
        'review_enabled' => false,
        'reviewer_model' => null,
        'fallback_model' => null,
        'new_localization_status' => 'draft',
        'offer_on_create' => true,
        'strict_fields' => false,
        'site_profile' => '',
        'instructions' => '',
        'language_instructions' => [],
        'glossary' => [],
        'excluded_collections' => [],
        'excluded_taxonomies' => [],
        'excluded_fields' => [],
    ];

    private ?array $values = null;

    public function fresh(): static
    {
        $this->values = null;

        return $this;
    }

    /**
     * Saves the given keys to the addon settings and keeps every other key as it is stored.
     */
    public function save(array $values): void
    {
        $settings = Addon::get(self::PACKAGE)->settings();

        foreach ($values as $key => $value) {
            $settings->set($key, $value);
        }

        $settings->save();
        $this->fresh();
    }

    public function translatorModel(): ?ModelRef
    {
        return $this->model('translator_model');
    }

    public function reviewEnabled(): bool
    {
        return $this->bool('review_enabled');
    }

    public function reviewerModel(): ?ModelRef
    {
        return $this->model('reviewer_model');
    }

    public function fallbackModel(): ?ModelRef
    {
        return $this->model('fallback_model');
    }

    public function newLocalizationStatus(): string
    {
        $status = $this->string('new_localization_status');

        return in_array($status, self::NEW_LOCALIZATION_STATUSES, true)
            ? $status
            : self::DEFAULTS['new_localization_status'];
    }

    public function offerOnCreate(): bool
    {
        return $this->bool('offer_on_create');
    }

    public function strictFields(): bool
    {
        return $this->bool('strict_fields');
    }

    public function siteProfile(): string
    {
        return $this->string('site_profile');
    }

    public function instructions(): string
    {
        return $this->string('instructions');
    }

    /**
     * @return array{formality: Formality, instructions: string}
     */
    public function languageInstructions(string $site): array
    {
        $row = Arr::first($this->rows('language_instructions'), fn (array $row) => self::siteOf($row) === $site) ?? [];

        return [
            'formality' => Formality::tryFrom(self::text($row['formality'] ?? null)) ?? Formality::Default,
            'instructions' => self::text($row['instructions'] ?? null),
        ];
    }

    /**
     * @return list<array{term: string, site: ?string, translation: string, do_not_translate: bool, note: string}>
     */
    public function glossary(string $site): array
    {
        return collect($this->rows('glossary'))
            ->map(fn (array $row) => [
                'term' => self::text($row['term'] ?? null),
                'site' => self::siteOf($row),
                'translation' => self::text($row['translation'] ?? null),
                'do_not_translate' => filter_var($row['do_not_translate'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'note' => self::text($row['note'] ?? null),
            ])
            ->filter(fn (array $row) => $row['term'] !== ''
                && ($row['translation'] !== '' || $row['do_not_translate'])
                && in_array($row['site'], [null, $site], true))
            ->sortBy(fn (array $row) => implode("\0", [
                Str::lower($row['term']),
                $row['term'],
                $row['site'] ?? '',
                $row['translation'],
                $row['note'],
                (int) $row['do_not_translate'],
            ]), SORT_STRING)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function excludedCollections(): array
    {
        return $this->handles('excluded_collections');
    }

    /**
     * @return list<string>
     */
    public function excludedTaxonomies(): array
    {
        return $this->handles('excluded_taxonomies');
    }

    /**
     * @return list<string>
     */
    public function excludedFields(): array
    {
        return $this->handles('excluded_fields');
    }

    private function values(): array
    {
        return $this->values ??= array_merge(
            self::DEFAULTS,
            Arr::whereNotNull(Addon::get(self::PACKAGE)?->settings()->raw() ?? []),
        );
    }

    private function model(string $key): ?ModelRef
    {
        $reference = $this->string($key);

        return $reference === '' ? null : ModelRef::fromString($reference);
    }

    private function bool(string $key): bool
    {
        return filter_var($this->values()[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? self::DEFAULTS[$key];
    }

    private function string(string $key): string
    {
        return self::text($this->values()[$key]);
    }

    private function rows(string $key): array
    {
        $rows = $this->values()[$key];

        return is_array($rows) ? array_filter($rows, 'is_array') : [];
    }

    private function handles(string $key): array
    {
        return collect(Arr::wrap($this->values()[$key]))
            ->map(fn ($handle) => self::text($handle))
            ->reject(fn (string $handle) => $handle === '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The site of a grid row: a `sites` field with one item stores a string, and the form sends a list.
     */
    public static function siteOf(array $row): ?string
    {
        $site = self::text(Arr::first(Arr::wrap($row['site'] ?? null)));

        return $site === '' ? null : $site;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
