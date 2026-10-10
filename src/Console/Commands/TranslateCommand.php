<?php

namespace Reach\StatamicTolk\Console\Commands;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Laravel\Prompts\Prompt;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\Providers;
use Reach\StatamicTolk\Console\Concerns\ReportsTranslations;
use Reach\StatamicTolk\Console\Concerns\ResolvesTranslationOptions;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Exceptions\ProviderNotConfigured;
use Reach\StatamicTolk\Exceptions\RunNotFound;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\Runs\Estimate;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunDispatcher;
use Reach\StatamicTolk\Runs\RunItem;
use Reach\StatamicTolk\Runs\RunPlanner;
use Reach\StatamicTolk\Runs\RunProcessor;
use Reach\StatamicTolk\Runs\RunRequest;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\GlobalsTarget;
use Reach\StatamicTolk\Targets\NavTarget;
use Reach\StatamicTolk\Translation\Plan;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetResult;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Symfony\Component\Console\Helper\ProgressBar;
use Throwable;

use function Laravel\Prompts\confirm;

/**
 * Translates entries, terms, global sets and navigation through a run (§A12): the run is estimated and confirmed here, then translated by `tolk:work`
 * worker processes while this process applies what they translated, one item at a time (§A11). With one worker, or
 * when no process can be started, this process does both.
 */
class TranslateCommand extends Command
{
    use ReportsTranslations;
    use ResolvesTranslationOptions;
    use RunsInPlease;

    /**
     * Seconds this process starts new units when it translates itself, before it applies what is ready.
     */
    public const int TRANSLATE_SECONDS = 5;

    /**
     * Seconds this process applies translated items before it looks at the workers and the progress again.
     */
    public const int APPLY_SECONDS = 5;

    public const int ESTIMATE_BUDGET_MS = 250;

    /**
     * The worker id of this process when it translates; the workers are `cli-1` and up (`tolk:work --lane`).
     */
    public const string WORKER = 'cli-0';

    public const string APPLIER = 'cli-apply';

    private const array TYPES = ['entry' => 'Entries', 'term' => 'Terms', 'globals' => 'Global sets', 'nav' => 'Navigation'];

    private const array LEFT_OUT = [
        RunPlanner::EXCLUDED_COLLECTION => ['target is in a collection excluded in the settings', 'targets are in collections excluded in the settings'],
        RunPlanner::SITE_NOT_IN_COLLECTION => ['target is in a site its collection does not have', 'targets are in sites their collection does not have'],
        RunPlanner::EXCLUDED_TAXONOMY => ['target is in a taxonomy excluded in the settings', 'targets are in taxonomies excluded in the settings'],
        RunPlanner::SITE_NOT_IN_TAXONOMY => ['target is in a site its taxonomy does not have', 'targets are in sites their taxonomy does not have'],
        GlobalsTarget::SITE_NOT_IN_SET => ['target is in a site its global set does not have', 'targets are in sites their global set does not have'],
        GlobalsTarget::NO_ORIGIN => ['target is in a site its global set has no origin for', 'targets are in sites their global set has no origin for'],
        GlobalsTarget::NO_BLUEPRINT => ['target is in a global set without a blueprint, so none of its fields is localizable', 'targets are in global sets without a blueprint, so none of their fields is localizable'],
        NavTarget::NO_SOURCE_TREE => ['target is in a navigation without a tree in the site it is translated from', 'targets are in navigations without a tree in the site they are translated from'],
        NavTarget::FROM_SITE => ['target is a navigation in the site it is translated from (--from)', 'targets are navigations in the site they are translated from (--from)'],
        RunItem::UNAUTHORIZED => ['target is one you may not edit', 'targets are ones you may not edit'],
    ];

    protected $signature = 'tolk:translate
        {--entry=* : Translate the family of this entry (any of its localizations names it)}
        {--collection=* : Translate every entry of this collection}
        {--taxonomy=* : Translate every term of this taxonomy}
        {--globals=* : Translate this global set}
        {--nav=* : Translate this navigation}
        {--all : Translate every collection, taxonomy and global set that has more than one site, and every navigation}
        {--site=* : The site to translate into (required)}
        {--from= : The site to translate a missing localization from, where the entry has one there, and a navigation from, where it has a tree there (default: the origin, and the tree of the default site; a term is always translated from the first site of its taxonomy, and a global set from the origin it names for the site)}
        {--mode=missing : missing (only what is not translated yet), update (also outdated translations), all (everything again) or review (only review the existing machine translations)}
        {--force : Overwrite fields that were edited by hand (in mode review: review them too; they stay manual)}
        {--publish : Publish a new localization when its origin is published, instead of saving it as a draft}
        {--publish-changes : On a collection with revisions, publish the changes to a published localization instead of leaving them unpublished}
        {--translator= : The model to translate with, as provider:model}
        {--review : Review the translations with a second model and apply its corrections (default from the settings)}
        {--no-review : Do not review the translations, whatever the settings say}
        {--reviewer= : The model to review with, as provider:model (implies --review)}
        {--concurrency= : The worker processes that translate in parallel (default from tolk.concurrency.cli; 1 translates in this process)}
        {--queue : Hand the run to the queue and print its address in the Control Panel, instead of running it here}
        {--no-estimate : Start without scanning the run for an estimate first}
        {--dry-run : Show what would be translated, without calling the model or saving anything}
        {--yes : Translate without asking for confirmation}';

    protected $description = 'Translate entries, terms, global sets and navigation into other sites';

    private RunRepository $runs;

    private RunProcessor $processor;

    private Settings $settings;

    private int $interrupts = 0;

    /** @var array<int, int> lane → exit code of the workers that stopped with an error */
    private array $failedWorkers = [];

    /** @var array<string, ?string> RunRequest::$assumedSites */
    private array $assumedSites = [];

    /**
     * Plans the run as if these containers had the sites: tolk:site passes what its dry run would enable.
     *
     * @param  array<string, ?string>  $assumedSites  RunRequest::$assumedSites
     */
    public function assumingSites(array $assumedSites): static
    {
        $this->assumedSites = $assumedSites;

        return $this;
    }

    public function handle(Settings $settings, ModelCatalog $catalog, Providers $providers, RunPlanner $planner, RunRepository $runs, RunProcessor $processor, RunDispatcher $dispatcher): int
    {
        $this->runs = $runs;
        $this->processor = $processor;
        $this->settings = $settings;
        $this->interrupts = 0;
        $this->failedWorkers = [];

        try {
            $sites = $this->sites();
            $mode = $this->mode();
            $review = $this->review($mode);
            $sourceSite = $this->sourceSite();

            if ($sourceSite !== null && $sites === [$sourceSite]) {
                throw new InvalidArgumentException("--from [{$sourceSite}] is the site to translate from, so it cannot be the only --site.");
            }

            [$collections, $entryIds, $taxonomies, $globals, $navs] = $this->selection();
            $concurrency = $this->concurrency();
            $this->checkQueue();
            $options = PlanOptions::fromSettings($settings, $catalog, [
                'translator' => $this->option('translator') ?: null,
                'mode' => $mode,
                'overwriteManual' => (bool) $this->option('force'),
                'review' => $review,
                'reviewer' => $this->option('reviewer') ?: null,
                'profile' => 'background',
            ]);

            $this->checkProviders($options, $providers);

            $planned = $planner->build(new RunRequest(
                sites: $sites,
                driver: $this->option('queue') ? RunDriver::Queue : RunDriver::Cli,
                sourceSite: $sourceSite,
                collections: $collections,
                taxonomies: $taxonomies,
                globals: $globals,
                navs: $navs,
                entryIds: $entryIds,
                options: [
                    'mode' => $mode->value,
                    'overwrite_manual' => (bool) $this->option('force'),
                    'review' => $review,
                    'translator' => $this->option('translator') ?: null,
                    'reviewer' => $this->option('reviewer') ?: null,
                    'new_status' => $this->option('publish') ? 'mirror' : null,
                    'publish_changes' => (bool) $this->option('publish-changes'),
                ],
                assumedSites: $this->assumedSites,
            ));
        } catch (InvalidArgumentException|MissingDefaultModel|NoProviderConfigured|ProviderNotConfigured|TargetNotFound $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->startReport($options->review);
        $reviewOnly = $options->mode === Mode::Review;

        foreach ($planned->skipped as $reason => $count) {
            $this->components->warn($this->leftOut($reason, $count));
        }

        if ($planned->items === []) {
            $this->components->info($reviewOnly ? 'Nothing to review.' : 'Nothing to translate.');

            return self::SUCCESS;
        }

        $this->header(count($planned->items), $sites, $options, $catalog);
        $run = $runs->create($planned->run, $planned->items);
        $this->trapInterrupts();

        $lines = [];
        $targets = 0;
        $estimate = $this->option('no-estimate') ? null : $this->scan($planner, $run, $lines, $targets);

        if ($this->interrupts > 0) {
            $runs->delete($run);
            $this->components->warn($reviewOnly ? 'Nothing was reviewed.' : 'Nothing was translated.');

            return self::FAILURE;
        }

        $this->printPlan($run, $estimate, $reviewOnly);

        if ($this->option('dry-run')) {
            $this->dryRun($run, $lines, $targets, $estimate, $options);
            $runs->delete($run);

            return self::SUCCESS;
        }

        // The estimate found nothing to do: there is nothing to confirm or to run.
        if ($runs->counts($run)[RunItemStatus::Pending->value] === 0) {
            $exitCode = $this->report($run);
            $runs->delete($run);

            return $exitCode;
        }

        if (! $this->confirmed($reviewOnly)) {
            $runs->delete($run);
            $this->components->warn($reviewOnly ? 'Nothing was reviewed.' : 'Nothing was translated.');

            return $this->interrupts > 0 ? self::FAILURE : self::SUCCESS;
        }

        if ($this->option('queue')) {
            $dispatcher->start($run);
            $this->newLine();
            $this->components->info("The run {$run->id} is on the queue [".RunDispatcher::connection().'].');
            $this->line('Follow it in the Control Panel: '.cp_route('tolk.runs.show', $run->id));

            return self::SUCCESS;
        }

        $processor->start($run);

        try {
            $run = $this->drive($run, $concurrency);
        } catch (RunNotFound) {
            $this->newLine();
            $this->components->error('The run was deleted while it was translated.');

            return self::FAILURE;
        }

        return $this->report($run);
    }

    /**
     * Applies translated items in this process while the workers translate, until the run ends, and shows the progress.
     * When no worker runs (none could be started, or they ended while the apply sent items back), this process
     * translates too, one item at a time. If this process fails, the run is cancelled, so the workers stop and the run
     * can be resumed.
     */
    private function drive(Run $run, int $concurrency): Run
    {
        $workers = $concurrency > 1 ? $this->startWorkers($run, $concurrency) : [];
        $ttl = max(1, (int) config('tolk.runs.claim_ttl'));

        if ($concurrency > 1 && $workers === []) {
            $this->line('<comment>No worker process could be started, so this process translates on its own.</comment>');
        }

        $this->newLine();
        $bar = $this->output->createProgressBar(array_sum($this->runs->counts($run)));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $this->advance($bar, $run);

        try {
            while ($this->interrupts === 0) {
                $this->settings->fresh();
                $workers = $this->reap($workers);
                $work = $workers === []
                    ? $this->processor->translate($run, self::WORKER, CarbonImmutable::now()->addSeconds(self::TRANSLATE_SECONDS), $ttl, 1)
                    : null;
                $applied = $this->processor->applyReady($run, self::APPLIER, CarbonImmutable::now()->addSeconds(self::APPLY_SECONDS));
                $run = $this->runs->find($run->id) ?? throw RunNotFound::for($run->id);
                $this->advance($bar, $run);

                if ($run->status !== RunStatus::Running) {
                    break;
                }

                if (($work?->units ?? 0) + ($work?->items ?? 0) + $applied->items + $applied->returned === 0) {
                    $this->pause(max(1, $work?->wait ?? $applied->wait ?? 1));
                }
            }

            if ($this->interrupts > 0) {
                $this->newLine(2);
                $this->components->warn('Cancelling the run.'.($this->running($workers) === [] ? '' : ' The worker processes finish their current request first; press Ctrl-C again to stop them at once.'));
                $this->processor->cancel($run);
            }
        } catch (Throwable $exception) {
            rescue(fn () => $this->processor->cancel($run), report: false);

            throw $exception;
        } finally {
            $this->waitFor($workers);
        }

        $run = $this->runs->find($run->id) ?? $run;
        $this->endBar($bar, in_array($run->status, [RunStatus::Completed, RunStatus::CompletedWithErrors], true));

        foreach ($this->failedWorkers as $lane => $exitCode) {
            $this->components->warn("Worker process {$lane} stopped with exit code {$exitCode}. Its error is in the log.");
        }

        return $run;
    }

    /**
     * @return array<int, InvokedProcess> by lane; empty when the site has no `please` file or no process could start
     */
    private function startWorkers(Run $run, int $count): array
    {
        $please = base_path('please');
        $workers = [];

        if (! is_file($please)) {
            return [];
        }

        try {
            foreach (range(1, $count) as $lane) {
                $workers[$lane] = Process::path(base_path())->forever()->quietly()
                    ->start([PHP_BINARY, $please, 'tolk:work', $run->id, "--lane={$lane}"]);
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return $workers;
    }

    /**
     * @param  array<int, InvokedProcess>  $workers
     * @return array<int, InvokedProcess> the workers still running
     */
    private function reap(array $workers): array
    {
        foreach ($workers as $lane => $worker) {
            if ($worker->running()) {
                continue;
            }

            $exitCode = $worker->wait()->exitCode();

            if ($exitCode !== 0 && $this->interrupts === 0) {
                $this->failedWorkers[$lane] = (int) $exitCode;
            }

            unset($workers[$lane]);
        }

        return $workers;
    }

    /**
     * @param  array<int, InvokedProcess>  $workers
     * @return array<int, InvokedProcess>
     */
    private function running(array $workers): array
    {
        return array_filter($workers, fn (InvokedProcess $worker) => $worker->running());
    }

    /**
     * Waits for the workers, which end once the run stops running. A second Ctrl-C stops them at once.
     *
     * @param  array<int, InvokedProcess>  $workers
     */
    private function waitFor(array $workers): void
    {
        while (($workers = $this->running($workers)) !== []) {
            if ($this->interrupts > 1) {
                foreach ($workers as $worker) {
                    $worker->stop(0);
                }
            }

            Sleep::for(200)->milliseconds();
        }
    }

    /**
     * Sleeps a second at a time, so Ctrl-C ends the wait.
     */
    private function pause(int $seconds): void
    {
        for ($slept = 0; $slept < $seconds && $this->interrupts === 0; $slept++) {
            Sleep::for(1)->second();
        }
    }

    private function advance(ProgressBar $bar, Run $run): void
    {
        $counts = $this->runs->counts($run);
        $finished = array_sum(array_map(fn (RunItemStatus $status) => $status->isFinal() ? $counts[$status->value] : 0, RunItemStatus::cases()));
        $waiting = $counts[RunItemStatus::Translated->value] + $counts[RunItemStatus::Applying->value];

        $bar->setMessage($waiting > 0 ? number_format($waiting).' translated, being saved' : '');
        $bar->setProgress($finished);
    }

    /**
     * Ctrl-C cancels the run once the current step is done, instead of leaving it running. Without pcntl, or under
     * tests, it ends the command at once.
     */
    private function trapInterrupts(): void
    {
        $this->trap(fn () => [SIGINT, SIGTERM], function () {
            $this->interrupts++;
        });
    }

    /**
     * Laravel Prompts reads the answer in raw mode, where Ctrl-C is a key: it declines the question and counts as an
     * interrupt. A pending read blocks signal handlers until it returns, so the trap is removed while the question is
     * asked, and a signal, or Ctrl-C where the question falls back to Symfony's (Windows), ends the command at once.
     * Without a terminal Prompts would take the default without reading, so an answer piped in goes to Symfony's
     * question, which reads it.
     */
    private function confirmed(bool $reviewOnly): bool
    {
        if ($this->option('yes')) {
            return true;
        }

        $question = $reviewOnly ? 'Review them and save the corrections?' : 'Translate and save them?';
        $this->untrap();

        if (! (defined('STDIN') && stream_isatty(STDIN))) {
            try {
                return $this->confirm($question, true);
            } finally {
                $this->trapInterrupts();
            }
        }

        Prompt::cancelUsing(function () {
            $this->interrupts++;

            return false;
        });

        try {
            return confirm($question);
        } finally {
            Prompt::cancelUsing(null);
            $this->trapInterrupts();
        }
    }

    /**
     * Estimates the run item by item behind a progress bar and collects, per item, the line a dry run prints.
     *
     * @param  array<int, ?string>  $lines  item index → what its plan does, null when it has nothing to do
     * @param  int  $targets  the plans that have something to do
     */
    private function scan(RunPlanner $planner, Run $run, array &$lines, int &$targets): Estimate
    {
        $total = array_sum($this->runs->counts($run));
        $collect = function (RunItem $item, Plan $plan) use (&$lines, &$targets) {
            $lines[$item->index] = $plan->isEmpty() ? null : $this->describePlan($plan);

            if (! $plan->isEmpty()) {
                $targets++;
            }
        };

        $this->newLine();
        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' Scanning %current%/%max% [%bar%] %percent:3s%%');
        $bar->start();
        $progress = null;

        do {
            $progress = $planner->estimate($run, $progress?->next ?? 0, self::ESTIMATE_BUDGET_MS, $collect);
            $bar->setProgress($progress->next ?? $total);
        } while (! $progress->isFinished() && $this->interrupts === 0);

        $this->endBar($bar, $progress->isFinished());

        return $progress->estimate;
    }

    /**
     * Ends the bar's line: full when its work is finished, else where the work stopped, which finish() would fill.
     * Throttling may not have drawn the last step yet. clear(), on the next and empty line, then resets the terminal's
     * progress indicator (Symfony 7.4 and later), as finish() does.
     */
    private function endBar(ProgressBar $bar, bool $finished): void
    {
        if ($finished) {
            $bar->finish();
            $this->newLine();

            return;
        }

        $bar->display();
        $this->newLine();
        $bar->clear();
    }

    /**
     * The items per content type and site, and the estimate.
     */
    private function printPlan(Run $run, ?Estimate $estimate, bool $reviewOnly): void
    {
        $this->newLine();

        foreach ($this->runs->breakdown($run) as ['type' => $type, 'site' => $site, 'counts' => $counts]) {
            $pending = $counts[RunItemStatus::Pending->value];
            $skipped = $counts[RunItemStatus::Skipped->value];

            $this->components->twoColumnDetail(
                (self::TYPES[$type] ?? $type).' → '.$this->siteName($site),
                implode(', ', array_filter([
                    $pending > 0 ? number_format($pending).' to '.($reviewOnly ? 'review' : 'translate') : null,
                    $skipped > 0 ? number_format($skipped).' skipped' : null,
                ])),
            );
        }

        if ($estimate !== null) {
            $this->printEstimate($estimate);
        }
    }

    /**
     * A line per item: what its plan does, or why it would be skipped, as the run's report says; then the totals of the
     * plans.
     *
     * @param  array<int, ?string>  $lines
     * @param  int  $targets  the plans that have something to do
     */
    private function dryRun(Run $run, array $lines, int $targets, ?Estimate $estimate, PlanOptions $options): void
    {
        $items = $this->items($run);
        $this->newLine();

        foreach ($items as $item) {
            $line = match (true) {
                isset($lines[$item->index]) => $lines[$item->index],
                // The scan keeps an item that waits for others pending: the run plans it again when its turn comes.
                array_key_exists($item->index, $lines) => $item->status === RunItemStatus::Pending
                    ? '<comment>nothing to do yet</comment>, planned again once the targets it waits for are translated'
                    : '<comment>nothing to do</comment>',
                $item->reason === TargetResult::ROOT_MISSING => '<comment>would be skipped</comment>, '.$item->error,
                default => null,
            };

            if ($line !== null) {
                $this->output->writeln("  {$this->itemLabel($item)}: {$line}");
            }
        }

        $this->newLine();
        $this->line($estimate !== null
            ? sprintf(
                'Dry run: %s of %s would be %s (%s, %s). Nothing was sent or saved.',
                number_format($targets),
                $this->count(count($items), 'target'),
                $this->outcomeVerb($options),
                $this->count($estimate->segments, 'segment'),
                $this->count($estimate->chars, 'character'),
            )
            : sprintf('Dry run: %s, not estimated. Nothing was sent or saved.', $this->count(count($items), 'target')));

        $this->noteRunOutOfScope($run->scope['out_of_scope'] ?? []);
        $this->summarizeOutOfScope();
    }

    /**
     * A line per finished item, what was left unfinished, the summary, and the exit code: 1 when an item failed or the
     * run did not end by itself.
     */
    private function report(Run $run): int
    {
        $unsaved = $untranslated = 0;
        $this->newLine();

        foreach ($this->items($run) as $item) {
            if ($item->status->isFinal()) {
                $this->reportItem($item);
            } elseif (in_array($item->status, [RunItemStatus::Translated, RunItemStatus::Applying], true)) {
                $unsaved++;
            } else {
                $untranslated++;
            }
        }

        $this->noteRunOutOfScope($run->scope['out_of_scope'] ?? []);

        if ($run->status === RunStatus::Aborted) {
            $this->newLine();
            $this->components->error($run->error ?? 'The run was stopped.');
        }

        if ($unsaved + $untranslated > 0) {
            $this->newLine();
            $this->components->warn($this->unfinished($run, $unsaved, $untranslated));
            $this->line('  '.cp_route('tolk.runs.show', $run->id));
        }

        $this->summarize();

        return $this->resultCounts()['failed'] > 0 || in_array($run->status, [RunStatus::Aborted, RunStatus::Cancelled], true)
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * What is left of a run that did not finish, and the way on. Only a resume saves what was translated without paying
     * for it again, and only until its plans expire; after an abort, the same cause stops a new run too.
     *
     * @param  int  $unsaved  the items translated but not saved
     * @param  int  $untranslated  the items not translated
     */
    private function unfinished(Run $run, int $unsaved, int $untranslated): string
    {
        $aborted = $run->status === RunStatus::Aborted;
        $them = fn (int $count) => $count === 1 ? 'it' : 'them';
        $facts = implode(', and ', array_filter([
            $unsaved > 0 ? $this->count($unsaved, 'target').($unsaved === 1 ? ' was' : ' were').' translated but not saved' : null,
            $untranslated > 0 ? $this->count($untranslated, 'target').($untranslated === 1 ? ' was' : ' were').' not translated' : null,
        ]));

        if ($unsaved > 0) {
            return sprintf(
                '%s. Resume the run in the Control Panel within %s to save %s without translating %s again%s:',
                $facts,
                CarbonInterval::seconds(max(1, (int) config('tolk.plan_ttl', 7200)))->cascade()->forHumans(),
                $them($unsaved),
                $them($unsaved),
                match (true) {
                    $untranslated === 0 => '',
                    $aborted => ', and to translate the rest once the cause above is fixed',
                    default => ', and to translate the rest',
                },
            );
        }

        return $aborted
            ? "{$facts}. Resume the run in the Control Panel once the cause above is fixed:"
            : "{$facts}. Run the command again to translate {$them($untranslated)}, or resume the run in the Control Panel:";
    }

    /**
     * @param  list<string>  $sites
     */
    private function header(int $targets, array $sites, PlanOptions $options, ModelCatalog $catalog): void
    {
        $this->line(sprintf(
            'Targets: %s (%s). %sMode: %s.',
            number_format($targets),
            implode(', ', array_map($this->siteName(...), $sites)),
            $this->describeModels($options, $catalog),
            $options->mode->value,
        ));
        $this->noteFakeEngine();
    }

    /**
     * @return list<RunItem>
     */
    private function items(Run $run): array
    {
        return $this->runs->items($run, null, 1, PHP_INT_MAX);
    }

    /**
     * @return list<string>
     */
    private function sites(): array
    {
        $sites = array_values(array_unique($this->option('site')));

        if ($sites === []) {
            throw new InvalidArgumentException('Name the sites to translate into with --site.');
        }

        foreach ($sites as $site) {
            if (Site::get($site) === null) {
                throw UnknownSite::for($site);
            }
        }

        return $sites;
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<string>, 4: list<string>} the collections, the entry ids, the taxonomies, the global sets and the navigations
     */
    private function selection(): array
    {
        $selected = [];

        foreach (['collection', 'entry', 'taxonomy', 'globals', 'nav'] as $option) {
            $selected[$option] = array_values(array_unique($this->option($option)));
        }

        $named = array_merge(...array_values($selected)) !== [];

        if ($this->option('all')) {
            if ($named) {
                throw new InvalidArgumentException('--all cannot be combined with --collection, --entry, --taxonomy, --globals or --nav.');
            }

            return [[RunRequest::ALL], [], [RunRequest::ALL], [RunRequest::ALL], [RunRequest::ALL]];
        }

        if (! $named) {
            throw new InvalidArgumentException('Name what to translate with --collection, --entry, --taxonomy, --globals, --nav or --all.');
        }

        $containers = [
            'collection' => ['Collection', fn (string $handle) => Collection::find($handle)],
            'taxonomy' => ['Taxonomy', fn (string $handle) => Taxonomy::find($handle)],
            'globals' => ['Global set', fn (string $handle) => GlobalSet::findByHandle($handle)],
            'nav' => ['Navigation', fn (string $handle) => Nav::findByHandle($handle)],
        ];

        foreach ($containers as $option => [$label, $find]) {
            foreach ($selected[$option] as $handle) {
                if ($find($handle) === null) {
                    throw new InvalidArgumentException("{$label} [{$handle}] not found.");
                }
            }
        }

        foreach ($selected['entry'] as $id) {
            if (Entry::find($id) === null) {
                throw TargetNotFound::entry($id);
            }
        }

        return array_values($selected);
    }

    private function leftOut(string $reason, int $count): string
    {
        [$one, $many] = self::LEFT_OUT[$reason] ?? ['target is left out: '.str_replace('_', ' ', $reason), 'targets are left out: '.str_replace('_', ' ', $reason)];

        return number_format($count).' '.($count === 1 ? $one : $many).'.';
    }
}
