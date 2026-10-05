<?php

namespace Reach\StatamicTolk\Runs;

use Closure;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Jobs\WorkRun;

/**
 * Decides which driver runs a run and starts it (§A12 "Drivers"). Queue runs get `concurrency.queue` lane jobs, which
 * carry the run's generation: starting the run again, or taking it over in the browser, makes a new generation, and
 * the lanes of an older one end at their next step.
 */
final readonly class RunDispatcher
{
    /**
     * Queue drivers that run a job in the process that dispatches it, or never: a run on them has no worker of its own.
     */
    public const array WITHOUT_WORKER = ['sync', 'deferred', 'background', 'null'];

    public function __construct(
        private RunRepository $runs,
        private RunProcessor $processor,
    ) {}

    /**
     * The driver for a run started from the CP: `runs.execution` as configured, and for `auto` the queue when its
     * connection has a worker, otherwise the browser.
     */
    public static function resolveDriver(): RunDriver
    {
        $execution = config('tolk.runs.execution') ?? 'auto';

        return match ($execution) {
            'queue' => RunDriver::Queue,
            'browser' => RunDriver::Browser,
            'auto' => self::hasWorker() ? RunDriver::Queue : RunDriver::Browser,
            default => throw new InvalidArgumentException('The setting tolk.runs.execution must be auto, queue or browser, not ['.(is_scalar($execution) ? $execution : get_debug_type($execution)).'].'),
        };
    }

    /**
     * The queue connection of the lane jobs: `runs.queue.connection`, else the app's default.
     */
    public static function connection(): string
    {
        return (string) (config('tolk.runs.queue.connection') ?: config('queue.default'));
    }

    /**
     * The configuration of the lane jobs' connection; for a failover connection, that of the first connection it
     * pushes to.
     */
    public static function connectionConfig(): array
    {
        $config = config('queue.connections.'.self::connection());
        $config = is_array($config) ? $config : [];

        if (($config['driver'] ?? null) === 'failover') {
            $first = config('queue.connections.'.($config['connections'][0] ?? ''));
            $config = is_array($first) ? $first : [];
        }

        return $config;
    }

    /**
     * Sets the run running (RunProcessor::start()). A queue run gets a new generation and its lane jobs; the browser
     * drives its runs itself. `tolk:translate` starts its own runs with RunProcessor::start(), so a CLI run started
     * here, from the CP, has no command driving it any more: it moves to the driver resolveDriver() gives.
     */
    public function start(Run $run): void
    {
        if ($run->driver === RunDriver::Cli) {
            $this->locked($run, function () use ($run) {
                $this->runs->save($run);

                if ($run->driver === RunDriver::Cli) {
                    $run->driver = self::resolveDriver();
                    $this->runs->save($run);
                }
            });
        }

        $this->processor->start($run);

        if ($run->driver !== RunDriver::Queue || $run->status !== RunStatus::Running) {
            return;
        }

        $generation = $this->locked($run, function () use ($run) {
            $this->runs->save($run);
            $run->generation++;
            $this->runs->save($run);

            return $run->generation;
        });

        foreach (range(1, max(1, (int) config('tolk.concurrency.queue'))) as $lane) {
            WorkRun::dispatch($run->id, $lane, $generation);
        }
    }

    /**
     * Moves a queue run to the browser, for a site whose queue has no worker. The new generation ends the lane jobs
     * that a worker may still pick up.
     */
    public function takeOver(Run $run): void
    {
        $this->locked($run, function () use ($run) {
            $this->runs->save($run);

            if ($run->driver === RunDriver::Queue) {
                $run->driver = RunDriver::Browser;
                $run->generation++;
                $this->runs->save($run);
            }
        });
    }

    /**
     * Whether a worker of its own runs the lane jobs' connection, which `auto` needs to choose the queue.
     */
    public static function hasWorker(): bool
    {
        $driver = self::connectionConfig()['driver'] ?? null;

        return is_string($driver) && ! in_array($driver, self::WITHOUT_WORKER, true);
    }

    // A generation is counted up under a lock, so two starts at once cannot both give their lanes the same one.
    private function locked(Run $run, Closure $callback): mixed
    {
        return Cache::store(config('tolk.cache_store'))->lock('tolk:run-generation:'.$run->id, 10)->block(5, $callback);
    }
}
