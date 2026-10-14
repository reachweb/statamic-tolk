<?php

namespace Reach\StatamicTolk\Support;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\Formality;
use Reach\StatamicTolk\Exceptions\InvalidSettings;
use Statamic\Addons\FileSettingsRepository;
use Statamic\Contracts\Addons\Settings as AddonSettings;
use Statamic\Contracts\Addons\SettingsRepository;
use Statamic\Facades\Addon;
use Throwable;

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
        'translated_slugs' => [],
        'auto_update' => false,
        'auto_update_create' => false,
        'auto_update_collections' => [],
        'auto_update_publish' => false,
    ];

    /**
     * The tags that let a setting hold `{{` and `}}` that are not Antlers: core parses every setting as Antlers.
     */
    private const string NOPARSE_TAG = '/\{\{\s*\/?\s*noparse\s*\}\}/';

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
        $settings = $this->stored();

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
        return self::prose($this->values()['site_profile']);
    }

    public function instructions(): string
    {
        return self::prose($this->values()['instructions']);
    }

    /**
     * @return array{formality: Formality, instructions: string}
     */
    public function languageInstructions(string $site): array
    {
        $row = Arr::first($this->rows('language_instructions'), fn (array $row) => self::siteOf($row) === $site) ?? [];

        return [
            'formality' => Formality::tryFrom(self::text($row['formality'] ?? null)) ?? Formality::Default,
            'instructions' => self::prose($row['instructions'] ?? null),
        ];
    }

    /**
     * @return list<array{term: string, site: ?string, translation: string, do_not_translate: bool, note: string}>
     */
    public function glossary(string $site): array
    {
        return collect($this->rows('glossary'))
            ->map(fn (array $row) => [
                'term' => self::prose($row['term'] ?? null),
                'site' => self::siteOf($row),
                'translation' => self::prose($row['translation'] ?? null),
                'do_not_translate' => filter_var($row['do_not_translate'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'note' => self::prose($row['note'] ?? null),
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
     * Whether the settings keep the entries of this collection, or the terms of this taxonomy, out of every translation.
     */
    public function excludes(ContentType $type, string $container): bool
    {
        return match ($type) {
            ContentType::Entry => in_array($container, $this->excludedCollections(), true),
            ContentType::Term => in_array($container, $this->excludedTaxonomies(), true),
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public function excludedFields(): array
    {
        return $this->handles('excluded_fields');
    }

    /**
     * Whether a new localization of this collection gets a slug made from its translated title (D16).
     */
    public function translatesSlugs(string $collection): bool
    {
        return in_array($collection, $this->handles('translated_slugs'), true);
    }

    /**
     * Whether saving a published entry in its origin updates its translations (T11.1).
     */
    public function autoUpdate(): bool
    {
        return $this->bool('auto_update');
    }

    /**
     * Whether an automatic update also creates the localizations the entry has not got yet.
     */
    public function autoUpdateCreate(): bool
    {
        return $this->bool('auto_update_create');
    }

    /**
     * @return list<string> the collections whose entries are updated automatically; empty for every collection
     */
    public function autoUpdateCollections(): array
    {
        return $this->handles('auto_update_collections');
    }

    /**
     * Whether an automatic update of a published localization with revisions is published (ApplyOptions::$publishChanges).
     */
    public function autoUpdatePublish(): bool
    {
        return $this->bool('auto_update_publish');
    }

    private function values(): array
    {
        return $this->values ??= array_merge(self::DEFAULTS, Arr::whereNotNull($this->stored()?->raw() ?? []));
    }

    /**
     * The addon's settings as core reads them. Core parses every value as Antlers on the way, so a text written into
     * the file by hand that is not Antlers fails the read: the exception names the file.
     */
    private function stored(): ?AddonSettings
    {
        $addon = Addon::get(self::PACKAGE);

        try {
            return $addon?->settings();
        } catch (Throwable $exception) {
            $file = app(SettingsRepository::class) instanceof FileSettingsRepository
                ? Str::after(resource_path("addons/{$addon->slug()}.yaml"), base_path().DIRECTORY_SEPARATOR)
                : null;

            throw InvalidSettings::unreadable($file, $exception);
        }
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

    /**
     * Whether the user may edit these settings: the ability core registers for an addon's settings (§A14).
     */
    public static function editableBy(?Authorizable $user): bool
    {
        return (bool) $user?->can('editSettings', Addon::get(self::PACKAGE));
    }

    public static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * A text someone wrote for the model, without the `{{ noparse }}` tags that let it hold `{{` and `}}`.
     */
    public static function prose(mixed $value): string
    {
        return trim(preg_replace(self::NOPARSE_TAG, '', self::text($value)));
    }
}
