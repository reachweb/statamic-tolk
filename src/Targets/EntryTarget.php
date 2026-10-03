<?php

namespace Reach\StatamicTolk\Targets;

use Illuminate\Support\Collection;
use LogicException;
use Reach\StatamicTolk\Content\SourceSummary;
use Reach\StatamicTolk\Content\TranslatableFields;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;

/**
 * Build it with TargetFactory, which validates the source and the site.
 */
final class EntryTarget implements Target
{
    private ?EntryContract $entry = null;

    private ?EntryContract $localization = null;

    private bool $localizationResolved = false;

    private ?Collection $fields = null;

    public function __construct(
        private readonly string $sourceId,
        private readonly string $site,
        private readonly TranslatableFields $translatableFields,
        private readonly SourceSummary $summary,
        private readonly Settings $settings,
    ) {}

    public function ref(): ?TargetRef
    {
        $localization = $this->localization();

        return $localization === null ? null : new TargetRef(ContentType::Entry, (string) $localization->id());
    }

    public function type(): ContentType
    {
        return ContentType::Entry;
    }

    public function exists(): bool
    {
        return $this->localization() !== null;
    }

    public function sourceSite(): string
    {
        return $this->source()->locale();
    }

    public function targetSite(): string
    {
        return $this->site;
    }

    public function container(): string
    {
        return $this->source()->collectionHandle();
    }

    public function title(): string
    {
        $source = $this->source();
        $title = $source->value('title');

        return is_string($title) && trim($title) !== '' ? $title : (string) ($source->slug() ?? $source->id());
    }

    public function editUrl(): ?string
    {
        return $this->localization()?->editUrl();
    }

    public function describe(): array
    {
        return ['type' => ContentType::Entry->value, 'source_id' => $this->sourceId, 'site' => $this->site];
    }

    public function fields(): Collection
    {
        return $this->fields ??= $this->translatableFields->for($this->blueprint(), $this->fieldContext());
    }

    public function explainFields(): array
    {
        return $this->translatableFields->explain($this->blueprint(), $this->fieldContext());
    }

    public function sourceHasValue(string $handle): bool
    {
        return $this->source()->has($handle);
    }

    public function sourceValue(string $handle): mixed
    {
        return $this->source()->get($handle);
    }

    public function sourceIsLocalization(): bool
    {
        return $this->source()->hasOrigin();
    }

    public function hasOverride(string $handle): bool
    {
        return $this->localization()?->has($handle) ?? false;
    }

    public function targetValue(string $handle): mixed
    {
        return $this->localization()?->get($handle);
    }

    public function document(): array
    {
        $source = $this->source();

        return [
            'type' => ContentType::Entry->value,
            'title' => $this->title(),
            'container' => (string) $source->collection()->title(),
            'url' => $source->url(),
            'summary' => $this->summary->of($this),
        ];
    }

    public function authorize(User $user): bool
    {
        $site = Site::get($this->site);

        return $site !== null
            && $user->can('view', $site)
            && $user->can('edit', $this->localization() ?? $this->source());
    }

    public function fresh(): static
    {
        return new self($this->sourceId, $this->site, $this->translatableFields, $this->summary, $this->settings);
    }

    public function apply(array $fields, ApplyOptions $options): ApplyResult
    {
        throw new LogicException('Applying translations to entries is not implemented yet.');
    }

    private function entry(): EntryContract
    {
        return $this->entry ??= Entry::find($this->sourceId) ?? throw TargetNotFound::entry($this->sourceId);
    }

    private function localization(): ?EntryContract
    {
        if (! $this->localizationResolved) {
            $this->localization = $this->entry()->in($this->site);
            $this->localizationResolved = true;
        }

        return $this->localization;
    }

    // D3: an existing localization is translated from its own origin, which in a chain need not be the entry this target was built from.
    private function source(): EntryContract
    {
        return $this->localization()?->origin() ?? $this->entry();
    }

    // Never from an unsaved makeLocalization() result: unsaved entries share the Blink keys of their blueprint and origin (§A11 "Create").
    private function blueprint(): Blueprint
    {
        $entry = $this->localization() ?? $this->source();

        return $entry->blueprint()->setParent($entry);
    }

    private function fieldContext(): array
    {
        return [
            'auto_title' => $this->source()->collection()->autoGeneratesTitles(),
            'excluded_fields' => $this->settings->excludedFields(),
        ];
    }
}
