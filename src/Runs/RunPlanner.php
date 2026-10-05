<?php

namespace Reach\StatamicTolk\Runs;

use InvalidArgumentException;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\Prompts\PromptContext;
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
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetResult;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Turns a RunRequest into a run and its items (§A12), and estimates a stored run item by item. Building only enumerates
 * targets and never plans them, so it stays fast enough for a web request.
 */
final readonly class RunPlanner
{
    public const string EXCLUDED_COLLECTION = 'excluded_collection';

    public const string SITE_NOT_IN_COLLECTION = 'site_not_in_collection';

    // Out-of-scope reasons that say nothing about text: a fieldtype without a handler, and the slug, never translated (D16).
    private const array NOT_TEXT = ['unsupported_fieldtype', 'slug'];

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
     * text-bearing top-level fields of its targets that are never translated, with the reason (§A2).
     */
    public function build(RunRequest $request): PlannedRun
    {
        foreach (array_filter([...$request->sites, $request->sourceSite]) as $site) {
            if (Site::get($site) === null) {
                throw UnknownSite::for($site);
            }
        }

        $options = $this->runOptions($request);
        $skipped = $outOfScope = [];
        $items = $this->entryItems($this->entryTargets($request, $skipped, $outOfScope), Mode::from($options['mode']) !== Mode::Review);
        $scope = [...$request->scope(), 'skipped' => $skipped, 'out_of_scope' => array_values($outOfScope)];

        return new PlannedRun(
            Run::make($request->driver, $scope, $options, $request->user),
            $items,
            $skipped,
        );
    }

    /**
     * Plans the run's pending items from $fromIndex on, without storing the plans, until the budget is used (at least
     * one item), adds them to the run's estimate, and marks an item whose plan is empty `skipped`. Index 0 starts the
     * estimate over; a call from any index other than where the last one stopped (a retried request) adds nothing.
     */
    public function estimate(Run $run, int $fromIndex, int $budgetMs): EstimateProgress
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
            $estimate = $estimate->add($this->estimateItem($run, $index++, $options, $contexts));

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
     * Every family × site target the user may edit, family by family, keyed by site. The other pairs are counted in
     * $skipped; a root's own site is not a target at all.
     *
     * @param  array<string, int>  $skipped
     * @param  array<string, array>  $outOfScope  see noteOutOfScope()
     * @return list<array<string, array{root: EntryContract, site: string, source: EntryContract, target: EntryTarget}>>
     */
    private function entryTargets(RunRequest $request, array &$skipped, array &$outOfScope): array
    {
        $collections = $this->collections($request->collections);

        if ($collections === [] && $request->entryIds === []) {
            return [];
        }

        $excluded = $this->settings->excludedCollections();
        $families = $explained = [];

        foreach ($this->families->roots($collections, $request->entryIds) as $root) {
            $collection = $root->collection();
            $family = [];

            foreach ($request->sites as $site) {
                if ($site === $root->locale()) {
                    continue;
                }

                if (in_array($collection->handle(), $excluded, true)) {
                    self::count($skipped, self::EXCLUDED_COLLECTION);

                    continue;
                }

                if (! $collection->sites()->contains($site)) {
                    self::count($skipped, self::SITE_NOT_IN_COLLECTION);

                    continue;
                }

                $source = $this->source($root, $site, $request->sourceSite);
                $target = $this->factory->forEntry($source, $site);

                if ($request->user !== null && ! $target->authorize($request->user)) {
                    self::count($skipped, RunItem::UNAUTHORIZED);

                    continue;
                }

                $blueprint = $collection->handle().'.'.$source->blueprint()->handle();

                if (! isset($explained[$blueprint])) {
                    $explained[$blueprint] = true;
                    $this->noteOutOfScope($target, $outOfScope);
                }

                $family[$site] = compact('root', 'site', 'source', 'target');
            }

            if ($family !== []) {
                $families[] = $family;
            }
        }

        return $families;
    }

    /**
     * Notes the target's text-bearing top-level fields that are out of scope, once per container, field and reason.
     *
     * @param  array<string, array{container: string, handle: string, label: string, type: string, reason: string}>  $outOfScope
     */
    private function noteOutOfScope(Target $target, array &$outOfScope): void
    {
        foreach ($target->explainFields() as $field) {
            if ($field['in_scope'] || in_array($field['reason'], self::NOT_TEXT, true)) {
                continue;
            }

            $outOfScope[$target->container().'.'.$field['handle'].':'.$field['reason']] ??= [
                'container' => $target->container(),
                'handle' => $field['handle'],
                'label' => (string) $field['display'],
                'type' => $field['type'],
                'reason' => $field['reason'],
            ];
        }
    }

    /**
     * Indexes the targets in family order, a family's chain sources before the targets translated from them, then
     * gives each item its dependencies. They may point at any item of the run, since the source trees of a run's
     * targets need not have one shape.
     *
     * @param  list<array<string, array{root: EntryContract, site: string, source: EntryContract, target: EntryTarget}>>  $families
     * @param  bool  $creates  whether missing localizations are created (every mode but review)
     * @return list<RunItem>
     */
    private function entryItems(array $families, bool $creates): array
    {
        $targets = [];
        $indexes = [];

        foreach ($families as $family) {
            foreach ($this->chainOrder($family) as $target) {
                $indexes[$target['root']->id().'|'.$target['site']] = count($targets);
                $targets[] = $target;
            }
        }

        return array_map(fn (array $target, int $index) => $this->entryItem($index, $target, $indexes, $creates), $targets, array_keys($targets));
    }

    /**
     * @param  array<string, array{root: EntryContract, site: string, source: EntryContract, target: EntryTarget}>  $family
     * @return list<array{root: EntryContract, site: string, source: EntryContract, target: EntryTarget}>
     */
    private function chainOrder(array $family): array
    {
        $ordered = [];

        while ($family !== []) {
            $next = array_key_first($family);

            foreach ($family as $site => $target) {
                if (! isset($family[$target['source']->locale()])) {
                    $next = $site;

                    break;
                }
            }

            $ordered[] = $family[$next];
            unset($family[$next]);
        }

        return $ordered;
    }

    /**
     * @param  array{root: EntryContract, site: string, source: EntryContract, target: EntryTarget}  $target
     * @param  array<string, int>  $indexes  root id|site → item index
     */
    private function entryItem(int $index, array $target, array $indexes, bool $creates): RunItem
    {
        ['root' => $root, 'site' => $site, 'source' => $source, 'target' => $entryTarget] = $target;
        $item = new RunItem($index, ContentType::Entry, (string) $source->id(), $site, $entryTarget->title());
        $dependsOn = [];

        if ($source->hasOrigin()) {
            $dependsOn[] = $indexes[$root->id().'|'.$source->locale()] ?? null;
        }

        // Only a localization created by the run is placed in a tree (§A11 "Create" and "Root page").
        if ($creates && ! $entryTarget->exists()) {
            try {
                $entryTarget->ensureRootExists();
            } catch (RootMissing $missing) {
                $rootIndex = $this->indexOf(Entry::find($missing->rootId ?? ''), $site, $indexes);

                if ($rootIndex === null) {
                    $item->status = RunItemStatus::Skipped;
                    $item->reason = TargetResult::ROOT_MISSING;
                    $item->error = $missing->getMessage();

                    return $item;
                }

                $dependsOn[] = $rootIndex;
            }

            $parent = $this->parentPage($source);

            if ($parent !== null && $parent->in($site) === null) {
                $parentIndex = $this->indexOf($parent, $site, $indexes);
                $dependsOn[] = $parentIndex;

                if ($parentIndex === null) {
                    $item->warnings[] = sprintf(
                        'Its parent page, %s, has no localization in %s, so this page will be placed at the top level.',
                        $parent->value('title') ?? $parent->slug(),
                        Site::get($site)->name(),
                    );
                }
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

    /**
     * @param  array<string, int>  $indexes
     */
    private function indexOf(?EntryContract $entry, string $site, array $indexes): ?int
    {
        return $entry === null ? null : $indexes[$entry->root()->id().'|'.$site] ?? null;
    }

    // §A11: an existing localization is translated from its origin; a missing one from the family's entry in the source site, else from the root.
    private function source(EntryContract $root, string $site, ?string $sourceSite): EntryContract
    {
        return $root->in($site)?->origin()
            ?? ($sourceSite === null ? null : $root->in($sourceSite))
            ?? $root;
    }

    /**
     * @param  list<string>  $handles
     * @return list<string>
     */
    private function collections(array $handles): array
    {
        if (RunRequest::all($handles)) {
            return Collection::all()
                ->filter(fn ($collection) => $collection->sites()->count() > 1)
                ->map->handle()
                ->values()
                ->all();
        }

        foreach ($handles as $handle) {
            if (Collection::find($handle) === null) {
                throw new InvalidArgumentException("Collection [{$handle}] not found.");
            }
        }

        return array_values(array_unique($handles));
    }

    /**
     * @param  array<string, PromptContext>  $contexts  by site, built once per site because each builds a glossary
     */
    private function estimateItem(Run $run, int $index, PlanOptions $options, array &$contexts): Estimate
    {
        $item = $this->runs->item($run, $index);

        if ($item?->status !== RunItemStatus::Pending) {
            return new Estimate;
        }

        try {
            $target = $this->factory->fromDescription($item->describe());
        } catch (InvalidTarget|NotSupportedYet|TargetNotFound|UnknownSite) {
            // Translating fails the item and says why.
            return new Estimate;
        }

        $plan = $options->mode === Mode::Review ? $this->planner->planReview($target, $options) : $this->planner->plan($target, $options);

        if ($plan->isEmpty()) {
            $this->skipUnclaimed($run, $index);

            return new Estimate;
        }

        return $this->estimator->forPlan($plan, $contexts[$item->targetSite] ??= PromptContext::fromSettings($this->settings, $item->targetSite));
    }

    // Read again right before the write: a worker may have taken the item while it was planned.
    private function skipUnclaimed(Run $run, int $index): void
    {
        $item = $this->runs->item($run, $index);

        if ($item?->status !== RunItemStatus::Pending || $item->claim !== null) {
            return;
        }

        $item->status = RunItemStatus::Skipped;
        $item->reason = TargetResult::NOTHING_TO_DO;

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
