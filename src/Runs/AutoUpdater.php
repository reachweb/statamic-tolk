<?php

namespace Reach\StatamicTolk\Runs;

use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Exceptions\RunNotFound;
use Reach\StatamicTolk\Jobs\WorkRun;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\EntryFamilies;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Site;

/**
 * Automatic updates (T11.1): saving a published entry in its origin updates its translations with a run of that entry,
 * as the origin's Update translations does. The run's lanes go on the queue when Tolk's runs use a queue with a worker;
 * otherwise the process that saved the entry drives the run after its response (Jobs\UpdateTranslations).
 */
final readonly class AutoUpdater
{
    /**
     * How many of the most recent runs are searched for the latest automatic update of a localization.
     */
    public const int RECENT = 50;

    /**
     * The worker id of the process that drives a run itself.
     */
    public const string WORKER = 'auto';

    /**
     * Seconds each RunProcessor::translate() call of drive() starts new units, so a cancel is seen between calls.
     */
    public const int TRANSLATE_SECONDS = 30;

    /**
     * Milliseconds each call of RunPlanner::estimate() plans items for.
     */
    private const int ESTIMATE_MS = 10_000;

    public function __construct(
        private Settings $settings,
        private EntryFamilies $families,
        private RunPlanner $planner,
        private RunRepository $runs,
        private RunProcessor $processor,
        private RunDispatcher $dispatcher,
    ) {}

    /**
     * Whether automatic updates go on the queue: Tolk's runs use the queue, and its connection has a worker.
     */
    public static function queued(): bool
    {
        return RunDispatcher::resolveDriver() === RunDriver::Queue && RunDispatcher::hasWorker();
    }

    /**
     * Whether saving the entry now, as $user, updates its translations: the settings cover its collection, it is
     * published, and it is the origin of a site to update.
     */
    public function applies(EntryContract $entry, ?User $user = null): bool
    {
        return $this->covers($entry) && $entry->published() && $this->sites($entry, $user) !== [];
    }

    /**
     * Whether the settings update the entries of the entry's collection automatically.
     */
    public function covers(EntryContract $entry): bool
    {
        $collection = $entry->collectionHandle();
        $collections = $this->settings->autoUpdateCollections();

        return $this->settings->autoUpdate()
            && $entry->collection()->sites()->count() > 1
            && ! $this->settings->excludes(ContentType::Entry, $collection)
            && ($collections === [] || in_array($collection, $collections, true));
    }

    /**
     * The sites an update of the entry as $user translates into: those of its direct localizations and, when the
     * settings create missing localizations and the entry is the root of its family, the sites it has none in. A user
     * gets only the sites they may access, so they may follow the run (§A14); without one, every site.
     *
     * @return list<string>
     */
    public function sites(EntryContract $entry, ?User $user = null): array
    {
        $sites = $this->families->originSites($entry, $this->settings->autoUpdateCreate());

        return $user === null ? $sites : array_values(array_filter($sites, fn (string $site) => $user->can('view', Site::get($site))));
    }

    /**
     * Whether a run from the entry has not finished and is still moving, an automatic one or one pushed from its banner:
     * a new run would translate the same fields again. A run that stopped moving no longer counts. Every run is searched,
     * because an import that saves many entries makes as many runs while an earlier one still works.
     */
    public function busy(EntryContract $entry): bool
    {
        $reference = $entry->reference();
        $cutoff = CarbonImmutable::now()->subSeconds(max(1, (int) config('tolk.runs.claim_ttl')));

        foreach ($this->runs->recent(PHP_INT_MAX) as $run) {
            if (! $run->status->isFinal() && ($run->scope['reference'] ?? null) === $reference && ! $run->lastActivityAt()->lessThan($cutoff)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Makes the entry's run, an update of its sites as $user, and plans it without calling a model. A run with nothing
     * to translate is deleted. Otherwise it starts: for the caller to drive() when $drive, else with lanes on the queue.
     *
     * @return ?Run the started run, or null when there is nothing to translate
     */
    public function start(EntryContract $entry, ?User $user, bool $drive): ?Run
    {
        $sites = $this->sites($entry, $user);

        if ($sites === []) {
            return null;
        }

        $planned = $this->planner->build(new RunRequest(
            sites: $sites,
            driver: $drive ? RunDriver::Cli : RunDriver::Queue,
            sourceSite: $entry->locale(),
            entryIds: [(string) $entry->id()],
            options: ['mode' => Mode::Update->value, 'publish_changes' => $this->settings->autoUpdatePublish()],
            user: $user,
            reference: $entry->reference(),
            automatic: true,
        ));

        if ($planned->items === []) {
            return null;
        }

        $run = $this->runs->create($planned->run, $planned->items);
        $next = 0;

        do {
            $next = $this->planner->estimate($run, $next, self::ESTIMATE_MS)->next;
        } while ($next !== null);

        if ($this->runs->counts($run)[RunItemStatus::Pending->value] === 0) {
            $this->runs->delete($run);

            return null;
        }

        $drive ? $this->processor->start($run) : $this->dispatcher->start($run);

        return $run;
    }

    /**
     * Translates and saves the run's items in this process until it is no longer running, as a queue lane does
     * (WorkRun), waiting in between as long as the run asks.
     */
    public function drive(Run $run): void
    {
        $ttl = max(1, (int) config('tolk.runs.claim_ttl'));

        try {
            while (true) {
                $work = $this->processor->translate($run, self::WORKER, CarbonImmutable::now()->addSeconds(self::TRANSLATE_SECONDS), $ttl);
                $applied = $this->processor->applyReady($run, self::WORKER, CarbonImmutable::now()->addSeconds(WorkRun::APPLY_SECONDS));

                if ($this->runs->find($run->id)?->status !== RunStatus::Running) {
                    return;
                }

                Sleep::for(WorkRun::waitSeconds($work, $applied))->seconds();
            }
        } catch (RunNotFound) {
            // The run was deleted.
        }
    }

    /**
     * When an automatic update last saved the localization in $site of the entry: the latest automatic run from the
     * entry, among the recent ones, whose item for the site is done.
     */
    public function lastUpdate(EntryContract $origin, string $site): ?CarbonImmutable
    {
        $reference = $origin->reference();

        foreach ($this->runs->recent(self::RECENT) as $run) {
            if (($run->scope['automatic'] ?? false) !== true || ($run->scope['reference'] ?? null) !== $reference || ! in_array($site, (array) ($run->scope['sites'] ?? []), true)) {
                continue;
            }

            foreach ($this->runs->items($run, RunItemStatus::Done, 1, count($run->sites())) as $item) {
                if ($item->targetSite === $site) {
                    return $run->finishedAt ?? $run->progressedAt ?? $run->createdAt;
                }
            }
        }

        return null;
    }
}
