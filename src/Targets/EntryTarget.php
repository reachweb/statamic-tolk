<?php

namespace Reach\StatamicTolk\Targets;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use LogicException;
use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\SourceSummary;
use Reach\StatamicTolk\Content\TranslatableFields;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\ApplyFailed;
use Reach\StatamicTolk\Exceptions\NotSupportedYet;
use Reach\StatamicTolk\Exceptions\RootMissing;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\State\PendingState;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Translation\AssembledField;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
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
        private readonly ContentWalker $walker,
        private readonly StatusResolver $resolver,
        private readonly StateWriter $stateWriter,
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
        return new self($this->sourceId, $this->site, $this->translatableFields, $this->summary, $this->settings, $this->walker, $this->resolver, $this->stateWriter);
    }

    /**
     * @param  list<AssembledField>  $fields
     */
    public function apply(array $fields, ApplyOptions $options): ApplyResult
    {
        if (! ContentWriter::active()) {
            throw new LogicException('Translations are applied only inside ContentWriter::write().');
        }

        return $this->fresh()->applyFresh($fields, $options);
    }

    /**
     * True when creating the localization would make it the home page of the target site in place of the origin tree's
     * root (§A11 "Root page").
     */
    public function rootMissing(): bool
    {
        if ($this->exists()) {
            return false;
        }

        $structure = $this->source()->collection()->structure();

        if ($structure === null || ! $structure->expectsRoot() || ($structure->in($this->site)?->tree() ?? []) !== []) {
            return false;
        }

        return $this->sourceRootId() !== (string) $this->source()->id();
    }

    /**
     * @param  list<AssembledField>  $fields
     */
    private function applyFresh(array $fields, ApplyOptions $options): ApplyResult
    {
        $source = $this->source();

        if ($source->revisionsEnabled()) {
            throw NotSupportedYet::revisions($source->collectionHandle());
        }

        $this->stateWriter->confirm($this);
        $fields = $this->keyByHandle($fields);
        $created = ! $this->exists();
        $warnings = $created ? $this->createLocalization($options) : [];
        $localization = $this->localization();
        $ref = $this->ref();

        $writes = $skipped = [];

        foreach ($fields as $handle => $field) {
            if ($this->targetHashNow($handle) !== $field->targetBefore && ! $options->overwriteManual) {
                $skipped[$handle] = 'target_changed';
            } else {
                $writes[$handle] = $field;
            }
        }

        $values = $this->normalise($localization, array_map(
            fn (AssembledField $field) => $field->value,
            array_filter($writes, fn (AssembledField $field) => ! $field->remove),
        ));

        foreach ($values as $handle => $value) {
            $this->stateWriter->recordPending($ref, $this->sourceSite(), $handle, $this->pending($writes[$handle], $value));
        }

        $publish = $created && $this->publishesAsOrigin($localization, $writes, $options);

        if ($writes !== [] || $publish) {
            foreach ($writes as $handle => $field) {
                $field->remove ? $localization->remove($handle) : $localization->set($handle, $values[$handle]);
            }

            if ($publish) {
                $localization->published($source->published());
            }

            $this->save($localization, $options);

            $removed = array_keys(array_filter($writes, fn (AssembledField $field) => $field->remove));

            if ($removed !== []) {
                $this->stateWriter->forget($ref, $removed);
            }

            $this->stateWriter->confirm($this->fresh());
        }

        return new ApplyResult(
            ref: $ref,
            created: $created,
            slug: $created ? $localization->slug() : null,
            applied: array_keys($writes),
            skipped: $skipped,
            warnings: $warnings,
        );
    }

    /**
     * @param  list<AssembledField>  $fields
     * @return array<string, AssembledField>
     */
    private function keyByHandle(array $fields): array
    {
        $keyed = [];

        foreach ($fields as $field) {
            if (! $this->fields()->has($field->handle)) {
                throw SourceChanged::field($field->handle);
            }

            $keyed[$field->handle] = $field;
        }

        return $keyed;
    }

    /**
     * Saves the new localization once, with no data of its own and as a draft, as the CP's localize action does, so it
     * has a reference and its place in the tree before any field is written. Returns the warnings.
     *
     * @return list<string>
     */
    private function createLocalization(ApplyOptions $options): array
    {
        if ($this->rootMissing()) {
            $root = Entry::find($this->sourceRootId() ?? '');

            throw RootMissing::for($root?->id(), (string) ($root?->value('title') ?? $root?->slug() ?? 'the root page'), $this->siteName());
        }

        // The id comes first: an unsaved entry shares the Blink keys of its origin and blueprint with every other (§A11 "Create").
        $localization = $this->source()->makeLocalization($this->site)->id(Stache::generateId())->published(false);
        $warnings = [];
        $slug = $localization->slug();

        if (is_string($slug) && $slug !== '' && $this->slugTaken($slug)) {
            $suffix = 2;

            while ($this->slugTaken("{$slug}-{$suffix}")) {
                $suffix++;
            }

            $localization->slug("{$slug}-{$suffix}");
            $warnings[] = "The slug \"{$slug}\" is already used in {$this->siteName()}, so the new localization got \"{$slug}-{$suffix}\".";
        }

        $this->save($localization, $options);

        $this->localization = $localization;
        $this->localizationResolved = true;
        $this->fields = null;

        return $warnings;
    }

    private function slugTaken(string $slug): bool
    {
        return Entry::query()
            ->where('collection', $this->container())
            ->where('site', $this->site)
            ->where('slug', $slug)
            ->count() > 0;
    }

    private function save(EntryContract $localization, ApplyOptions $options): void
    {
        if ($options->user !== null) {
            $localization->updateLastModified($options->user);
        }

        if ($localization->save() === false) {
            throw ApplyFailed::vetoed('entry::'.$localization->id());
        }
    }

    private function targetHashNow(string $handle): ?string
    {
        return $this->hasOverride($handle) ? $this->resolver->targetHash($this->fields()->get($handle), $this->targetValue($handle)) : null;
    }

    /**
     * The values as a load and save in the CP leaves them (§A11 step 3), so a later CP save does not rewrite them.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalise(EntryContract $localization, array $values): array
    {
        if ($values === []) {
            return [];
        }

        // Every entry of a collection shares one blueprint object, so the parent is set right before fields().
        $processed = $localization->blueprint()->setParent($localization)->fields()
            ->addValues($values)
            ->preProcess()
            ->process()
            ->values()
            ->all();

        return array_intersect_key($processed, $values);
    }

    private function pending(AssembledField $field, mixed $value): PendingState
    {
        return new PendingState(
            plan: $field->plan,
            sourceHash: $field->sourceHash,
            targetHash: $this->resolver->targetHash($this->fields()->get($field->handle), $value),
            targetBefore: $field->targetBefore,
            segments: $field->segments,
            identical: $field->identical,
            incomplete: $field->incomplete,
            translator: $field->translator,
            reviewer: null,
            createdAt: CarbonImmutable::now(),
        );
    }

    /**
     * D10: a new localization mirrors its origin's published state only when the operation asks for it, the user may
     * publish, and every in-scope field with source text was delivered complete.
     *
     * @param  array<string, AssembledField>  $writes
     */
    private function publishesAsOrigin(EntryContract $localization, array $writes, ApplyOptions $options): bool
    {
        if ($options->newStatus !== 'mirror' || ($options->user !== null && ! $options->user->can('publish', $localization))) {
            return false;
        }

        foreach ($this->fields() as $handle => $field) {
            $delivered = $writes[$handle] ?? null;

            if ($delivered === null ? $this->walker->leaves($field, $this->sourceValue($handle)) !== [] : $delivered->incomplete) {
                return false;
            }
        }

        return true;
    }

    private function sourceRootId(): ?string
    {
        $source = $this->source();
        $root = $source->collection()->structure()?->in($source->locale())?->root();

        return isset($root['entry']) ? (string) $root['entry'] : null;
    }

    private function siteName(): string
    {
        return (string) (Site::get($this->site)?->name() ?? $this->site);
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
