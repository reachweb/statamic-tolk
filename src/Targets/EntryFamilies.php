<?php

namespace Reach\StatamicTolk\Targets;

use Closure;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Query\Builder;
use Statamic\Facades\Entry;

/**
 * Enumerates the entry families of bulk work and the targets they make.
 */
final class EntryFamilies
{
    /**
     * The roots of the families of the given collections and entries, each once, grouped by collection in the order
     * first named. A structured collection's roots follow its trees, depth first and site by site, so a root page and
     * every parent come before their children (§A11 "Root page" and tree placement).
     *
     * @param  list<string>  $collections  collection handles
     * @param  list<string>  $entryIds  any entry of each family
     * @return list<EntryContract>
     */
    public function roots(array $collections, array $entryIds): array
    {
        $roots = [];

        foreach ($collections as $handle) {
            foreach ($this->rootQuery($handle)->lazy() as $entry) {
                $roots[(string) $entry->id()] ??= $entry;
            }
        }

        foreach ($entryIds as $id) {
            $root = (Entry::find($id) ?? throw TargetNotFound::entry($id))->root();
            $roots[(string) $root->id()] ??= $root;
        }

        return collect($roots)
            ->groupBy(fn (EntryContract $root) => $root->collectionHandle())
            ->flatMap(fn ($group) => $this->inTreeOrder($group->first()->collection(), $group->all(), fn (EntryContract $root) => (string) $root->id()))
            ->values()
            ->all();
    }

    /**
     * The ids of the collection's roots that are targets in $site, as in targets(): none when the collection is not
     * available in $site, else the roots in the other sites. In the order of roots(), read from the index without
     * loading the entries.
     *
     * @return list<string>
     */
    public function rootIdsOutside(CollectionContract $collection, string $site): array
    {
        if (! $collection->sites()->contains($site)) {
            return [];
        }

        $ids = $this->rootQuery($collection->handle())->pluck('site', 'id')
            ->reject(fn (string $rootSite) => $rootSite === $site)
            ->keys()
            ->map(fn (int|string $id) => (string) $id)
            ->all();

        return $this->inTreeOrder($collection, $ids, fn (string $id) => $id);
    }

    /**
     * The sites the collection's roots are in, each once, read from the index without loading the entries.
     *
     * @return list<string>
     */
    public function rootSites(string $collection): array
    {
        return $this->rootQuery($collection)->pluck('site')->unique()->values()->all();
    }

    /**
     * Every family × site pair that is a target: the collection is available in the site, and the site is not the
     * root's own. In family order, then in the order of the sites.
     *
     * @param  list<EntryContract>  $roots
     * @param  list<string>  $sites
     * @return list<array{type: string, source_id: string, site: string}> TargetFactory::fromDescription() input
     */
    public function targets(array $roots, array $sites): array
    {
        $targets = [];

        foreach ($roots as $root) {
            foreach ($sites as $site) {
                if ($site !== $root->locale() && $root->collection()->sites()->contains($site)) {
                    $targets[] = ['type' => ContentType::Entry->value, 'source_id' => (string) $root->id(), 'site' => $site];
                }
            }
        }

        return $targets;
    }

    // A family's root is the entry without an origin.
    private function rootQuery(string $collection): Builder
    {
        return Entry::query()->where('collection', $collection)->whereNull('origin');
    }

    /**
     * @template T
     *
     * @param  list<T>  $roots  of the collection
     * @param  Closure(T): string  $id
     * @return list<T>
     */
    private function inTreeOrder(CollectionContract $collection, array $roots, Closure $id): array
    {
        $positions = $this->treePositions($collection);

        if ($positions === []) {
            return $roots;
        }

        // Stable: roots that are in no tree keep their order, after the others.
        usort($roots, fn ($a, $b) => ($positions[$id($a)] ?? PHP_INT_MAX) <=> ($positions[$id($b)] ?? PHP_INT_MAX));

        return $roots;
    }

    /**
     * @return array<string, int> entry id → its position in the collection's trees, depth first, site by site
     */
    private function treePositions(CollectionContract $collection): array
    {
        $structure = $collection->structure();
        $positions = [];

        if ($structure === null) {
            return [];
        }

        foreach ($collection->sites() as $site) {
            $this->walk($structure->in($site)?->tree() ?? [], $positions);
        }

        return $positions;
    }

    private function walk(array $branches, array &$positions): void
    {
        foreach ($branches as $branch) {
            if (isset($branch['entry'])) {
                $positions[(string) $branch['entry']] ??= count($positions);
            }

            $this->walk($branch['children'] ?? [], $positions);
        }
    }
}
