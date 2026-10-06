<?php

namespace Reach\StatamicTolk\State;

use Closure;
use InvalidArgumentException;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\NotSupportedYet;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\EntryFamilies;
use Reach\StatamicTolk\Targets\GlobalsTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Globals\GlobalSet as GlobalSetContract;
use Statamic\Contracts\Query\Builder;
use Statamic\Contracts\Structures\Nav as NavContract;
use Statamic\Contracts\Taxonomies\Taxonomy as TaxonomyContract;
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/**
 * Counts the item statuses of the targets of one container and site (§A6) for `tolk:status` and the Overview, in
 * slices that fit a time budget. It enumerates targets as runs do (T8.3), resolves them with StatusResolver and never
 * writes.
 */
final readonly class StatusScanner
{
    public function __construct(
        private EntryFamilies $families,
        private TargetFactory $factory,
        private StatusResolver $resolver,
        private Settings $settings,
    ) {}

    /**
     * The containers of a type that the status covers when none are named: the collections and taxonomies with more
     * than one site that the settings do not exclude, the global sets with more than one site, and every navigation
     * (a navigation lists only the sites it has a tree in).
     *
     * @return list<string> handles
     */
    public function containers(ContentType $type): array
    {
        [$all, $excluded] = match ($type) {
            ContentType::Entry => [Collection::all(), $this->settings->excludedCollections()],
            ContentType::Term => [Taxonomy::all(), $this->settings->excludedTaxonomies()],
            ContentType::GlobalSet => [GlobalSet::all(), []],
            ContentType::Nav => [Nav::all(), []],
            default => throw NotSupportedYet::contentType($type),
        };

        return $all
            ->filter(fn ($container) => $type === ContentType::Nav || $container->sites()->count() > 1)
            ->map->handle()
            ->reject(fn (string $handle) => in_array($handle, $excluded, true))
            ->values()
            ->all();
    }

    /**
     * The sites the container has targets in, in its own order of sites: of a collection, each site that some
     * family's root is not in; of a taxonomy with terms, every site but its first; of a global set, each site that
     * GlobalsTarget can translate; of a navigation with a tree in the default site, every other site.
     *
     * @return list<string>
     */
    public function sites(ContentType $type, string $container): array
    {
        return match ($type) {
            ContentType::Entry => $this->entrySites($this->collection($container)),
            ContentType::Term => $this->termSites($this->taxonomy($container)),
            ContentType::GlobalSet => $this->globalSites($this->globalSet($container)),
            ContentType::Nav => $this->navSites($this->nav($container)),
            default => throw NotSupportedYet::contentType($type),
        };
    }

    /**
     * Resolves the container's targets in $site from $cursor on until the budget is used, at least one. A site the
     * container has no targets in gives an empty, finished result. The cursor is the position of the next target, so
     * content that changes between two calls may shift a target into or out of the totals.
     */
    public function scan(ContentType $type, string $container, string $site, ?string $cursor, int $budgetMs): ScanResult
    {
        if (Site::get($site) === null) {
            throw UnknownSite::for($site);
        }

        $index = $this->offset($cursor);
        $targets = $this->targets($type, $container, $site);
        $deadline = hrtime(true) + max(0, $budgetMs) * 1_000_000;
        $result = ScanResult::empty();

        while ($index < count($targets)) {
            $result = $result->withTarget($this->resolver->resolve($targets[$index++]()));

            if (hrtime(true) >= $deadline) {
                break;
            }
        }

        return $result->withCursor($index < count($targets) ? (string) $index : null);
    }

    /**
     * The container's targets in $site, as scan() resolves them, each built only when called; an existing site the
     * container has no targets in gives none.
     *
     * @return list<Closure(): Target> in a stable order, so a cursor continues where the last scan stopped
     */
    public function targets(ContentType $type, string $container, string $site): array
    {
        return match ($type) {
            ContentType::Entry => $this->entryTargets($this->collection($container), $site),
            ContentType::Term => $this->termTargets($this->taxonomy($container), $site),
            ContentType::GlobalSet => $this->globalTargets($this->globalSet($container), $site),
            ContentType::Nav => $this->navTargets($this->nav($container), $site),
            default => throw NotSupportedYet::contentType($type),
        };
    }

    /**
     * @return list<Closure(): Target>
     */
    private function entryTargets(CollectionContract $collection, string $site): array
    {
        $roots = collect($this->families->roots([$collection->handle()], []))->keyBy(fn (EntryContract $root) => (string) $root->id());

        return array_map(
            fn (array $target) => fn () => $this->factory->forEntry($roots[$target['source_id']], $target['site']),
            $this->families->targets($roots->values()->all(), [$site]),
        );
    }

    /**
     * @return list<Closure(): Target>
     */
    private function termTargets(TaxonomyContract $taxonomy, string $site): array
    {
        if (! $taxonomy->sites()->skip(1)->contains($site)) {
            return [];
        }

        return $this->termQuery($taxonomy)->get()->map(fn (TermContract $term) => fn () => $this->factory->forTerm($term, $site))->values()->all();
    }

    /**
     * @return list<Closure(): Target>
     */
    private function globalTargets(GlobalSetContract $set, string $site): array
    {
        return in_array($site, $this->globalSites($set), true) ? [fn () => $this->factory->forGlobalSet($set, $site)] : [];
    }

    /**
     * @return list<Closure(): Target>
     */
    private function navTargets(NavContract $nav, string $site): array
    {
        return in_array($site, $this->navSites($nav), true) ? [fn () => $this->factory->forNav($nav, $site)] : [];
    }

    /**
     * @return list<string>
     */
    private function entrySites(CollectionContract $collection): array
    {
        $rootSites = $this->families->rootSites($collection->handle());

        return $collection->sites()
            ->filter(fn (string $site) => array_diff($rootSites, [$site]) !== [])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function termSites(TaxonomyContract $taxonomy): array
    {
        if ($this->termQuery($taxonomy)->count() === 0) {
            return [];
        }

        return $taxonomy->sites()->skip(1)->values()->all();
    }

    /**
     * @return list<string>
     */
    private function globalSites(GlobalSetContract $set): array
    {
        return $set->sites()
            ->filter(fn (string $site) => GlobalsTarget::untranslatableReasonFor($set, $site) === null)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function navSites(NavContract $nav): array
    {
        $default = Site::default()->handle();

        return $nav->existsIn($default) ? Site::all()->keys()->reject(fn (string $site) => $site === $default)->values()->all() : [];
    }

    // A taxonomy's terms are its first site's, as RunPlanner reads them.
    private function termQuery(TaxonomyContract $taxonomy): Builder
    {
        return Term::query()->where('taxonomy', $taxonomy->handle())->where('site', $taxonomy->sites()->first());
    }

    private function offset(?string $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }

        return ctype_digit($cursor) ? (int) $cursor : throw new InvalidArgumentException("Invalid scan cursor [{$cursor}].");
    }

    private function collection(string $handle): CollectionContract
    {
        return Collection::find($handle) ?? throw new InvalidArgumentException("Collection [{$handle}] not found.");
    }

    private function taxonomy(string $handle): TaxonomyContract
    {
        return Taxonomy::find($handle) ?? throw new InvalidArgumentException("Taxonomy [{$handle}] not found.");
    }

    private function globalSet(string $handle): GlobalSetContract
    {
        return GlobalSet::findByHandle($handle) ?? throw new InvalidArgumentException("Global set [{$handle}] not found.");
    }

    private function nav(string $handle): NavContract
    {
        return Nav::findByHandle($handle) ?? throw new InvalidArgumentException("Navigation [{$handle}] not found.");
    }
}
