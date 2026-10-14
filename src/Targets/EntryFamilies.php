<?php

namespace Reach\StatamicTolk\Targets;

use Closure;
use Generator;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Query\Builder;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Enumerates the entry families of bulk work and the targets they make.
 */
final class EntryFamilies
{
    /**
     * Roots loaded by one query while roots() is read.
     */
    public const int CHUNK = 50;

    /**
     * What core memoizes in Blink for one entry: its localizations, its origin and its blueprint.
     */
    private const string MEMOIZED = '/^(entry-descendants-|origin-Entry-|entry-.+-blueprint$)/';

    /**
     * The roots of rootIds(), loaded CHUNK at a time as the caller reads them. Before it loads the next chunk, it forgets
     * what core memoized in Blink meanwhile for the entries the caller read (their localizations, origins, blueprints
     * and tree pages), so a caller that keeps no entry holds one chunk of families in memory, not the whole collection.
     * A root deleted after the ids were read is left out. Unknown entries throw at once, before anything is read.
     *
     * @param  list<string>  $collections  collection handles
     * @param  list<string>  $entryIds  any entry of each family
     * @return Generator<int, EntryContract>
     *
     * @throws TargetNotFound
     */
    public function roots(array $collections, array $entryIds): Generator
    {
        return $this->load($this->rootIdsByCollection($collections, $entryIds));
    }

    /**
     * The ids of the roots of the families of the given collections and entries, each once, grouped by collection in
     * the order first named, read from the index without loading the collections' entries. A structured collection's
     * roots follow its trees, depth first and site by site, so a root page and every parent come before their children
     * (§A11 "Root page" and tree placement).
     *
     * @param  list<string>  $collections  collection handles
     * @param  list<string>  $entryIds  any entry of each family
     * @return list<string>
     *
     * @throws TargetNotFound
     */
    public function rootIds(array $collections, array $entryIds): array
    {
        return array_merge(...array_values($this->rootIdsByCollection($collections, $entryIds)));
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
     * The sites the entry is the origin of (§A11 "Entries"): those of its direct localizations and, for the root of the
     * family when $missing, the sites of its collection the family has no localization in yet. In the order of the
     * collection's sites.
     *
     * @return list<string>
     */
    public function originSites(EntryContract $entry, bool $missing = true): array
    {
        $children = $entry->directDescendants();
        $family = $missing && $entry->isRoot() ? $entry->descendants() : null;

        return $entry->collection()->sites()
            ->filter(fn (string $site) => $site !== $entry->locale() && ($children->has($site) || ($family !== null && ! $family->has($site))))
            ->values()
            ->all();
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

    /**
     * @return array<string, list<string>> collection handle → root ids, as rootIds() orders them
     */
    private function rootIdsByCollection(array $collections, array $entryIds): array
    {
        $ids = [];

        foreach ($collections as $handle) {
            foreach ($this->rootQuery($handle)->pluck('id') as $id) {
                $ids[$handle][(string) $id] = true;
            }
        }

        foreach ($entryIds as $id) {
            $root = (Entry::find($id) ?? throw TargetNotFound::entry($id))->root();
            $ids[$root->collectionHandle()][(string) $root->id()] = true;
        }

        foreach ($ids as $handle => $byId) {
            $ids[$handle] = $this->inTreeOrder(Collection::find((string) $handle), array_map(fn (int|string $id) => (string) $id, array_keys($byId)), fn (string $id) => $id);
        }

        return $ids;
    }

    /**
     * One query per root would cost more than a chunk: Entry::find() filters the whole id index (T12.2).
     *
     * @param  array<string, list<string>>  $idsByCollection
     * @return Generator<int, EntryContract>
     */
    private function load(array $idsByCollection): Generator
    {
        foreach ($idsByCollection as $handle => $ids) {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $memoized = self::memoized();
                $loaded = Entry::query()->where('collection', $handle)->whereIn('id', $chunk)->get()
                    ->keyBy(fn (EntryContract $entry) => (string) $entry->id())
                    ->all();

                foreach ($chunk as $id) {
                    if (isset($loaded[$id])) {
                        yield $loaded[$id];
                    }
                }

                $loaded = [];
                self::forgetMemoizedSince($memoized);
            }
        }
    }

    /**
     * @return array{default: array<string, true>, pages: array<string, true>} the keys of the Blink stores entries are memoized in
     */
    private static function memoized(): array
    {
        return [
            'default' => array_fill_keys(array_keys(Blink::store()->all()), true),
            'pages' => array_fill_keys(array_keys(Blink::store('structure-entries')->all()), true),
        ];
    }

    /**
     * Forgets the entries memoized since memoized() was read: the default store's entry keys, and the entries of tree
     * pages (Page::entry(), which a page's parent is read through).
     *
     * @param  array{default: array<string, true>, pages: array<string, true>}  $before
     */
    private static function forgetMemoizedSince(array $before): void
    {
        foreach (['default' => Blink::store(), 'pages' => Blink::store('structure-entries')] as $name => $store) {
            foreach (array_keys($store->all()) as $key) {
                if (! isset($before[$name][$key]) && ($name === 'pages' || preg_match(self::MEMOIZED, (string) $key) === 1)) {
                    $store->forget((string) $key);
                }
            }
        }
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
