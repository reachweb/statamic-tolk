<?php

namespace Reach\StatamicTolk\Targets;

use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
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
            foreach (Entry::query()->where('collection', $handle)->whereNull('origin')->lazy() as $entry) {
                $roots[(string) $entry->id()] ??= $entry;
            }
        }

        foreach ($entryIds as $id) {
            $root = (Entry::find($id) ?? throw TargetNotFound::entry($id))->root();
            $roots[(string) $root->id()] ??= $root;
        }

        return collect($roots)
            ->groupBy(fn (EntryContract $root) => $root->collectionHandle())
            ->flatMap(fn ($group) => $this->inTreeOrder($group->all()))
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
     * @param  list<EntryContract>  $roots  of one collection
     * @return list<EntryContract>
     */
    private function inTreeOrder(array $roots): array
    {
        $positions = $this->treePositions($roots[0]->collection());

        if ($positions === []) {
            return $roots;
        }

        // Stable: roots that are in no tree keep their order, after the others.
        usort($roots, fn (EntryContract $a, EntryContract $b) => ($positions[(string) $a->id()] ?? PHP_INT_MAX) <=> ($positions[(string) $b->id()] ?? PHP_INT_MAX));

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
