<?php

namespace Reach\StatamicTolk\Support;

use Reach\StatamicTolk\Targets\GlobalsTarget;
use Statamic\Contracts\Taxonomies\TermRepository;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Stache\Repositories\TermRepository as StacheTermRepository;

/**
 * What tolk:site's preflight finds and changes in the configuration of collections, taxonomies and global sets, so
 * that content can be translated into one site.
 */
final class SiteSetup
{
    public function __construct(
        private readonly Settings $settings,
        private readonly TermRepository $terms,
    ) {}

    /**
     * The collections and taxonomies that are not excluded in the settings and not enabled for $site.
     *
     * @return list<array{type: string, handle: string, name: string}> type `collection` or `taxonomy`
     */
    public function containersWithout(string $site): array
    {
        $containers = [];

        foreach (Collection::all() as $collection) {
            if (! $collection->sites()->contains($site) && ! in_array($collection->handle(), $this->settings->excludedCollections(), true)) {
                $containers[] = ['type' => 'collection', 'handle' => $collection->handle(), 'name' => "Collection {$collection->title()}"];
            }
        }

        foreach (Taxonomy::all() as $taxonomy) {
            if (! $taxonomy->sites()->contains($site) && ! in_array($taxonomy->handle(), $this->settings->excludedTaxonomies(), true)) {
                $containers[] = ['type' => 'taxonomy', 'handle' => $taxonomy->handle(), 'name' => "Taxonomy {$taxonomy->title()}"];
            }
        }

        return $containers;
    }

    /**
     * Enables $site for a collection or taxonomy, as its settings in the Control Panel do. For a taxonomy on the
     * Stache, the terms store is cleared as well, as the Control Panel does when a taxonomy's sites change.
     */
    public function enable(string $type, string $handle, string $site): void
    {
        $container = $type === 'taxonomy' ? Taxonomy::find($handle) : Collection::find($handle);

        if ($container->sites()->contains($site)) {
            return;
        }

        $container->sites([...$container->sites()->all(), $site])->save();

        if ($type === 'collection') {
            return;
        }

        // Term::taxonomy() remembers the taxonomy, and unlike Collection::save(), Taxonomy::save() does not forget it.
        Blink::forget("taxonomy-{$handle}");

        if ($this->terms instanceof StacheTermRepository) {
            Stache::store('terms::'.$handle)->clear();
        }
    }

    /**
     * The global sets that are not enabled for $site, or that name no origin for it while no other site names it as
     * theirs (GlobalsTarget::needsOrigin(), the sites the audit lists). `origin` is the site to translate from: $from, or
     * the default site, when the set has it, and otherwise the set's first other site; null when it has none.
     *
     * @return list<array{handle: string, name: string, enabled: bool, origin: ?string}>
     */
    public function globalSetsWithout(string $site, ?string $from): array
    {
        $sets = [];

        foreach (GlobalSet::all() as $set) {
            $enabled = $set->sites()->contains($site);

            if ($enabled && ! GlobalsTarget::needsOrigin($set, $site)) {
                continue;
            }

            $preferred = $from ?? Site::default()->handle();
            $others = $set->sites()->reject(fn (string $handle) => $handle === $site);

            $sets[] = [
                'handle' => $set->handle(),
                'name' => "Global set {$set->title()}",
                'enabled' => $enabled,
                'origin' => $others->contains($preferred) ? $preferred : $others->first(),
            ];
        }

        return $sets;
    }

    /**
     * Enables $site for a global set with $origin as its origin, or sets the origin of a site it has, as the set's
     * settings in the Control Panel do.
     */
    public function enableGlobalSet(string $handle, string $site, string $origin): void
    {
        $set = GlobalSet::find($handle);

        $set->sites($set->origins()->put($site, $origin)->all())->save();
    }

    /**
     * The collections enabled for $site, and not excluded in the settings, that have a route for each site but none
     * for $site, so their entries in $site have no URL.
     *
     * @return list<array{handle: string, name: string}>
     */
    public function collectionsWithoutRoute(string $site): array
    {
        return Collection::all()
            ->filter(fn ($collection) => $collection->sites()->contains($site)
                && ! in_array($collection->handle(), $this->settings->excludedCollections(), true)
                && $collection->route($site) === null
                && $collection->routes()->filter()->isNotEmpty())
            ->map(fn ($collection) => ['handle' => $collection->handle(), 'name' => "Collection {$collection->title()}"])
            ->values()
            ->all();
    }
}
