<?php

namespace Reach\StatamicTolk\Targets;

use Illuminate\Support\Collection;
use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\SourceSummary;
use Reach\StatamicTolk\Content\TranslatableFields;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\ApplyFailed;
use Reach\StatamicTolk\Exceptions\RootMissing;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\Concerns\AppliesFields;
use Reach\StatamicTolk\Translation\AssembledField;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Revisions\Revision;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Fields;
use Statamic\Support\Arr;
use Statamic\Support\Str;

/**
 * Build it with TargetFactory, which validates the source and the site.
 */
final class EntryTarget implements Target
{
    use AppliesFields;

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
        private ?EntryContract $entry = null,
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

            throw RootMissing::for(
                $root?->id(),
                (string) ($root?->value('title') ?? $root?->slug() ?? 'the root page'),
                (string) $this->source()->collection()->title(),
                $this->siteName(),
                $root?->editUrl(),
            );
        }
    }

    /**
     * @param  list<AssembledField>  $fields
     */
    private function applyFresh(array $fields, ApplyOptions $options): ApplyResult
    {
        $fields = $this->keyByHandle($fields);
        $created = ! $this->exists();
        $warnings = $created ? $this->createLocalization($options) : [];
        $localization = $this->localization();
        $item = $this->working();
        $ref = $this->ref();
        [$writes, $skipped] = $this->writable($fields, $options);
        $values = $this->prepareWrites($item, $ref, $writes);
        $mirror = $created && $this->publishesAsOrigin($localization, $writes, $options);

        foreach ($writes as $handle => $field) {
            $field->remove ? $item->remove($handle) : $item->set($handle, $values[$handle]);
        }

        if ($created && $this->settings->translatesSlugs($this->container())) {
            array_push($warnings, ...$this->translateSlug($item, $writes));
        }

        if ($writes !== [] || $mirror) {
            array_push($warnings, ...$this->saveChanges($item, $mirror, $options));
            $this->confirmWrites($ref, $writes);
        }

        return new ApplyResult(
            ref: $ref,
            created: $created,
            slug: $created ? $item->slug() : null,
            applied: array_keys($writes),
            skipped: $skipped,
            warnings: $warnings,
        );
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
        $source = $this->source();
        $localization = $source->makeLocalization($this->site)->id(Stache::generateId())->published(false);
        // Registered after core's callback, which appends the page to the end of its level, so it runs right after it.
        $localization->afterSave(fn (EntryContract $saved) => $this->placeInTree($source, $saved));
        $warnings = [];
        $slug = $localization->slug();

        if (is_string($slug) && $slug !== '' && ($unique = $this->uniqueSlug($slug)) !== $slug) {
            $localization->slug($unique);

            // With translated slugs this one is temporary, and translateSlug() warns about the slug it keeps.
            if (! $this->settings->translatesSlugs($this->container())) {
                $warnings[] = $this->slugTakenWarning($slug, $unique);
            }
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

    /**
     * Moves a new localization from the end of its level, where core's makeLocalization() appends it and an orderable
     * collection lists a new entry, to its origin's position among the pages already there: right after the nearest
     * preceding origin sibling at that level, else right before the nearest following one, never before the target's
     * home page. The pages already in the tree keep their order (§A11 "Create").
     */
    private function placeInTree(EntryContract $source, EntryContract $localization): void
    {
        $structure = $source->collection()->structure();
        $tree = $structure?->in($this->site);
        $siblings = self::levelOf($structure?->in($source->locale())?->tree() ?? [], (string) $source->id());

        if ($tree === null || $siblings === null) {
            return;
        }

        $at = array_search((string) $source->id(), $siblings, true);
        $branches = $tree->tree();
        $placed = $this->moved(
            $branches,
            (string) $localization->id(),
            array_reverse(array_slice($siblings, 0, $at)),
            array_slice($siblings, $at + 1),
            $structure->expectsRoot(),
        );

        // Core stores no tree for a new entry of an orderable collection, which then lists it in whatever order its query returns.
        if ($placed !== $branches || $source->collection()->orderable()) {
            $tree->tree($placed)->save();
        }
    }

    /**
     * @param  list<string>  $before  the origin's preceding siblings, nearest first
     * @param  list<string>  $after  the origin's following siblings, nearest first
     */
    private function moved(array $branches, string $id, array $before, array $after, bool $root): array
    {
        $level = self::entryIds($branches);
        $at = array_search($id, $level, true);

        if ($at === false) {
            foreach ($branches as $i => $branch) {
                if (isset($branch['children'])) {
                    $branches[$i]['children'] = $this->moved($branch['children'], $id, $before, $after, false);
                }
            }

            return $branches;
        }

        $moving = array_splice($branches, $at, 1);
        $positions = [];

        foreach (self::entryIds($branches) as $i => $entry) {
            $positions[$entry] ??= $i;
        }

        // Looks up only the siblings it passes, nearest first: an orderable collection holds every entry at one level.
        $position = function (array $siblings) use ($positions): ?int {
            if ($positions === []) {
                return null;
            }

            foreach ($siblings as $sibling) {
                $localization = $this->localizationOf($sibling);

                if ($localization !== null && isset($positions[$localization])) {
                    return $positions[$localization];
                }
            }

            return null;
        };

        $preceding = $position($before);
        $index = $preceding !== null ? $preceding + 1 : ($position($after) ?? count($branches));
        array_splice($branches, $root ? max(1, $index) : $index, 0, $moving);

        return $branches;
    }

    // The id of the target site's page of the entry's localization family. Queried within the collection, because
    // Entry::find() filters the id index of every collection.
    private function localizationOf(string $id): ?string
    {
        $localization = Entry::query()->where('collection', $this->container())->where('id', $id)->first()?->in($this->site);

        return $localization === null ? null : (string) $localization->id();
    }

    /**
     * The entry ids of the tree level that holds $id, or null.
     *
     * @return list<string>|null
     */
    private static function levelOf(array $branches, string $id): ?array
    {
        $level = self::entryIds($branches);

        if (in_array($id, $level, true)) {
            return $level;
        }

        foreach ($branches as $branch) {
            if (($found = self::levelOf($branch['children'] ?? [], $id)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function entryIds(array $branches): array
    {
        return array_map(fn (array $branch) => (string) ($branch['entry'] ?? ''), array_values($branches));
    }

    /**
     * D16: in a collection with translated slugs, a new localization gets the slug of its title as the CP's form makes
     * it on save (EntriesController::resolveSlug()), once the title is translated; until then, the origin's. Returns the
     * warnings.
     *
     * @param  array<string, AssembledField>  $writes
     * @return list<string>
     */
    private function translateSlug(EntryContract $item, array $writes): array
    {
        $origin = $this->source()->slug();

        if (! is_string($origin) || $origin === '' || ! $this->blueprint()->hasField('slug')) {
            return [];
        }

        $warnings = [];
        $title = $writes['title'] ?? null;

        if ($item->collection()->autoGeneratesTitles()) {
            $translated = $writes === [] ? null : $item->autoGeneratedTitle();
        } elseif ($title?->incomplete) {
            $translated = null;
            $warnings[] = __('The title was not translated completely, so the new localization kept the slug of its origin.');
        } else {
            $translated = $title === null || $title->remove ? null : $item->get('title');
        }

        $slug = Str::slug(Settings::text($translated), '-', Site::get($this->site)?->lang() ?? 'en');
        $slug = $slug === '' ? $origin : $slug;
        $unique = $this->uniqueSlug($slug, (string) $item->id());
        $item->slug($unique);

        if ($unique !== $slug) {
            $warnings[] = $this->slugTakenWarning($slug, $unique);
        }

        return $warnings;
    }

    // The slug, or the first free one of `{slug}-2`, `{slug}-3`, … among the collection's other entries in the site.
    private function uniqueSlug(string $slug, ?string $except = null): string
    {
        $taken = fn (string $candidate) => Entry::query()
            ->where('collection', $this->container())
            ->where('site', $this->site)
            ->where('slug', $candidate)
            ->when($except !== null, fn ($query) => $query->where('id', '!=', $except))
            ->count() > 0;

        if (! $taken($slug)) {
            return $slug;
        }

        $suffix = 2;

        while ($taken("{$slug}-{$suffix}")) {
            $suffix++;
        }

        return "{$slug}-{$suffix}";
    }

    private function slugTakenWarning(string $slug, string $unique): string
    {
        return __('The slug ":slug" is already used in :site, so the new localization got ":new".', ['slug' => $slug, 'site' => $this->siteName(), 'new' => $unique]);
    }

    /**
     * Saves the written fields as the CP would (D26, §A11 "Revisions"). Returns the warnings.
     *
     * @return list<string>
     */
    private function saveChanges(EntryContract $item, bool $mirror, ApplyOptions $options): array
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

        $hadWorkingCopy = $item->hasWorkingCopy();
        $this->saveRevision($item->makeWorkingCopy(), $item, $options, $this->revisionMessage());

        if (! $options->publishChanges) {
            return [];
        }

        // publish() publishes the whole working copy, which may hold somebody else's changes that nobody approved.
        if ($hadWorkingCopy) {
            return [__('The :site localization already had unpublished changes, so the translation was added to them and not published.', ['site' => $this->siteName()])];
        }

        if ($options->user !== null && ! $options->user->can('publish', $item)) {
            return [__('The translation was saved as unpublished changes: the user who started it may not publish this entry.')];
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
        return __('Translated from :site with Tolk', ['site' => Site::get($this->sourceSite())?->name() ?? $this->sourceSite()]);
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
