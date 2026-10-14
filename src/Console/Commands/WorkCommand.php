<?php

namespace Reach\StatamicTolk\Console\Commands;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Exceptions\RunNotFound;
use Reach\StatamicTolk\Runs\RunProcessor;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Console\RunsInPlease;

/**
 * A worker process of `tolk:translate` (§A12 "Drivers"): translates the run's items as worker `cli-{lane}` until none
 * is left to translate or the run stops running. It never applies; the command does. When the command ends without
 * cancelling the run (`kill -9`, the out-of-memory killer), the worker cancels it before its next unit and stops; a run
 * the CP resumed meanwhile has another driver, and the worker only stops.
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
        {--lane=1 : The lane of this worker, which gives its worker id}
        {--parent= : The process id of the command that started this worker; when it ends first, the worker cancels the run and stops}';

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

        $parent = $this->option('parent');

        if ($parent !== null && (! ctype_digit((string) $parent) || (int) $parent < 1)) {
            $this->components->error("Invalid parent [{$parent}]. Use the process id of the command that starts the worker.");

            return self::FAILURE;
        }

        // Ctrl-C in the terminal reaches the workers too. tolk:translate cancels the run, which ends the current call
        // before its next unit, so the unit in progress is kept instead of being paid for again.
        $this->trap(fn () => [SIGINT], fn () => null);

        $ttl = max(1, (int) config('tolk.runs.claim_ttl'));
        $ended = $this->commandEnded($parent === null ? null : (int) $parent);

        try {
            do {
                $settings->fresh();
                $report = $processor->translate($run, 'cli-'.(int) $lane, CarbonImmutable::now()->addSeconds(self::DEADLINE_SECONDS), $ttl, proceed: fn () => ! $ended());

                // Nobody saves what the workers translate any more: the run is cancelled, to be resumed, instead of paid
                // for to its end, unless the CP resumed it meanwhile, which hands it to another driver.
                if ($ended()) {
                    $processor->cancel($run, RunDriver::Cli);

                    break;
                }

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

    /**
     * Whether the command that started this worker has ended. It waits for its workers, so one that ends first was killed
     * or died of a fatal error. A worker the command started directly is its child until it ends; one started through a
     * shell (Symfony's Process on PHP built with `--enable-sigchild`) checks that the process exists. Without `--parent`,
     * or without the posix extension (Windows), the command never reads as ended.
     *
     * @return Closure(): bool
     */
    private function commandEnded(?int $parent): Closure
    {
        if ($parent === null || ! function_exists('posix_getppid') || ! function_exists('posix_kill')) {
            return fn () => false;
        }

        return posix_getppid() === $parent
            ? fn () => posix_getppid() !== $parent
            : fn () => ! posix_kill($parent, 0);
    }
}
