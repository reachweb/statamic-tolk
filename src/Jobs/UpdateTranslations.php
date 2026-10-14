<?php

namespace Reach\StatamicTolk\Jobs;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Reach\StatamicTolk\Listeners\TrackQueueJobs;
use Reach\StatamicTolk\Runs\AutoUpdater;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunDispatcher;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Support\WebServer;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Throwable;

/**
 * Updates the translations of an entry that was saved published in its origin (T11.1), as the user who saved it.
 * With a queue worker it waits a few seconds on the queue, so quick saves of the entry make one job, and only starts the
 * run; without one it runs after the save's response and drives the run itself. A save inside a queue job whose
 * connection has a worker has no response to wait for, since the worker's process ends only when it stops: the job then
 * waits on that job's queue and drives the run in its worker, without the worker's time limit. A save while an earlier
 * run from the entry is still moving waits for that run, and a save a run makes waits for that run to translate the
 * sites it is still to translate from the entry, so the same fields are not translated twice. After a response only one
 * process per entry and user waits: a later save's job hands its update to that process and ends at once, so a few
 * quick saves do not hold as many PHP processes. It never throws: a failure is reported, and the localizations stay
 * outdated, as their banner shows.
 */
final class UpdateTranslations implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Seconds a job waits on the queue before it starts.
     */
    public const int DELAY_SECONDS = 10;

    /**
     * Seconds after which a job on the queue looks again whether an earlier run from the entry has finished.
     */
    public const int RETRY_SECONDS = 15;

    /**
     * Seconds a job looks again whether an earlier run has finished, while it runs after a response.
     */
    public const int POLL_SECONDS = 2;

    /**
     * Minutes a job waits for an earlier run from the entry before it gives up.
     */
    public const int WAIT_MINUTES = 15;

    /**
     * Seconds the lock that keeps two jobs of the entry from starting a run at once is held at most.
     */
    public const int LOCK_SECONDS = 60;

    /**
     * Seconds the mark of the process that waits for an earlier run from the entry lasts after it last looked, so the
     * saves after a process that died while waiting are updated again once it has expired.
     */
    public const int WAITER_SECONDS = 60;

    /**
     * Seconds the unique lock of a job still on the queue is held at most.
     */
    public int $uniqueFor = 600;

    /**
     * Seconds a worker lets the job run, null for the worker's own limit; 0, no limit, for a job that drives its run.
     */
    public ?int $timeout = null;

    /**
     * @param  list<array{run: string, item: int}>  $after  the run that saved the entry and the item it saved it with, when
     *                                                      it is still to translate sites from it (AutoUpdater::savingRun())
     */
    public function __construct(
        public readonly string $entryId,
        public readonly ?string $userId = null,
        public readonly array $after = [],
    ) {
        $this->onConnection(RunDispatcher::connection());
        $this->onQueue(config('tolk.runs.queue.name'));
    }

    /**
     * Dispatches the update of the entry as $user, once the runs in $after are done with it: after DELAY_SECONDS on the
     * queue of Tolk's runs or, without a worker there, on the queue of the job that saved the entry in a worker; otherwise
     * after the response.
     *
     * @param  list<array{run: string, item: int}>  $after
     */
    public static function dispatchFor(EntryContract $entry, ?UserContract $user, array $after = []): void
    {
        $job = new self((string) $entry->id(), $user === null ? null : (string) $user->id(), $after);

        if (AutoUpdater::queued()) {
            dispatch($job->delay(self::DELAY_SECONDS));
        } elseif (($worker = app(TrackQueueJobs::class)->worker()) !== null) {
            $job->timeout = 0;
            dispatch($job->onConnection($worker['connection'])->onQueue($worker['queue'])->delay(self::DELAY_SECONDS));
        } else {
            dispatch($job)->afterResponse();
        }
    }

    public function uniqueId(): string
    {
        return $this->entryId;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store(config('tolk.cache_store'));
    }

    /**
     * A job on the queue is released while an earlier run from the entry is still moving, up to WAIT_MINUTES.
     */
    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addMinutes(self::WAIT_MINUTES);
    }

    public function handle(AutoUpdater $updater, Settings $settings): void
    {
        $settings->fresh();

        try {
            $this->update($updater);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function update(AutoUpdater $updater): void
    {
        $user = $this->userId === null ? null : User::find($this->userId);

        // A user who was deleted meanwhile has no rights left to update with.
        if ($this->userId !== null && $user === null) {
            return;
        }

        $inProcess = $this->job === null || $this->job instanceof SyncJob;
        // Lanes on the queue of Tolk's runs would have no worker: the job drives the run, also in a worker of the site.
        $drive = $inProcess || ! AutoUpdater::queued();
        // A browser saves a run's next items only once the response of this request has arrived, which this web server
        // sends only when the job ends: waiting here for the run that saved the entry would hold that run.
        $holdsResponse = $inProcess && app(WebServer::class)->endsResponsesEarly() === false;
        $waiter = ['token' => Str::random(20), 'after' => $this->after, 'until' => CarbonImmutable::now()->addMinutes(self::WAIT_MINUTES)->getTimestamp()];

        if ($inProcess) {
            self::keepRunning();
        }

        while (true) {
            $run = $this->attempt($updater, $user, $inProcess, $drive, $holdsResponse, $waiter);

            if ($run !== false) {
                break;
            }

            if (! $inProcess) {
                $this->release(self::RETRY_SECONDS);

                return;
            }

            if (CarbonImmutable::now()->getTimestamp() >= $waiter['until']) {
                return;
            }

            Sleep::for(self::POLL_SECONDS)->seconds();
            // Plans from what other processes saved meanwhile, the earlier run's localizations among them.
            ContentWriter::refreshStache();
        }

        if ($run !== null && $drive) {
            $updater->drive($run);
        }
    }

    /**
     * Under the entry's lock, starts the entry's run, unless the job has to wait for another job starting one, for an
     * earlier run from the entry that is still moving, or for the sites a run that saved the entry is still to translate
     * from it. In the saving process only one job per entry and user waits, marked in the cache with the runs it waits
     * for and when it stops waiting: a later job leaves its update to it and ends, and the one that waits then also waits
     * for the runs the later job waits for (the union of their $after) and as long as it would. A job that would hold
     * the response its run waits for leaves that run's sites to it instead.
     *
     * @param  bool  $drive  whether this job drives the run it starts
     * @param  bool  $holdsResponse  whether waiting holds the response of the request that saved the entry
     * @param  array{token: string, after: list<array{run: string, item: int}>, until: int}  $waiter  this job's mark
     * @return Run|false|null the run, null when there is nothing to translate or another job waits to translate it, false
     *                        when it has to wait
     */
    private function attempt(AutoUpdater $updater, ?UserContract $user, bool $inProcess, bool $drive, bool $holdsResponse, array &$waiter): Run|false|null
    {
        $cache = Cache::store(config('tolk.cache_store'));
        $lock = $cache->lock('tolk:auto-update:'.$this->entryId, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return false;
        }

        try {
            $key = 'tolk:auto-update:waiting:'.$this->entryId.':'.$this->userId;
            $waiting = $inProcess ? $cache->get($key) : null;
            $waiting = is_array($waiting) ? $waiting : null;
            $mine = $waiting !== null && $waiting['token'] === $waiter['token'];

            if ($mine) {
                $waiter = $waiting;
            }

            $entry = Entry::find($this->entryId);

            if ($entry === null || ! $updater->applies($entry, $user)) {
                return $this->stopWaiting($key, $mine);
            }

            // The job that waits plans after the earlier run from what is saved then, this save included.
            if ($waiting !== null && ! $mine) {
                $cache->put($key, [
                    'token' => $waiting['token'],
                    'after' => array_values(array_unique([...$waiting['after'], ...$waiter['after']], SORT_REGULAR)),
                    'until' => max($waiting['until'], $waiter['until']),
                ], self::WAITER_SECONDS);

                return null;
            }

            $left = $updater->left($entry, $waiter['after']);

            if ($updater->busy($entry) || ($left !== [] && ! $holdsResponse)) {
                if (! $inProcess) {
                    return false;
                }

                if (CarbonImmutable::now()->getTimestamp() >= $waiter['until']) {
                    return $this->stopWaiting($key, $mine);
                }

                $cache->put($key, $waiter, self::WAITER_SECONDS);

                return false;
            }

            $this->stopWaiting($key, $mine);

            return $updater->start($entry, $user, $drive, $left);
        } finally {
            $lock->release();
        }
    }

    /**
     * Removes the mark of the job that waits for the entry, when it is this job's.
     */
    private function stopWaiting(string $key, bool $mine): null
    {
        if ($mine) {
            Cache::store(config('tolk.cache_store'))->forget($key);
        }

        return null;
    }

    /**
     * The process has sent its response: the request's time limit and a closed connection no longer apply.
     */
    private static function keepRunning(): void
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        ignore_user_abort(true);
    }
}
