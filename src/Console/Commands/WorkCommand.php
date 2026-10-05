<?php

namespace Reach\StatamicTolk\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Exceptions\RunNotFound;
use Reach\StatamicTolk\Runs\RunProcessor;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Console\RunsInPlease;

/**
 * A worker process of `tolk:translate` (§A12 "Drivers"): translates the run's items as worker `cli-{lane}` until none
 * is left to translate or the run stops running. It never applies; the command does.
 */
class WorkCommand extends Command
{
    use RunsInPlease;

    /**
     * Seconds each RunProcessor::translate() call starts new units, so settings changes are read between calls.
     */
    public const int DEADLINE_SECONDS = 30;

    protected $signature = 'tolk:work
        {run : The id of the run}
        {--lane=1 : The lane of this worker, which gives its worker id}';

    protected $description = 'Translate the items of a run in a worker process of tolk:translate';

    protected $hidden = true;

    protected $hiddenInPlease = true;

    public function handle(RunRepository $runs, RunProcessor $processor, Settings $settings): int
    {
        $run = $runs->find((string) $this->argument('run'));
        $lane = $this->option('lane');

        if ($run === null) {
            $this->components->error("Run [{$this->argument('run')}] not found.");

            return self::FAILURE;
        }

        if (! ctype_digit((string) $lane) || (int) $lane < 1) {
            $this->components->error("Invalid lane [{$lane}]. Use a whole number of at least 1.");

            return self::FAILURE;
        }

        // Ctrl-C in the terminal reaches the workers too. tolk:translate cancels the run, which ends the current call
        // before its next unit, so the unit in progress is kept instead of being paid for again.
        $this->trap(fn () => [SIGINT], fn () => null);

        $ttl = max(1, (int) config('tolk.runs.claim_ttl'));

        try {
            do {
                $settings->fresh();
                $report = $processor->translate($run, 'cli-'.(int) $lane, CarbonImmutable::now()->addSeconds(self::DEADLINE_SECONDS), $ttl);

                // Nothing to claim now: an item waits for a dependency, a pause, or another worker.
                if ($report->remains && $report->wait !== 0) {
                    Sleep::for(max(1, $report->wait ?? 1))->seconds();
                }
            } while ($report->remains);
        } catch (RunNotFound) {
            // The run was deleted.
        }

        return self::SUCCESS;
    }
}
