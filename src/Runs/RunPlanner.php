<?php

namespace Reach\StatamicTolk\Runs;

use Closure;
use InvalidArgumentException;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\Prompts\PromptContext;
use Reach\StatamicTolk\Content\TranslatableFields;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Exceptions\ClaimLost;
use Reach\StatamicTolk\Exceptions\InvalidTarget;
use Reach\StatamicTolk\Exceptions\NotSupportedYet;
use Reach\StatamicTolk\Exceptions\RootMissing;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\EntryFamilies;
use Reach\StatamicTolk\Targets\EntryTarget;
use Reach\StatamicTolk\Targets\GlobalsTarget;
use Reach\StatamicTolk\Targets\NavTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Translation\Plan;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetResult;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Structures\Nav as NavContract;
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/**
 * Turns a RunRequest into a run and its items (§A12), and estimates a stored run item by item. Building only enumerates
 * targets and never plans them, so it stays fast enough for a web request.
 */
final readonly class RunPlanner
{
    public const string EXCLUDED_COLLECTION = 'excluded_collection';

    public const string SITE_NOT_IN_COLLECTION = 'site_not_in_collection';

    public const string EXCLUDED_TAXONOMY = 'excluded_taxonomy';

    public const string SITE_NOT_IN_TAXONOMY = 'site_not_in_taxonomy';

    // A site the others are translated from, which is left out without being counted.
    private const string SOURCE = 'source';

    public function __construct(
        private EntryFamilies $families,
        private TargetFactory $factory,
        private Settings $settings,
        private ModelCatalog $catalog,
        private Planner $planner,
        private Estimator $estimator,
        private RunRepository $runs,
    ) {}

    /**
     * The unsaved pending run, with the models and defaults resolved from the settings now, so a run keeps the options
     * it was started with. The run's `scope` holds the request, the `skipped` counts and, as `out_of_scope`, the
     * text-bearing top-level fields of its targets that are never translated, with the reason (§A2). Containers the
     * request assumes to have its sites (RunRequest::$assumedSites) are planned as if they had them.
     */
    public function build(RunRequest $request): PlannedRun
    {
        foreach (array_filter([...$request->sites, $request->sourceSite]) as $site) {
            if (Site::get($site) === null) {
                throw UnknownSite::for($site);
            }
        }

        $options = $this->runOptions($request);
        $notes = ['skipped' => [], 'out_of_scope' => [], 'explained' => []];
        $items = $this->entryItems($this->entryTargets($request, Mode::from($options['mode']) !== Mode::Review, $notes));
        $items = [...$items, ...$this->termItems($request, count($items), $notes)];
        $items = [...$items, ...$this->globalItems($request, count($items), $notes)];
        $items = [...$items, ...$this->navItems($request, count($items), $notes)];
        $scope = [...$request->scope(), 'skipped' => $notes['skipped'], 'out_of_scope' => array_values($notes['out_of_scope'])];

        return new PlannedRun(
            Run::make($request->driver, $scope, $options, $request->user),
            $items,
            $notes['skipped'],
        );
    }

    /**
     * Plans the run's pending items from $fromIndex on, without storing the plans, until the budget is used (at least
     * one item), adds them to the run's estimate, and marks an item whose plan is empty `skipped` unless it waits for
     * other items: the run may give it something to do before its turn, by translating its source or creating the
     * localizations its navigation links (§A12), and it is planned again then. Index 0 starts the estimate over; a call
     * from any index other than where the last one stopped (a retried request) adds nothing.
     *
     * @param  ?Closure(RunItem, Plan): void  $planned  called with each item and its plan, empty or not
     */
    public function estimate(Run $run, int $fromIndex, int $budgetMs, ?Closure $planned = null): EstimateProgress
    {
        $stored = $run->estimate === null ? new EstimateProgress(new Estimate, 0) : EstimateProgress::fromArray($run->estimate);

        if ($fromIndex > 0 && $fromIndex !== $stored->next) {
            return $stored;
        }

        $estimate = $fromIndex > 0 ? $stored->estimate : new Estimate;
        $size = array_sum($this->runs->counts($run));
        $options = $this->planOptions($run);
        $contexts = [];
        $deadline = hrtime(true) + max(0, $budgetMs) * 1_000_000;
        $index = max(0, $fromIndex);

        while ($index < $size) {
            $estimate = $estimate->add($this->estimateItem($run, $index++, $options, $contexts, $planned));

            if (hrtime(true) >= $deadline) {
                break;
            }
        }

        $progress = new EstimateProgress($estimate, $index < $size ? $index : null);
        $run->estimate = $progress->toArray();
        $this->runs->save($run);

        return $progress;
    }

    /**
     * The plan options of the run's items: its own options, and the profile of its driver.
     */
    public function planOptions(Run $run): PlanOptions
    {
        return PlanOptions::fromSettings($this->settings, $this->catalog, [
            'translator' => $run->options['translator'] ?? null,
            'mode' => $run->options['mode'] ?? null,
            'overwriteManual' => (bool) ($run->options['overwrite_manual'] ?? false),
            'review' => (bool) ($run->options['review'] ?? false),
            'reviewer' => $run->options['reviewer'] ?? null,
            'profile' => $run->driver === RunDriver::Cli ? 'background' : 'web',
            'userId' => $run->createdBy['id'] ?? null,
        ]);
    }

    private function runOptions(RunRequest $request): array
    {
        $options = $request->options;
        $plan = PlanOptions::fromSettings($this->settings, $this->catalog, [
            'translator' => $options['translator'],
            'mode' => $options['mode'],
            'overwriteManual' => $options['overwrite_manual'],
            'review' => $options['review'],
            'reviewer' => $options['reviewer'],
        ]);

        return [
            'mode' => $plan->mode->value,
            'overwrite_manual' => $plan->overwriteManual,
            'review' => $plan->review,
            'translator' => (string) $plan->translator,
            'reviewer' => $plan->reviewer?->__toString(),
            'new_status' => $options['new_status'] ?? $this->settings->newLocalizationStatus(),
            'publish_changes' => $options['publish_changes'],
        ];
    }

    /**
     * Every family × site target the user may edit, family by family, keyed by site, as the scalars entryItems() needs:
     * the roots are read a chunk at a time (EntryFamilies::roots()) and each target is built from the root it read, so
     * memory holds the entries of one chunk of families, not of the whole collection or site. The other pairs are
     * counted in the notes; a root's own site is not a target at all.
     *
     * @param  bool  $creates  whether missing localizations are created (every mode but review)
     * @param  array  $notes  see sitesFor()
     * @return list<array<string, array{root: string, site: string, source: string, source_site: string, chained: bool, title: string, root_missing: ?array{family: ?string, error: string, page: ?array}, parent: ?array{family: string, title: mixed}}>>
     */
    private function entryTargets(RunRequest $request, bool $creates, array &$notes): array
    {
        $assumed = RunRequest::assumed($request->assumedSites, 'collection');
        $collections = $this->containers($request->collections, Collection::class, 'Collection', $assumed);

        if ($collections === [] && $request->entryIds === []) {
            return [];
        }

        $excluded = $this->settings->excludedCollections();
        $families = [];

        foreach ($this->families->roots($collections, $request->entryIds) as $root) {
            $collection = $root->collection();
            $family = [];
            $sites = $this->sitesFor($request, $notes, fn (string $site) => match (true) {
                $site === $root->locale() => self::SOURCE,
                in_array($collection->handle(), $excluded, true) => self::EXCLUDED_COLLECTION,
                ! $collection->sites()->contains($site) && ! in_array($collection->handle(), $assumed, true) => self::SITE_NOT_IN_COLLECTION,
                default => null,
            });

            foreach ($sites as $site) {
                $source = $this->source($root, $site, $request->sourceSite);
                $target = $this->factory->forLoadedEntry($source, $site, $request->assumedSites);

                if ($this->admits($request, $target, $collection->handle().'.'.$source->blueprint()->handle(), $notes)) {
                    $family[$site] = $this->entryTargetInfo($root, $site, $source, $target, $creates);
                }
            }

            if ($family !== []) {
                $families[] = $family;
            }
        }

        return $families;
    }

    /**
     * One item per term × site the user may edit, from index $offset on, term by term (§A11 "Terms"). The other pairs
     * are counted in the notes; a term's default site is the source and not a target at all. Terms have no chains and
     * no tree, so the items depend on nothing.
     *
     * @param  array  $notes  see sitesFor()
     * @return list<RunItem>
     */
    private function termItems(RunRequest $request, int $offset, array &$notes): array
    {
        $assumed = RunRequest::assumed($request->assumedSites, 'taxonomy');
        $taxonomies = $this->containers($request->taxonomies, Taxonomy::class, 'Taxonomy', $assumed);

        if ($taxonomies === [] && $request->termIds === []) {
            return [];
        }

        $excluded = $this->settings->excludedTaxonomies();
        $items = [];

        foreach ($this->terms($taxonomies, $request->termIds) as $term) {
            $taxonomy = $term->taxonomy();
            $sites = $this->sitesFor($request, $notes, fn (string $site) => match (true) {
                $site === $term->locale() => self::SOURCE,
                in_array($taxonomy->handle(), $excluded, true) => self::EXCLUDED_TAXONOMY,
                ! $taxonomy->sites()->contains($site) && ! in_array($taxonomy->handle(), $assumed, true) => self::SITE_NOT_IN_TAXONOMY,
                default => null,
            });

            foreach ($sites as $site) {
                $target = $this->factory->forTerm($term, $site, $request->assumedSites);

                if ($this->admits($request, $target, $taxonomy->handle().'.'.$term->blueprint()->handle(), $notes)) {
                    $items[] = new RunItem($offset + count($items), ContentType::Term, (string) $term->id(), $site, $target->title());
                }
            }
        }

        return $items;
    }

    /**
     * One item per global set × site the user may edit, from index $offset on, set by set (§A11 "Global sets"). A site
     * the set does not have or has no origin for, and every site of a set without a blueprint, are counted in the
     * notes (GlobalsTarget's reasons); a site without an origin that other sites of the set are translated from is
     * the source and not a target at all. Within a set, a site comes after the site it is translated from and its
     * item waits for that site's item (chains: de_CH waits for de).
     *
     * @param  array  $notes  see sitesFor()
     * @return list<RunItem>
     */
    private function globalItems(RunRequest $request, int $offset, array &$notes): array
    {
        $items = [];

        foreach ($this->containers($request->globals, GlobalSet::class, 'Global set', RunRequest::assumed($request->assumedSites, 'globals')) as $handle) {
            $set = GlobalSet::findByHandle($handle);
            $origin = $request->assumedSites["globals:{$handle}"] ?? null;
            $targets = $indexes = [];
            $sites = $this->sitesFor($request, $notes, fn (string $site) => GlobalsTarget::isSource($set, $site) ? self::SOURCE : GlobalsTarget::untranslatableReasonFor($set, $site, $origin));

            foreach ($sites as $site) {
                $target = $this->factory->forGlobalSet($set, $site, $request->assumedSites);

                if ($this->admits($request, $target, $handle, $notes)) {
                    $targets[$site] = $target;
                }
            }

            foreach (self::chainOrder($targets, fn (GlobalsTarget $target) => $target->sourceSite()) as $target) {
                $item = new RunItem($offset + count($items), ContentType::GlobalSet, $handle, $target->targetSite(), $target->title());
                $item->dependsOn = isset($indexes[$target->sourceSite()]) ? [$indexes[$target->sourceSite()]] : [];
                $indexes[$target->targetSite()] = $item->index;
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * One item per navigation × site the user may edit, from index $offset on, nav by nav (§A11 "Navigation"). The
     * source is the nav's tree in the request's source site when it has one there, else the default site's tree; the
     * source site is not a target, and a nav without a source tree is counted in the notes, as is a requested site
     * whose tree is the source because the request names it as the source site. Nav items are of stage 1,
     * so each is translated only once no entry, term or global set item of its site is unfinished: the localizations
     * the run creates are then there for its entry branches (§A12).
     *
     * @param  array  $notes  see sitesFor()
     * @return list<RunItem>
     */
    private function navItems(RunRequest $request, int $offset, array &$notes): array
    {
        $items = [];

        foreach ($this->navs($request->navs) as $nav) {
            $sourceSite = $request->sourceSite !== null && $nav->existsIn($request->sourceSite) ? $request->sourceSite : Site::default()->handle();
            $hasSourceTree = $nav->existsIn($sourceSite);
            $sites = $this->sitesFor($request, $notes, fn (string $site) => match (true) {
                $site === $sourceSite && $site === $request->sourceSite => NavTarget::FROM_SITE,
                $site === $sourceSite => self::SOURCE,
                ! $hasSourceTree => NavTarget::NO_SOURCE_TREE,
                default => null,
            });

            foreach ($sites as $site) {
                $target = $this->factory->forNav($nav, $site, $sourceSite);

                if ($this->admits($request, $target, $nav->handle(), $notes)) {
                    $items[] = new RunItem($offset + count($items), ContentType::Nav, $target->describe()['source_id'], $site, $target->title(), stage: 1);
                }
            }
        }

        return $items;
    }

    /**
     * The requested sites that $leftOut() names no reason for. The others are counted in the notes' `skipped` under
     * their reason, except a source site (SOURCE). The notes hold what build() reports, and the blueprints admits()
     * has explained.
     *
     * @param  array{skipped: array<string, int>, out_of_scope: array<string, array>, explained: array<string, true>}  $notes
     * @param  Closure(string): ?string  $leftOut
     * @return list<string>
     */
    private function sitesFor(RunRequest $request, array &$notes, Closure $leftOut): array
    {
        $sites = [];

        foreach ($request->sites as $site) {
            $reason = $leftOut($site);

            if ($reason === null) {
                $sites[] = $site;
            } elseif ($reason !== self::SOURCE) {
                self::count($notes['skipped'], $reason);
            }
        }

        return $sites;
    }

    /**
     * Whether the run's user may edit $target; a target they may not is counted as unauthorized. The first target of
     * each blueprint (a key unique within its content type) notes the blueprint's out-of-scope fields.
     *
     * @param  array  $notes  see sitesFor()
     */
    private function admits(RunRequest $request, Target $target, string $blueprint, array &$notes): bool
    {
        if ($request->user !== null && ! $target->authorize($request->user)) {
            self::count($notes['skipped'], RunItem::UNAUTHORIZED);

            return false;
        }

        $key = $target->type()->value.':'.$blueprint;

        if (! isset($notes['explained'][$key])) {
            $notes['explained'][$key] = true;
            $this->noteOutOfScope($target, $notes['out_of_scope']);
        }

        return true;
    }

    /**
     * The terms of the given taxonomies and ids, each once, in their taxonomy's default site, grouped by taxonomy in
     * the order first named.
     *
     * @param  list<string>  $taxonomies
     * @param  list<string>  $termIds
     * @return list<TermContract>
     */
    private function terms(array $taxonomies, array $termIds): array
    {
        $terms = [];

        foreach ($taxonomies as $handle) {
            $default = Taxonomy::find($handle)->sites()->first();

            foreach (Term::query()->where('taxonomy', $handle)->where('site', $default)->get() as $term) {
                $terms[(string) $term->id()] ??= $term;
            }
        }

        foreach ($termIds as $id) {
            $term = Term::find($id) ?? throw TargetNotFound::term($id);
            $terms[(string) $term->id()] ??= $term;
        }

        return collect($terms)->groupBy(fn (TermContract $term) => $term->taxonomyHandle())->flatten(1)->all();
    }

    /**
     * The named navigations, or with [ALL] every one: a navigation can get a tree in any site.
     *
     * @param  list<string>  $handles
     * @return list<NavContract>
     */
    private function navs(array $handles): array
    {
        if (RunRequest::all($handles)) {
            return Nav::all()->values()->all();
        }

        return array_map(
            fn (string $handle) => Nav::findByHandle($handle) ?? throw new InvalidArgumentException("Navigation [{$handle}] not found."),
            array_values(array_unique($handles)),
        );
    }

    /**
     * Notes the target's text-bearing top-level fields that are out of scope, once per container, field and reason.
     *
     * @param  array<string, array{container: string, handle: string, label: string, type: string, reason: string}>  $outOfScope
     */
    private function noteOutOfScope(Target $target, array &$outOfScope): void
    {
        foreach (TranslatableFields::textOutOfScope($target->explainFields()) as $field) {
            $outOfScope[$target->container().'.'.$field['handle'].':'.$field['reason']] ??= ['container' => $target->container(), ...$field];
        }
    }

    /**
     * What entryItem() needs of a target, read while its entries are loaded. Only a localization created by the run is
     * placed in a tree (§A11 "Create" and "Root page"), so only a missing one, in a run that creates it, notes the root
     * page it waits for and a parent page without a localization in the site, each by the root id of its family.
     *
     * @return array the shape entryTargets() returns per target
     */
    private function entryTargetInfo(EntryContract $root, string $site, EntryContract $source, EntryTarget $target, bool $creates): array
    {
        $info = [
            'root' => (string) $root->id(),
            'site' => $site,
            'source' => (string) $source->id(),
            'source_site' => $source->locale(),
            'chained' => $source->hasOrigin(),
            'title' => $target->title(),
            'root_missing' => null,
            'parent' => null,
        ];

        if (! $creates || $target->exists()) {
            return $info;
        }

        try {
            $target->ensureRootExists();
        } catch (RootMissing $missing) {
            $rootPage = Entry::find($missing->rootId ?? '');
            $info['root_missing'] = ['family' => $rootPage === null ? null : (string) $rootPage->root()->id(), 'error' => $missing->getMessage(), 'page' => $missing->rootPage()];
        }

        $parent = $this->parentPage($source);

        if ($parent !== null && $parent->in($site) === null) {
            $info['parent'] = ['family' => (string) $parent->root()->id(), 'title' => $parent->value('title') ?? $parent->slug()];
        }

        return $info;
    }

    /**
     * Indexes the targets in family order, a family's chain sources before the targets translated from them, then
     * gives each item its dependencies. They may point at any item of the run, since the source trees of a run's
     * targets need not have one shape.
     *
     * @param  list<array<string, array>>  $families  as entryTargets() returns them
     * @return list<RunItem>
     */
    private function entryItems(array $families): array
    {
        $targets = [];
        $indexes = [];

        foreach ($families as $family) {
            foreach (self::chainOrder($family, fn (array $target) => $target['source_site']) as $target) {
                $indexes[$target['root'].'|'.$target['site']] = count($targets);
                $targets[] = $target;
            }
        }

        return array_map(fn (array $target, int $index) => $this->entryItem($index, $target, $indexes), $targets, array_keys($targets));
    }

    /**
     * The values in chain order: each after the value of the site it is translated from, when that is one of them.
     *
     * @template T
     *
     * @param  array<string, T>  $bySite
     * @param  Closure(T): string  $sourceSite
     * @return list<T>
     */
    private static function chainOrder(array $bySite, Closure $sourceSite): array
    {
        $ordered = [];

        while ($bySite !== []) {
            $next = array_key_first($bySite);

            foreach ($bySite as $site => $value) {
                if (! isset($bySite[$sourceSite($value)])) {
                    $next = $site;

                    break;
                }
            }

            $ordered[] = $bySite[$next];
            unset($bySite[$next]);
        }

        return $ordered;
    }

    /**
     * @param  array  $target  as entryTargets() returns it
     * @param  array<string, int>  $indexes  root id|site → item index
     */
    private function entryItem(int $index, array $target, array $indexes): RunItem
    {
        ['root' => $root, 'site' => $site, 'root_missing' => $missing, 'parent' => $parent] = $target;
        $item = new RunItem($index, ContentType::Entry, $target['source'], $site, $target['title']);
        $dependsOn = [];

        if ($target['chained']) {
            $dependsOn[] = $indexes[$root.'|'.$target['source_site']] ?? null;
        }

        if ($missing !== null) {
            $rootIndex = $missing['family'] === null ? null : $indexes[$missing['family'].'|'.$site] ?? null;

            if ($rootIndex === null) {
                $item->status = RunItemStatus::Skipped;
                $item->reason = TargetResult::ROOT_MISSING;
                $item->error = $missing['error'];
                $item->rootPage = $missing['page'];

                return $item;
            }

            $dependsOn[] = $rootIndex;
        }

        if ($parent !== null) {
            $parentIndex = $indexes[$parent['family'].'|'.$site] ?? null;
            $dependsOn[] = $parentIndex;

            if ($parentIndex === null) {
                $item->warnings[] = __('Its parent page, :parent, has no localization in :site, so this page will be placed at the top level.', [
                    'parent' => $parent['title'],
                    'site' => Site::get($site)->name(),
                ]);
            }
        }

        $item->dependsOn = array_values(array_filter($dependsOn, fn (?int $dependency) => $dependency !== null));

        return $item;
    }

    /**
     * The entry of the page a new localization of $source is placed under, when that is not the top level.
     */
    private function parentPage(EntryContract $source): ?EntryContract
    {
        $collection = $source->collection();

        // Core does not place localizations of orderable collections in their tree.
        if ($collection->structure() === null || $collection->orderable()) {
            return null;
        }

        $parent = $source->parent();

        return $parent === null || $parent->isRoot() ? null : $parent->entry();
    }

    // §A11: an existing localization is translated from its origin; a missing one from the family's entry in the source site, else from the root.
    private function source(EntryContract $root, string $site, ?string $sourceSite): EntryContract
    {
        return $root->in($site)?->origin()
            ?? ($sourceSite === null ? null : $root->in($sourceSite))
            ?? $root;
    }

    /**
     * The named containers, or with [ALL] every one that has more than one site or is assumed to have the request's.
     *
     * @param  list<string>  $handles
     * @param  class-string<Collection|Taxonomy|GlobalSet>  $facade
     * @param  string  $label  what an unknown handle is called in the error
     * @param  list<string>  $assumed  the handles the request assumes to have its sites (RunRequest::$assumedSites)
     * @return list<string>
     */
    private function containers(array $handles, string $facade, string $label, array $assumed = []): array
    {
        if (RunRequest::all($handles)) {
            return $facade::all()
                ->filter(fn ($container) => $container->sites()->count() > 1 || in_array($container->handle(), $assumed, true))
                ->map->handle()
                ->values()
                ->all();
        }

        foreach ($handles as $handle) {
            if ($facade::find($handle) === null) {
                throw new InvalidArgumentException("{$label} [{$handle}] not found.");
            }
        }

        return array_values(array_unique($handles));
    }

    /**
     * @param  array<string, PromptContext>  $contexts  by site, built once per site because each builds a glossary
     * @param  ?Closure(RunItem, Plan): void  $planned
     */
    private function estimateItem(Run $run, int $index, PlanOptions $options, array &$contexts, ?Closure $planned): Estimate
    {
        $item = $this->runs->item($run, $index);

        if ($item?->status !== RunItemStatus::Pending) {
            return new Estimate;
        }

        try {
            $target = $this->factory->fromDescription($item->describe(), (array) ($run->scope['assumed_sites'] ?? []));
        } catch (InvalidTarget|NotSupportedYet|TargetNotFound|UnknownSite) {
            // Translating fails the item and says why.
            return new Estimate;
        }

        $plan = $options->mode === Mode::Review ? $this->planner->planReview($target, $options) : $this->planner->plan($target, $options);

        if ($planned !== null) {
            $planned($item, $plan);
        }

        if ($plan->isEmpty()) {
            if ($this->runs->isReady($run, $index)) {
                $this->skipUnclaimed($run, $index, $plan);
            }

            return new Estimate;
        }

        return $this->estimator->forPlan($plan, $contexts[$item->targetSite] ??= PromptContext::fromSettings($this->settings, $item->targetSite));
    }

    // Read again right before the write: a worker may have taken the item while it was planned. It is skipped as when
    // translating skips it.
    private function skipUnclaimed(Run $run, int $index, Plan $plan): void
    {
        $item = $this->runs->item($run, $index);

        if ($item?->status !== RunItemStatus::Pending || $item->claim !== null) {
            return;
        }

        $item->skipNothingToDo($plan);

        try {
            $this->runs->saveItem($run, $item);
        } catch (ClaimLost) {
        }
    }

    /**
     * @param  array<string, int>  $skipped
     */
    private static function count(array &$skipped, string $reason): void
    {
        $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
    }
}
