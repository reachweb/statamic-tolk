<?php

namespace Reach\StatamicTolk\Targets;

use Illuminate\Support\Collection;
use LogicException;
use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\SourceSummary;
use Reach\StatamicTolk\Content\TranslatableFields;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\ApplyFailed;
use Reach\StatamicTolk\Exceptions\RootMissing;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\State\PendingState;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Translation\AssembledField;
use Reach\StatamicTolk\Translation\TargetResult;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Revisions\Revision;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Fields;
use Statamic\Support\Arr;

/**
 * Build it with TargetFactory, which validates the source and the site.
 */
final class EntryTarget implements Target
{
    private ?EntryContract $entry = null;

    private ?EntryContract $localization = null;

    private bool $localizationResolved = false;

    private ?EntryContract $working = null;

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
        return $this->working()?->has($handle) ?? false;
    }

    public function targetValue(string $handle): mixed
    {
        return $this->working()?->get($handle);
    }

    // The source's own data merged over its origins', as the CP's edit page reads it; value() would add the collection's
    // injected values. That page also drops a root's null, '' and [] values, so the field shows its default.
    public function inheritedValue(string $handle): mixed
    {
        for ($entry = $this->source(); $entry !== null; $entry = $entry->origin()) {
            if ($entry->has($handle)) {
                return $entry->isRoot() ? Arr::removeNullValues([$handle => $entry->get($handle)])[$handle] ?? null : $entry->get($handle);
            }
        }

        return null;
    }

    public function formFields(array $values, bool $source = false): Fields
    {
        $item = $this->localization() ?? $this->source();

        // Every entry of a collection shares one blueprint object, so the parent is set right before fields().
        return $item->blueprint()
            ->setParent($source ? $this->source() : $item->fromWorkingCopy())
            ->fields()
            ->addValues($values);
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
     * @throws RootMissing when rootMissing() is true, naming the root page to translate first
     */
    public function ensureRootExists(): void
    {
        if ($this->rootMissing()) {
            $root = Entry::find($this->sourceRootId() ?? '');

            throw RootMissing::for($root?->id(), (string) ($root?->value('title') ?? $root?->slug() ?? 'the root page'), $this->siteName());
        }
    }

    /**
     * @param  list<AssembledField>  $fields
     */
    private function applyFresh(array $fields, ApplyOptions $options): ApplyResult
    {
        $this->stateWriter->confirm($this);
        $fields = $this->keyByHandle($fields);
        $created = ! $this->exists();
        $warnings = $created ? $this->createLocalization($options) : [];
        $localization = $this->localization();
        $item = $this->working();
        $hadWorkingCopy = $localization->revisionsEnabled() && $localization->hasWorkingCopy();
        $ref = $this->ref();

        $writes = $skipped = [];

        foreach ($fields as $handle => $field) {
            if ($this->resolver->currentTargetHash($this, $this->fields()->get($handle)) !== $field->targetBefore && ! $options->overwriteManual) {
                $skipped[$handle] = TargetResult::TARGET_CHANGED;
            } else {
                $writes[$handle] = $field;
            }
        }

        $values = $this->normalise($item, array_map(
            fn (AssembledField $field) => $field->value,
            array_filter($writes, fn (AssembledField $field) => ! $field->remove),
        ));

        foreach ($values as $handle => $value) {
            $this->stateWriter->recordPending($ref, $this->sourceSite(), $handle, $this->pending($writes[$handle], $value));
        }

        $mirror = $created && $this->publishesAsOrigin($localization, $writes, $options);

        if ($writes !== [] || $mirror) {
            foreach ($writes as $handle => $field) {
                $field->remove ? $item->remove($handle) : $item->set($handle, $values[$handle]);
            }

            array_push($warnings, ...$this->saveChanges($item, $mirror, $hadWorkingCopy, $options));

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
     * has a reference and its place in the tree before any field is written; with revisions on, it gets a revision, as
     * store() gives it. Returns the warnings.
     *
     * @return list<string>
     */
    private function createLocalization(ApplyOptions $options): array
    {
        $this->ensureRootExists();

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

        // As store() does, which returns the revision's result and so hides a save that a listener cancelled.
        if ($localization->revisionsEnabled()) {
            $this->saveRevision($localization->makeRevision(), $localization, $options);
        }

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

    /**
     * Saves the written fields as the CP would (D26, §A11 "Revisions"). Returns the warnings.
     *
     * @return list<string>
     */
    private function saveChanges(EntryContract $item, bool $mirror, bool $hadWorkingCopy, ApplyOptions $options): array
    {
        if (! $item->revisionsEnabled()) {
            if ($mirror) {
                $item->published($this->source()->published());
            }

            $this->save($item, $options);

            return [];
        }

        if ($mirror && $this->source()->published()) {
            $this->publish($item, $options);

            return [];
        }

        if (! $item->published()) {
            $this->save($item, $options);

            return [];
        }

        $this->saveRevision($item->makeWorkingCopy(), $item, $options, $this->revisionMessage());

        if (! $options->publishChanges) {
            return [];
        }

        // publish() publishes the whole working copy, which may hold somebody else's changes that nobody approved.
        if ($hadWorkingCopy) {
            return ["The {$this->siteName()} localization already had unpublished changes, so the translation was added to them and not published."];
        }

        if ($options->user !== null && ! $options->user->can('publish', $item)) {
            return ['The translation was saved as unpublished changes: the user who started it may not publish this entry.'];
        }

        $this->publish($item, $options);

        return [];
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

    private function saveRevision(Revision $revision, EntryContract $item, ApplyOptions $options, ?string $message = null): void
    {
        if ($revision->user($options->user)->message($message)->save() === false) {
            throw ApplyFailed::vetoed('entry::'.$item->id());
        }
    }

    // Publishes the working copy, or the item itself when it has none, with a revision (Revisable::publishWorkingCopy()).
    private function publish(EntryContract $item, ApplyOptions $options): void
    {
        if ($item->publish(['user' => $options->user, 'message' => $this->revisionMessage()]) === false) {
            throw ApplyFailed::vetoed('entry::'.$item->id());
        }
    }

    private function revisionMessage(): string
    {
        return 'Translated from '.(Site::get($this->sourceSite())?->name() ?? $this->sourceSite()).' with Tolk';
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
        return $field->pending($this->resolver->targetHash($this->fields()->get($field->handle), $value));
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

    // What the CP's edit page shows and saves: with revisions on, the localization as its working copy has it (§A6).
    private function working(): ?EntryContract
    {
        return $this->working ??= $this->localization()?->fromWorkingCopy();
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
