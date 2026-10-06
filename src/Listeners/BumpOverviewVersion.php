<?php

namespace Reach\StatamicTolk\Listeners;

use Illuminate\Support\Str;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Events\TargetTranslated;
use Reach\StatamicTolk\State\OverviewCache;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Events\AddonSettingsSaved;
use Statamic\Events\BlueprintDeleted;
use Statamic\Events\BlueprintSaved;
use Statamic\Events\CollectionSaved;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Statamic\Events\FieldsetSaved;
use Statamic\Events\GlobalSetSaved;
use Statamic\Events\GlobalVariablesDeleted;
use Statamic\Events\GlobalVariablesSaved;
use Statamic\Events\NavTreeDeleted;
use Statamic\Events\NavTreeSaved;
use Statamic\Events\RevisionSaved;
use Statamic\Events\StacheCleared;
use Statamic\Events\TaxonomySaved;
use Statamic\Events\TermDeleted;
use Statamic\Events\TermSaved;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Fields\Blueprint;

/**
 * Makes the Overview count a container again when its content, its state or its blueprint changed (OverviewCache).
 */
final readonly class BumpOverviewVersion
{
    public function __construct(private OverviewCache $cache) {}

    public function handleEntrySaved(EntrySaved $event): void
    {
        $this->entry($event->entry);
    }

    public function handleEntryDeleted(EntryDeleted $event): void
    {
        $this->entry($event->entry);
    }

    /**
     * On collections with revisions, the CP saves a published entry's changes into its working copy, which is what the
     * status reads (§A6).
     */
    public function handleRevisionSaved(RevisionSaved $event): void
    {
        $key = (string) $event->revision->key();

        if (str_starts_with($key, 'collections/')) {
            $this->entries(explode('/', $key)[1]);
        }
    }

    public function handleCollectionSaved(CollectionSaved $event): void
    {
        $this->entries($event->collection->handle());
    }

    public function handleTermSaved(TermSaved $event): void
    {
        $this->cache->bump(ContentType::Term, $event->term->taxonomyHandle());
    }

    public function handleTermDeleted(TermDeleted $event): void
    {
        $this->cache->bump(ContentType::Term, $event->term->taxonomyHandle());
    }

    public function handleTaxonomySaved(TaxonomySaved $event): void
    {
        $this->cache->bump(ContentType::Term, $event->taxonomy->handle());
    }

    public function handleGlobalVariablesSaved(GlobalVariablesSaved $event): void
    {
        $this->cache->bump(ContentType::GlobalSet, $event->variables->handle());
    }

    public function handleGlobalVariablesDeleted(GlobalVariablesDeleted $event): void
    {
        $this->cache->bump(ContentType::GlobalSet, $event->variables->handle());
    }

    public function handleGlobalSetSaved(GlobalSetSaved $event): void
    {
        $this->cache->bump(ContentType::GlobalSet, $event->globals->handle());
    }

    public function handleNavTreeSaved(NavTreeSaved $event): void
    {
        $this->cache->bump(ContentType::Nav, $event->tree->handle());
    }

    public function handleNavTreeDeleted(NavTreeDeleted $event): void
    {
        $this->cache->bump(ContentType::Nav, $event->tree->handle());
    }

    public function handleBlueprintSaved(BlueprintSaved $event): void
    {
        $this->blueprint($event->blueprint);
    }

    public function handleBlueprintDeleted(BlueprintDeleted $event): void
    {
        $this->blueprint($event->blueprint);
    }

    /**
     * A fieldset can be imported by any blueprint.
     */
    public function handleFieldsetSaved(FieldsetSaved $event): void
    {
        $this->cache->bumpAll();
    }

    /**
     * The excluded fields decide what every status counts.
     */
    public function handleAddonSettingsSaved(AddonSettingsSaved $event): void
    {
        if ($event->settings->addon()->package() === Settings::PACKAGE) {
            $this->cache->bumpAll();
        }
    }

    /**
     * Content changed outside Statamic (a deploy, a git pull) reaches the Stache only when it is cleared or refreshed.
     */
    public function handleStacheCleared(StacheCleared $event): void
    {
        $this->cache->bumpAll();
    }

    /**
     * The state of a target is written after its content is saved, so the content event alone may come too early.
     */
    public function handleTargetTranslated(TargetTranslated $event): void
    {
        $ref = $event->ref;

        match ($ref->type) {
            ContentType::Entry => ($entry = Entry::find($ref->id)) ? $this->entry($entry) : null,
            ContentType::Term => $this->cache->bump(ContentType::Term, Str::before($ref->id, '::')),
            ContentType::GlobalSet, ContentType::Nav => $this->cache->bump($ref->type, $ref->id),
            default => null,
        };
    }

    private function entry(EntryContract $entry): void
    {
        $this->entries($entry->collectionHandle());
    }

    /**
     * A navigation's status follows the localizations of the entries it links (T9.4).
     */
    private function entries(string $collection): void
    {
        $this->cache->bump(ContentType::Entry, $collection);

        foreach (Nav::all() as $nav) {
            $this->cache->bump(ContentType::Nav, $nav->handle());
        }
    }

    private function blueprint(Blueprint $blueprint): void
    {
        $namespace = (string) $blueprint->namespace();
        [$group, $handle] = str_contains($namespace, '.') ? explode('.', $namespace, 2) : [$namespace, (string) $blueprint->handle()];

        match ($group) {
            'collections' => $this->entries($handle),
            'taxonomies' => $this->cache->bump(ContentType::Term, $handle),
            'globals' => $this->cache->bump(ContentType::GlobalSet, $handle),
            'navigation' => $this->cache->bump(ContentType::Nav, $handle),
            default => null,
        };
    }
}
