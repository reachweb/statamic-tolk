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
use Reach\StatamicTolk\Runs\AutoUpdater;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunDispatcher;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Throwable;

/**
 * Updates the translations of an entry that was saved published in its origin (T11.1), as the user who saved it.
 * With a queue worker it waits a few seconds on the queue, so quick saves of the entry make one job, and only starts the
 * run; without one it runs after the save's response and drives the run itself. A save while an earlier run from the
 * entry is still moving waits for that run, so the same fields are not translated twice. Without a worker only one
 * process per entry and user waits: a later save's job hands its update to that process and ends at once, so a few quick
 * saves do not hold as many PHP processes. It never throws: a failure is reported, and the localizations stay outdated,
 * as their banner shows.
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
     * @param  list<string>  $except  sites left to the run that saved the entry (AutoUpdater::leftToRun())
     */
    public function __construct(
        public readonly string $entryId,
        public readonly ?string $userId = null,
        public readonly array $except = [],
    ) {
        $this->onConnection(RunDispatcher::connection());
        $this->onQueue(config('tolk.runs.queue.name'));
    }

    /**
     * Dispatches the update of the entry as $user, but not into $except: on the queue after DELAY_SECONDS, or after the
     * response.
     *
     * @param  list<string>  $except
     */
    public static function dispatchFor(EntryContract $entry, ?UserContract $user, array $except = []): void
    {
        $job = new self((string) $entry->id(), $user === null ? null : (string) $user->id(), $except);

        if (AutoUpdater::queued()) {
            dispatch($job->delay(self::DELAY_SECONDS));
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
        $waiter = ['token' => Str::random(20), 'except' => $this->except, 'until' => CarbonImmutable::now()->addMinutes(self::WAIT_MINUTES)->getTimestamp()];

        if ($inProcess) {
            self::keepRunning();
        }

        while (true) {
            $run = $this->attempt($updater, $user, $inProcess, $waiter);

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

        if ($run !== null && $inProcess) {
            $updater->drive($run);
        }
    }

    /**
     * Under the entry's lock, starts the entry's run, unless the job has to wait for another job starting one or for an
     * earlier run from the entry that is still moving. In the saving process only one job per entry and user waits,
     * marked in the cache: a later job leaves its update to it and ends, and the one that waits then also translates
     * the sites the later job's save left to no run (the intersection of their $except) and waits as long as it would.
     *
     * @param  array{token: string, except: list<string>, until: int}  $waiter  this job's mark: the sites it leaves to a
     *                                                                          run, and when it stops waiting
     * @return Run|false|null the run, null when there is nothing to translate or another job waits to translate it, false
     *                        when it has to wait
     */
    private function attempt(AutoUpdater $updater, ?UserContract $user, bool $inProcess, array &$waiter): Run|false|null
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

            if ($entry === null || ! $updater->applies($entry, $user, $waiter['except'])) {
                return $this->stopWaiting($key, $mine);
            }

            // The job that waits plans after the earlier run from what is saved then, this save included.
            if ($waiting !== null && ! $mine) {
                $cache->put($key, [
                    'token' => $waiting['token'],
                    'except' => array_values(array_intersect($waiting['except'], $waiter['except'])),
                    'until' => max($waiting['until'], $waiter['until']),
                ], self::WAITER_SECONDS);

                return null;
            }

            if ($updater->busy($entry)) {
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

            return $updater->start($entry, $user, $inProcess, $waiter['except']);
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
