<?php

namespace Reach\StatamicTolk\Runs;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Enums\UsageSource;
use Reach\StatamicTolk\Events\RunStarted;
use Reach\StatamicTolk\Exceptions\ClaimLost;
use Reach\StatamicTolk\Exceptions\InvalidTarget;
use Reach\StatamicTolk\Exceptions\NotSupportedYet;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\Exceptions\RootMissing;
use Reach\StatamicTolk\Exceptions\RunNotFound;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Exceptions\TransientProviderFailure;
use Reach\StatamicTolk\Exceptions\UnitBusy;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\Support\Sanitizer;
use Reach\StatamicTolk\Targets\EntryTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Translation\Pipeline;
use Reach\StatamicTolk\Translation\Plan;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\PlanStore;
use Reach\StatamicTolk\Translation\TargetResult;
use Throwable;

/**
 * Works on runs (§A12); every driver calls the same methods. translate() claims items, plans them and runs their units
 * until a deadline, and saves nothing to content.
 */
final readonly class RunProcessor
{
    /**
     * Transient provider failures of one item in a row, each a release with a wait, before the next one fails it.
     */
    public const int MAX_WAITS = 10;

    /**
     * Items failed in a row because the provider stayed unavailable before the run is aborted.
     */
    public const int MAX_UNAVAILABLE_ITEMS = 3;

    /**
     * Attempts at an item that throws before it fails.
     */
    public const int MAX_ATTEMPTS = 3;

    /**
     * The first wait after an item threw, doubled with each further attempt.
     */
    public const int RETRY_SECONDS = 5;

    /**
     * The wait after another request turned out to hold the item.
     */
    public const int BUSY_SECONDS = 1;

    private const array FINISHED = [RunStatus::Completed, RunStatus::CompletedWithErrors, RunStatus::Cancelled, RunStatus::Aborted];

    public function __construct(
        private RunRepository $runs,
        private RunPlanner $runPlanner,
        private TargetFactory $factory,
        private Planner $planner,
        private PlanStore $store,
        private Pipeline $pipeline,
        private StateWriter $stateWriter,
        private UsageRecorder $recorder,
    ) {}

    /**
     * Sets a pending or running run running, from now on: `progressedAt` is set, so a run without progress is measured
     * from its start, and the count of items failed in a row for an unavailable provider starts over. Fires RunStarted
     * when the run was pending. A finished run is left as it is.
     */
    public function start(Run $run): void
    {
        $started = $this->locked($run, function () use ($run) {
            $this->runs->save($run);

            if (! in_array($run->status, [RunStatus::Pending, RunStatus::Running], true)) {
                return false;
            }

            $started = $run->status === RunStatus::Pending;
            $run->status = RunStatus::Running;
            $run->progressedAt = CarbonImmutable::now();
            $this->runs->save($run);
            $this->cache()->forget($this->unavailableKey($run));

            return $started;
        });

        if ($started) {
            RunStarted::dispatch($run->id, $run->driver, $run->createdBy, $this->runs->counts($run));
        }
    }

    /**
     * Claims items and works on them until the deadline, starting units only before it, so the call returns at most one
     * provider timeout after the deadline. An item whose plan is finished is `translated`. The loop stops when nothing
     * can be claimed, after a transient provider failure, and when the run stops running.
     */
    public function translate(Run $run, string $workerId, CarbonInterface $deadline, int $claimTtl): WorkReport
    {
        $units = $items = 0;
        $wait = null;
        $options = null;

        // claimNext() refreshes the run and hands out nothing while it is not running, so a cancel or an abort ends the loop.
        while (CarbonImmutable::now()->lessThan($deadline)) {
            $item = $this->runs->claimNext($run, $workerId, $claimTtl);

            if ($item === null) {
                break;
            }

            $options ??= $this->runPlanner->planOptions($run);
            $wait = $this->work($run, $item, $options, $deadline, $claimTtl, $units, $items);

            if ($wait !== null || $run->status !== RunStatus::Running) {
                break;
            }
        }

        return $this->report($run, $units, $items, $wait);
    }

    /**
     * Ends a running run once no item is unfinished: `completed`, or `completed_with_errors` when an item failed. A run
     * that ended gets its finish time.
     */
    public function finalize(Run $run): void
    {
        $this->locked($run, function () use ($run) {
            $this->runs->save($run);
            $counts = $this->runs->counts($run);

            if ($run->status === RunStatus::Running && self::unfinished($counts) === 0) {
                $run->status = $counts[RunItemStatus::Failed->value] > 0 ? RunStatus::CompletedWithErrors : RunStatus::Completed;
            }

            if (in_array($run->status, self::FINISHED, true)) {
                $run->finishedAt ??= CarbonImmutable::now();
            }

            $this->runs->save($run);
        });
    }

    /**
     * Works on one claimed item under its lock, so a retried request never works on it twice at once.
     *
     * @return ?int null to go on with the next item, otherwise the seconds to wait before the next call
     */
    private function work(Run $run, RunItem $item, PlanOptions $options, CarbonInterface $deadline, int $claimTtl, int &$units, int &$items): ?int
    {
        $lock = $this->cache()->lock("tolk:item:{$run->id}:{$item->index}", $claimTtl);

        // An earlier request still works on the item. This claim replaced its own, so it stops at its next write.
        if (! $lock->get()) {
            return self::BUSY_SECONDS;
        }

        try {
            return $this->attempt($run, $item, $options, $deadline, $units, $items);
        } catch (ClaimLost|UnitBusy|RunNotFound) {
            // Another worker has the item now, or the run was deleted.
            return self::BUSY_SECONDS;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return ?int as work()
     */
    private function attempt(Run $run, RunItem $item, PlanOptions $options, CarbonInterface $deadline, int &$units, int &$items): ?int
    {
        try {
            return $this->translateItem($run, $item, $options, $deadline, $units, $items);
        } catch (ClaimLost|UnitBusy|RunNotFound $quiet) {
            throw $quiet;
        } catch (TargetNotFound|InvalidTarget|NotSupportedYet|UnknownSite $exception) {
            return $this->fail($run, $item, $exception->getMessage(), $exception instanceof NotSupportedYet ? TargetResult::NOT_SUPPORTED : null, $items);
        } catch (RootMissing $missing) {
            return $this->fail($run, $item, $missing->getMessage(), TargetResult::ROOT_MISSING, $items);
        } catch (TransientProviderFailure $failure) {
            return $this->waitFor($run, $item, $failure, $items);
        } catch (ProviderRejected $rejected) {
            // Every other item would meet the same refusal. The run is aborted first, so a lost claim cannot prevent it.
            $this->abort($run, Sanitizer::message($rejected->getMessage()));
            $this->runs->release($run, $item);

            return 0;
        } catch (Throwable $exception) {
            report($exception);

            return $this->retryLater($run, $item, $exception, $items);
        }
    }

    /**
     * @return ?int as work()
     */
    private function translateItem(Run $run, RunItem $item, PlanOptions $options, CarbonInterface $deadline, int &$units, int &$items): ?int
    {
        $target = $this->factory->fromDescription(['type' => $item->type->value, 'source_id' => $item->sourceId, 'site' => $item->targetSite]);
        $plan = ($item->planId === null ? null : $this->store->get($item->planId)) ?? $this->plan($run, $item, $target, $options);

        if ($plan === null) {
            $items++;
            $this->finalizeWhenFinished($run);

            return null;
        }

        $record = $this->recorder($run, $item);

        while (($runnable = $this->pipeline->runnable($plan)) !== []) {
            if (! CarbonImmutable::now()->lessThan($deadline)) {
                return 0;
            }

            $this->runs->extendClaim($run, $item);

            if ($run->status !== RunStatus::Running) {
                $this->runs->release($run, $item);

                return 0;
            }

            $this->pipeline->runUnit($plan, array_key_first($runnable), $record);
            $units++;

            // Stores the item's usage, and its waits, which a unit that succeeded resets.
            $item->waits = 0;
            $this->runs->saveItem($run, $item);
        }

        $item->status = RunItemStatus::Translated;
        $this->runs->saveItem($run, $item);
        $this->cache()->forget($this->unavailableKey($run));
        $items++;

        return null;
    }

    /**
     * Plans the item and stores the plan, or marks the item `skipped` when the plan is empty and returns null.
     */
    private function plan(Run $run, RunItem $item, Target $target, PlanOptions $options): ?Plan
    {
        $this->stateWriter->confirm($target);

        if ($options->mode === Mode::Review) {
            $plan = $this->planner->planReview($target, $options);
        } else {
            // Checked before planning, so no tokens are spent on an entry that could not be saved (§A11 "Root page").
            if ($target instanceof EntryTarget) {
                $target->ensureRootExists();
            }

            $plan = $this->planner->plan($target, $options);
        }

        if ($plan->isEmpty()) {
            $item->status = RunItemStatus::Skipped;
            $item->reason = TargetResult::NOTHING_TO_DO;
            $item->fields = $plan->skipped;
            $this->runs->saveItem($run, $item);

            return null;
        }

        $this->store->put($plan);
        $item->planId = $plan->id;
        $this->runs->saveItem($run, $item);

        return $plan;
    }

    /**
     * Records every provider call in the monthly log, on the run and on the item. It runs between a paid call and the
     * storing of its outcome, where anything thrown would make the unit run, and be paid for, again, so it never throws.
     *
     * @return Closure(ModelRef, Usage): void
     */
    private function recorder(Run $run, RunItem $item): Closure
    {
        return function (ModelRef $model, Usage $usage) use ($run, $item): void {
            $this->recorder->record($model, $usage, UsageSource::Run);
            $item->usage = $item->usage->add($model, $usage);

            try {
                $this->runs->addUsage($run, $model, $usage);
            } catch (Throwable $exception) {
                report($exception);
            }
        };
    }

    /**
     * Releases the item until the provider can be called again. After MAX_WAITS waits in a row the next failure fails
     * it, and MAX_UNAVAILABLE_ITEMS items failed that way in a row abort the run.
     */
    private function waitFor(Run $run, RunItem $item, TransientProviderFailure $failure, int &$items): int
    {
        $seconds = max(1, $failure->seconds);

        if ($item->waits >= self::MAX_WAITS) {
            $message = "The AI provider [{$failure->provider}] was still unavailable after ".self::MAX_WAITS.' waits.';
            $this->fail($run, $item, $message, TargetResult::PROVIDER_UNAVAILABLE, $items);

            if ($this->countUnavailable($run) >= self::MAX_UNAVAILABLE_ITEMS) {
                $this->abort($run, $message);
            }

            return $seconds;
        }

        $item->waits++;
        $this->runs->release($run, $item, CarbonImmutable::now()->addSeconds($seconds));

        return $seconds;
    }

    /**
     * Releases an item that threw with a growing delay, and fails it at its MAX_ATTEMPTS-th attempt.
     */
    private function retryLater(Run $run, RunItem $item, Throwable $exception, int &$items): ?int
    {
        $item->attempts++;

        if ($item->attempts >= self::MAX_ATTEMPTS) {
            return $this->fail($run, $item, $exception->getMessage(), null, $items);
        }

        $this->runs->release($run, $item, CarbonImmutable::now()->addSeconds(self::RETRY_SECONDS * 2 ** ($item->attempts - 1)));

        return null;
    }

    private function fail(Run $run, RunItem $item, string $message, ?string $reason, int &$items): null
    {
        $item->status = RunItemStatus::Failed;
        $item->reason = $reason;
        $item->error = Sanitizer::message($message);
        $this->runs->saveItem($run, $item);
        $items++;

        $this->finalizeWhenFinished($run);

        return null;
    }

    private function abort(Run $run, string $message): void
    {
        $this->locked($run, function () use ($run, $message) {
            $this->runs->save($run);

            if ($run->status === RunStatus::Running) {
                $run->status = RunStatus::Aborted;
                $run->error = $message;
                $this->runs->save($run);
            }
        });

        $this->finalize($run);
    }

    private function finalizeWhenFinished(Run $run): void
    {
        if (self::unfinished($this->runs->counts($run)) === 0) {
            $this->finalize($run);
        }
    }

    private function countUnavailable(Run $run): int
    {
        $key = $this->unavailableKey($run);

        $this->cache()->add($key, 0, max(1, (int) config('tolk.plan_ttl', 7200)));

        return (int) $this->cache()->increment($key);
    }

    private function unavailableKey(Run $run): string
    {
        return "tolk:run-unavailable:{$run->id}";
    }

    private function report(Run $run, int $units, int $items, ?int $wait): WorkReport
    {
        $counts = $this->runs->counts($run);
        $remains = $run->status === RunStatus::Running
            && $counts[RunItemStatus::Pending->value] + $counts[RunItemStatus::Translating->value] > 0;

        return new WorkReport($units, $items, $remains, $remains ? ($wait ?? $this->runs->nextAvailableIn($run)) : null);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private static function unfinished(array $counts): int
    {
        return array_sum(array_map(
            fn (RunItemStatus $status) => $status->isFinal() ? 0 : $counts[$status->value] ?? 0,
            RunItemStatus::cases(),
        ));
    }

    // Start, abort and finalize change the run's status under one lock of their own: the repository's is private.
    private function locked(Run $run, Closure $callback): mixed
    {
        return $this->cache()->lock('tolk:run-status:'.$run->id, 10)->block(5, $callback);
    }

    private function cache(): Repository
    {
        return Cache::store(config('tolk.cache_store'));
    }
}
