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
use Reach\StatamicTolk\Runs\AutoUpdater;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunDispatcher;
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
 * entry is still moving waits for that run, so the same fields are not translated twice. It never throws: a failure is
 * reported, and the localizations stay outdated, as their banner shows.
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
     * Seconds the unique lock of a job still on the queue is held at most.
     */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $entryId,
        public readonly ?string $userId = null,
    ) {
        $this->onConnection(RunDispatcher::connection());
        $this->onQueue(config('tolk.runs.queue.name'));
    }

    /**
     * Dispatches the update of the entry as $user: on the queue after DELAY_SECONDS, or after the response.
     */
    public static function dispatchFor(EntryContract $entry, ?UserContract $user): void
    {
        $job = new self((string) $entry->id(), $user === null ? null : (string) $user->id());

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
        $deadline = CarbonImmutable::now()->addMinutes(self::WAIT_MINUTES);

        if ($inProcess) {
            self::keepRunning();
        }

        while (true) {
            $entry = Entry::find($this->entryId);

            if ($entry === null || ! $updater->applies($entry, $user)) {
                return;
            }

            $run = $this->attempt($updater, $entry, $user, $inProcess);

            if ($run !== false) {
                break;
            }

            if (! $inProcess) {
                $this->release(self::RETRY_SECONDS);

                return;
            }

            if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                return;
            }

            Sleep::for(self::POLL_SECONDS)->seconds();
        }

        if ($run !== null && $inProcess) {
            $updater->drive($run);
        }
    }

    /**
     * Starts the entry's run unless another job is starting one or an earlier run from the entry is still moving.
     *
     * @return Run|false|null the run, null when there is nothing to translate, false when it has to wait
     */
    private function attempt(AutoUpdater $updater, EntryContract $entry, ?UserContract $user, bool $drive): Run|false|null
    {
        $lock = Cache::store(config('tolk.cache_store'))->lock('tolk:auto-update:'.$this->entryId, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return false;
        }

        try {
            return $updater->busy($entry) ? false : $updater->start($entry, $user, $drive);
        } finally {
            $lock->release();
        }
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
