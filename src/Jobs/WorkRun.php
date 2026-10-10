<?php

namespace Reach\StatamicTolk\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Sleep;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Exceptions\RunNotFound;
use Reach\StatamicTolk\Runs\ApplyReport;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunDispatcher;
use Reach\StatamicTolk\Runs\RunProcessor;
use Reach\StatamicTolk\Runs\WorkReport;
use Throwable;

/**
 * One lane of a queue run (§A12 "Drivers"): translates until a deadline, applies what is ready for a few seconds, and
 * dispatches itself again while the run has work. Every job ends before its connection's `retry_after`, so the queue
 * never hands a lane out twice; a lane that was killed is dispatched again by failed().
 */
final class WorkRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Times a lane is dispatched again after it failed in a row.
     */
    public const int MAX_RESTARTS = 5;

    /**
     * Seconds a lane applies translated items after translating.
     */
    public const int APPLY_SECONDS = 5;

    /**
     * Laravel's `retry_after`, for a connection that sets none.
     */
    public const int RETRY_AFTER = 90;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public readonly string $runId,
        public readonly int $lane,
        public readonly int $generation,
        public readonly int $restarts = 0,
    ) {
        $this->onConnection(RunDispatcher::connection());
        $this->onQueue(config('tolk.runs.queue.name'));
        $this->timeout = self::timeoutSeconds();
    }

    /**
     * The connection's `retry_after`: the seconds after which the queue hands a job that has not ended to a worker again.
     */
    public static function retryAfter(): int
    {
        return (int) (RunDispatcher::connectionConfig()['retry_after'] ?? self::RETRY_AFTER);
    }

    /**
     * Seconds a lane starts new units: it ends at most one provider timeout later, then applies for APPLY_SECONDS, and
     * leaves a margin before the job's timeout.
     */
    public static function deadlineSeconds(): int
    {
        return max(5, min(30, self::retryAfter() - (int) config('tolk.http.timeout.web') - 20));
    }

    public static function timeoutSeconds(): int
    {
        return max(1, self::retryAfter() - 10);
    }

    public function handle(RunRepository $runs, RunProcessor $processor): void
    {
        $run = $runs->find($this->runId);

        if (! $this->isCurrent($run)) {
            return;
        }

        $worker = "queue-{$this->lane}";

        try {
            $work = $processor->translate($run, $worker, CarbonImmutable::now()->addSeconds(self::deadlineSeconds()), max(1, (int) config('tolk.runs.claim_ttl')));
            $applied = $processor->applyReady($run, $worker, CarbonImmutable::now()->addSeconds(self::APPLY_SECONDS));
        } catch (RunNotFound) {
            // The run was deleted.
            return;
        }

        // Both calls finalize a running run once no item is unfinished, so a run that still runs has work left, also
        // when the apply sent items back to be translated again.
        if ($this->isCurrent($runs->find($this->runId))) {
            $this->next(self::waitSeconds($work, $applied));
        }
    }

    /**
     * A lane that failed, or that its worker killed at the timeout, is dispatched again, so the run goes on.
     */
    public function failed(?Throwable $exception = null): void
    {
        if ($this->restarts < self::MAX_RESTARTS && $this->isCurrent(app(RunRepository::class)->find($this->runId))) {
            dispatch(new self($this->runId, $this->lane, $this->generation, $this->restarts + 1));
        }
    }

    private function isCurrent(?Run $run): bool
    {
        return $run !== null && $run->status === RunStatus::Running && $run->generation === $this->generation;
    }

    private function next(int $delay): void
    {
        $job = new self($this->runId, $this->lane, $this->generation);

        // A queue that runs jobs at once ignores delays: the lane waits here instead of calling again at once.
        if ($this->job instanceof SyncJob && $delay > 0) {
            Sleep::for($delay)->seconds();
            $delay = 0;
        }

        dispatch($delay > 0 ? $job->delay($delay) : $job);
    }

    /**
     * Seconds until the lane can do something again: at once when a deadline ended its work or an apply sent items back,
     * otherwise the earliest wait it was given, and at least 1 second when nothing could be claimed.
     */
    public static function waitSeconds(WorkReport $work, ApplyReport $applied): int
    {
        $waits = array_filter([
            $work->remains ? $work->wait : null,
            $applied->remains ? $applied->wait : null,
            $applied->returned > 0 ? 0 : null,
        ], fn (?int $wait) => $wait !== null);

        return $waits === [] ? 1 : max(0, min($waits));
    }
}
