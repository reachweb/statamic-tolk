<?php

namespace Reach\StatamicTolk\Runs;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Enums\TargetResultStatus;
use Reach\StatamicTolk\Enums\UsageSource;
use Reach\StatamicTolk\Events\RunFinished;
use Reach\StatamicTolk\Events\RunStarted;
use Reach\StatamicTolk\Events\TargetTranslated;
use Reach\StatamicTolk\Exceptions\ApplyFailed;
use Reach\StatamicTolk\Exceptions\ClaimLost;
use Reach\StatamicTolk\Exceptions\InvalidTarget;
use Reach\StatamicTolk\Exceptions\NotSupportedYet;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\Exceptions\RootMissing;
use Reach\StatamicTolk\Exceptions\RunNotFound;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Exceptions\TransientProviderFailure;
use Reach\StatamicTolk\Exceptions\UnitBusy;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\Exceptions\WriterBusy;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\GitListeners;
use Reach\StatamicTolk\Support\Sanitizer;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\EntryTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Translation\Pipeline;
use Reach\StatamicTolk\Translation\Plan;
use Reach\StatamicTolk\Translation\PlanApplier;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\PlanStore;
use Reach\StatamicTolk\Translation\TargetResult;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;
use Throwable;

/**
 * Works on runs (§A12); every driver calls the same methods. translate() claims items, plans them and runs their units
 * until a deadline, and saves nothing to content. applyReady() saves translated items, one at a time under the global
 * write lock (§A11).
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
    public const int MAX_ATTEMPTS = RunItem::MAX_ATTEMPTS;

    /**
     * The first wait after an item threw, doubled with each further attempt.
     */
    public const int RETRY_SECONDS = 5;

    /**
     * The wait after another request turned out to hold the item.
     */
    public const int BUSY_SECONDS = 1;

    private const array TO_APPLY = [RunItemStatus::Translated, RunItemStatus::Applying];

    public function __construct(
        private RunRepository $runs,
        private RunPlanner $runPlanner,
        private TargetFactory $factory,
        private Planner $planner,
        private PlanStore $store,
        private Pipeline $pipeline,
        private StateWriter $stateWriter,
        private UsageRecorder $recorder,
        private PlanApplier $applier,
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

            if ($run->status->isFinal()) {
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
     * Cancels a pending or running run and finalizes it, which commits the content saved so far. Workers stop at their
     * next step; an item they release mid-plan keeps its plan for a resume.
     *
     * @return bool whether the run was cancelled now
     */
    public function cancel(Run $run): bool
    {
        $cancelled = $this->locked($run, function () use ($run) {
            $this->runs->save($run);

            if ($run->status->isFinal()) {
                return false;
            }

            $run->status = RunStatus::Cancelled;
            $this->runs->save($run);

            return true;
        });

        if ($cancelled) {
            $this->finalize($run);
        }

        return $cancelled;
    }

    /**
     * Sets a cancelled or aborted run running again, so its unfinished items are worked on; RunDispatcher::start()
     * starts it. A running run is left as it is.
     *
     * @return bool whether the run is running now
     */
    public function resume(Run $run): bool
    {
        return $this->locked($run, function () use ($run) {
            $this->runs->save($run);

            if (in_array($run->status, [RunStatus::Cancelled, RunStatus::Aborted], true)) {
                $this->reopen($run);
            }

            return $run->status === RunStatus::Running;
        });
    }

    /**
     * Puts the failed items, and those skipped because a dependency failed, back to `pending`, and sets a run that ended
     * running again when it put any back; RunDispatcher::start() starts it. A pending run is left alone.
     *
     * @return int the items put back
     */
    public function retry(Run $run): int
    {
        return $this->locked($run, function () use ($run) {
            $this->runs->save($run);

            if ($run->status === RunStatus::Pending) {
                return 0;
            }

            $retried = $this->runs->retryFailed($run);

            if ($retried > 0 && $run->status !== RunStatus::Running) {
                $this->reopen($run);
            }

            return $retried;
        });
    }

    /**
     * Stops a running run that made no progress for $seconds, the sweep of tolk:prune: it is aborted with $message, so
     * its page offers Resume, or completed when no item is unfinished, and finalized, which commits what it saved. A run
     * that progressed meanwhile is left alone.
     *
     * @return bool whether the run was stopped
     */
    public function stopStalled(Run $run, int $seconds, string $message): bool
    {
        $stopped = $this->locked($run, function () use ($run, $seconds, $message) {
            $this->runs->save($run);

            if ($run->status !== RunStatus::Running || ! $run->lastActivityAt()->lessThan(CarbonImmutable::now()->subSeconds($seconds))) {
                return false;
            }

            if (self::unfinished($this->runs->counts($run)) > 0) {
                $run->status = RunStatus::Aborted;
                $run->error = $message;
                $this->runs->save($run);
            }

            return true;
        });

        if ($stopped) {
            $this->finalize($run);
        }

        return $stopped;
    }

    /**
     * Claims items and works on them until the deadline, starting units only before it, so the call returns at most one
     * provider timeout after the deadline. An item whose plan is finished is `translated`. The loop stops when nothing
     * can be claimed, after a transient provider failure, when the run stops running, and once $maxItems items finished
     * translating, so a caller that also applies can save each item before it translates the next.
     */
    public function translate(Run $run, string $workerId, CarbonInterface $deadline, int $claimTtl, ?int $maxItems = null): WorkReport
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

            if ($wait !== null || $run->status !== RunStatus::Running || ($maxItems !== null && $items >= $maxItems)) {
                break;
            }
        }

        return $this->report($run, $units, $items, $wait);
    }

    /**
     * Saves `translated` items until the deadline, one at a time and each inside ContentWriter::write(), so the global
     * write lock is held before anything reads content. An item whose plan expired, or whose source changed since it was
     * planned, goes back to `pending` to be planned and translated again; a second change of its source fails it. The
     * run is finalized when its last item finishes, and when it stopped running while this call saved content.
     */
    public function applyReady(Run $run, string $workerId, CarbonInterface $deadline): ApplyReport
    {
        $items = $returned = 0;
        $wait = null;
        $ttl = max(1, (int) config('tolk.write.lock_seconds'));
        $counts = $this->runs->counts($run);

        // Read from the counts first, so a call with nothing to apply does not take the global write lock.
        while (CarbonImmutable::now()->lessThan($deadline) && self::count($counts, ...self::TO_APPLY) > 0) {
            try {
                $step = ContentWriter::write(fn () => $this->applyNext($run, $workerId, $ttl), $this->secondsUntil($deadline));
            } catch (WriterBusy) {
                // Another process is saving content.
                $wait = self::BUSY_SECONDS;
                break;
            }

            if ($step === null) {
                break;
            }

            ['item' => $item, 'result' => $result] = $step;

            if ($result !== null) {
                TargetTranslated::dispatch($result->ref, $result->appliedFields(), array_keys($item->usage->usages), $item->usage);
            }

            $items += (int) ($item?->status->isFinal() ?? false);
            $returned += (int) ($item?->status === RunItemStatus::Pending);

            // A cancel or an abort came while this call saved content, which the finalize of the cancel did not commit.
            if ($run->status !== RunStatus::Running) {
                $this->finalize($run);
                break;
            }

            $counts = $this->finalizeWhenFinished($run);

            // Another request holds the item.
            if ($item === null) {
                $wait = self::BUSY_SECONDS;
                break;
            }
        }

        if ($run->status === RunStatus::Running) {
            $counts = $this->finalizeWhenFinished($run);
        }

        $remains = $run->status === RunStatus::Running && self::count($counts, ...self::TO_APPLY) > 0;
        $wait = $wait ?? (CarbonImmutable::now()->lessThan($deadline) ? self::BUSY_SECONDS : 0);

        return new ApplyReport($items, $returned, $remains, $remains ? $wait : null);
    }

    /**
     * Ends a running run once no item is unfinished: `completed`, or `completed_with_errors` when an item failed. A run
     * that ended gets its finish time and fires RunFinished, once per end. Content saved since the last call is
     * committed once, whether the run ended or not, so content saved by an interrupted run is never left uncommitted.
     */
    public function finalize(Run $run): void
    {
        $finalized = $this->locked($run, function () use ($run) {
            $this->runs->save($run);
            $counts = $this->runs->counts($run);

            if ($run->status === RunStatus::Running && self::unfinished($counts) === 0) {
                $run->status = $counts[RunItemStatus::Failed->value] > 0 ? RunStatus::CompletedWithErrors : RunStatus::Completed;
            }

            $ended = $run->status->isFinal();
            $fires = $ended && $run->finalizedAt === null;
            $commits = $run->saves > $run->committedSaves;

            if ($ended) {
                $run->finishedAt ??= CarbonImmutable::now();
            }

            if ($fires) {
                $run->finalizedAt = CarbonImmutable::now();
            }

            $run->committedSaves = $run->saves;
            $this->runs->save($run);

            return ['commits' => $commits, 'event' => $fires ? new RunFinished($run->id, $run->status, $counts, $run->usage) : null];
        });

        if ($finalized['commits']) {
            GitListeners::commit(ContentWriter::COMMIT_MESSAGE);
        }

        if ($finalized['event'] !== null) {
            event($finalized['event']);
        }
    }

    /**
     * Works on one claimed item under its lock, so a retried request never works on it twice at once.
     *
     * @return ?int null to go on with the next item, otherwise the seconds to wait before the next call
     */
    private function work(Run $run, RunItem $item, PlanOptions $options, CarbonInterface $deadline, int $claimTtl, int &$units, int &$items): ?int
    {
        $lock = $this->itemLock($run, $item, $claimTtl);

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
        } catch (TargetNotFound|InvalidTarget|NotSupportedYet|UnknownSite|RootMissing $exception) {
            return $this->fail($run, $item, $exception->getMessage(), self::reason($exception), $items);
        } catch (TransientProviderFailure $failure) {
            return $this->waitFor($run, $item, $failure, $items);
        } catch (ProviderRejected $rejected) {
            // Every other item would meet the same refusal. The run is aborted first, so a lost claim cannot prevent it.
            $this->abort($run, Sanitizer::message($rejected->getMessage()));
            $this->runs->release($run, $item);

            return 0;
        } catch (Throwable $exception) {
            report($exception);

            return $this->releaseForRetry($run, $item) ? null : $this->fail($run, $item, $exception->getMessage(), null, $items);
        }
    }

    /**
     * @return ?int as work()
     */
    private function translateItem(Run $run, RunItem $item, PlanOptions $options, CarbonInterface $deadline, int &$units, int &$items): ?int
    {
        $plan = $item->planId === null ? null : $this->store->get($item->planId);

        // A worker lives on while another process applies, and planning reads what the run saved before: the root page an
        // entry is placed under, the localizations a navigation links to (§A12). Its Stache memory would not show them.
        if ($plan === null) {
            ContentWriter::refreshStache();
        }

        $target = $this->factory->fromDescription($item->describe());
        $plan ??= $this->plan($run, $item, $target, $options);

        if ($plan === null) {
            $items++;
            $this->finalizeWhenFinished($run);

            return null;
        }

        $record = $this->recorder($run, $item);
        $runnable = $this->pipeline->runnable($plan);

        while ($runnable !== []) {
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
            $item->waits = 0;
            $runnable = $this->pipeline->runnable($plan);

            // Stores the item's usage, and its waits, which a unit that succeeded resets; after the last unit, the
            // status is saved with them.
            if ($runnable !== []) {
                $this->runs->saveItem($run, $item);
            }
        }

        $item->status = RunItemStatus::Translated;
        $this->runs->saveItem($run, $item);
        $this->cache()->forget($this->unavailableKey($run));
        $items++;

        return null;
    }

    /**
     * Claims the next translated item and applies it under its lock. Runs inside ContentWriter::write().
     *
     * @return ?array{item: ?RunItem, result: ?TargetResult} null when no item could be claimed; `item` is null when
     *                                                       another request has it now, and `result` is set when
     *                                                       translations were saved
     */
    private function applyNext(Run $run, string $workerId, int $ttl): ?array
    {
        $item = $this->runs->claimTranslated($run, $workerId, $ttl);

        if ($item === null) {
            return null;
        }

        $lock = $this->itemLock($run, $item, $ttl);

        if (! $lock->get()) {
            return ['item' => null, 'result' => null];
        }

        try {
            return ['item' => $item, 'result' => $this->applyItem($run, $item)];
        } catch (ClaimLost|RunNotFound) {
            return ['item' => null, 'result' => null];
        } finally {
            $lock->release();
        }
    }

    /**
     * @return ?TargetResult the result when translations were saved
     */
    private function applyItem(Run $run, RunItem $item): ?TargetResult
    {
        $plan = null;

        try {
            $target = $this->factory->fromDescription($item->describe());
            $plan = $item->planId === null ? null : $this->store->get($item->planId);
            $user = $run->createdBy === null ? null : User::find($run->createdBy['id']);

            // The creator's access is checked again, because it may have changed since the run was planned (§A14).
            if ($run->createdBy !== null && ($user === null || ! $target->authorize($user))) {
                $this->forget($plan);

                return $this->settle($run, $item, RunItemStatus::Skipped, RunItem::UNAUTHORIZED);
            }

            if ($plan === null) {
                return $this->settle($run, $item, RunItemStatus::Pending, null);
            }

            $result = $this->applyPlan($run, $plan, $target, $user);
        } catch (ClaimLost|RunNotFound $quiet) {
            throw $quiet;
        } catch (SourceChanged $changed) {
            $this->forget($plan);

            // The first change sends the item back to be planned again; a second one fails it, as TargetTranslator does.
            return $item->reason === TargetResult::SOURCE_CHANGED
                ? $this->failItem($run, $item, $changed->getMessage(), TargetResult::SOURCE_CHANGED)
                : $this->settle($run, $item, RunItemStatus::Pending, TargetResult::SOURCE_CHANGED);
        } catch (TargetNotFound|InvalidTarget|NotSupportedYet|UnknownSite|RootMissing|ApplyFailed $exception) {
            $this->forget($plan);

            return $this->failItem($run, $item, $exception->getMessage(), self::reason($exception));
        } catch (Throwable $exception) {
            report($exception);

            if (! $this->releaseForRetry($run, $item)) {
                $this->forget($plan);
                $this->failItem($run, $item, $exception->getMessage(), null);
            }

            return null;
        }

        $this->forget($plan);
        $this->applied($run, $item, $result, $target);

        return $result->status === TargetResultStatus::Done ? $result : null;
    }

    private function applyPlan(Run $run, Plan $plan, Target $target, ?UserContract $user): TargetResult
    {
        $options = new ApplyOptions(
            user: $user,
            newStatus: (string) ($run->options['new_status'] ?? 'draft'),
            publishChanges: (bool) ($run->options['publish_changes'] ?? false),
            overwriteManual: (bool) ($run->options['overwrite_manual'] ?? false),
        );

        try {
            $result = $this->applier->apply($plan, $target, $options);
        } catch (Throwable $exception) {
            // Anything but a changed source may come after a save had begun, so finalize() commits it.
            if (! $exception instanceof SourceChanged) {
                $this->countSave($run);
            }

            throw $exception;
        }

        if ($result->status === TargetResultStatus::Done || $result->created) {
            $this->countSave($run);
        }

        return $result;
    }

    /**
     * Records the applied target on the item: `done` when translations were saved or the reviewer accepted every field,
     * `skipped` when every field was edited by someone meanwhile, `failed` when no field could be translated completely.
     */
    private function applied(Run $run, RunItem $item, TargetResult $result, Target $target): void
    {
        [$status, $reason] = match (true) {
            $result->status === TargetResultStatus::Done => [RunItemStatus::Done, null],
            $result->reason === TargetResult::NO_CORRECTIONS => [RunItemStatus::Done, TargetResult::NO_CORRECTIONS],
            $result->status === TargetResultStatus::Skipped => [RunItemStatus::Skipped, $result->reason],
            default => [RunItemStatus::Failed, $result->reason],
        };

        $item->fields = $result->fields;
        $item->created = $result->created;
        $item->corrections = $result->corrections;
        // The planner's own warnings, such as a parent page without a localization, stay.
        $item->warnings = array_values(array_unique([...$item->warnings, ...$result->warnings]));
        $item->error = $result->error === null ? null : Sanitizer::message($result->error);
        $item->reference = $result->ref === null ? $item->reference : (string) $result->ref;
        $item->editUrl = $target->fresh()->editUrl() ?? $item->editUrl;

        $this->settle($run, $item, $status, $reason);
    }

    /**
     * Gives the item its status after applying, or sends it back to `pending`; either way its plan is done with.
     */
    private function settle(Run $run, RunItem $item, RunItemStatus $status, ?string $reason): null
    {
        $item->status = $status;
        $item->reason = $reason;
        $item->planId = null;
        $this->runs->saveItem($run, $item);

        return null;
    }

    private function failItem(Run $run, RunItem $item, string $message, ?string $reason): null
    {
        $item->error = Sanitizer::message($message);

        return $this->settle($run, $item, RunItemStatus::Failed, $reason);
    }

    /**
     * Counts a save for finalize(), which commits once for everything saved since its last call. Only one process
     * applies at a time, so the count read before the increment is current.
     */
    private function countSave(Run $run): void
    {
        $this->runs->save($run);
        $run->saves++;
        $this->runs->save($run);
    }

    private function forget(?Plan $plan): void
    {
        if ($plan !== null) {
            $this->store->forget($plan);
        }
    }

    private function secondsUntil(CarbonInterface $deadline): int
    {
        return max(0, (int) CarbonImmutable::now()->diffInSeconds($deadline, false));
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
     * Counts an attempt at an item that threw and releases it after a growing delay: `pending` again, or `translated`
     * when its apply threw. False at its MAX_ATTEMPTS-th attempt, when the caller fails it instead.
     */
    private function releaseForRetry(Run $run, RunItem $item): bool
    {
        if (++$item->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $this->runs->release($run, $item, CarbonImmutable::now()->addSeconds(self::RETRY_SECONDS * 2 ** ($item->attempts - 1)));

        return true;
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

    /**
     * Runs an ended run again. Its end is cleared, so finalize() fires RunFinished at the next one.
     */
    private function reopen(Run $run): void
    {
        $run->status = RunStatus::Running;
        $run->error = null;
        $run->finishedAt = null;
        $run->finalizedAt = null;
        $this->runs->save($run);
    }

    /**
     * Finalizes the run once no item is unfinished, also when the repository finished the last one itself (an expired
     * claim's last attempt, a dependency that failed).
     *
     * @return array<string, int> the counts it decided on
     */
    private function finalizeWhenFinished(Run $run): array
    {
        $counts = $this->runs->counts($run);

        if (self::unfinished($counts) === 0) {
            $this->finalize($run);
        }

        return $counts;
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
        $counts = $run->status === RunStatus::Running ? $this->finalizeWhenFinished($run) : [];
        $remains = $run->status === RunStatus::Running && self::count($counts, RunItemStatus::Pending, RunItemStatus::Translating) > 0;

        return new WorkReport($units, $items, $remains, $remains ? ($wait ?? $this->runs->nextAvailableIn($run)) : null);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private static function count(array $counts, RunItemStatus ...$statuses): int
    {
        return array_sum(array_map(fn (RunItemStatus $status) => $counts[$status->value] ?? 0, $statuses));
    }

    /**
     * @param  array<string, int>  $counts
     */
    private static function unfinished(array $counts): int
    {
        return self::count($counts, ...array_filter(RunItemStatus::cases(), fn (RunItemStatus $status) => ! $status->isFinal()));
    }

    private static function reason(Throwable $exception): ?string
    {
        return match (true) {
            $exception instanceof NotSupportedYet => TargetResult::NOT_SUPPORTED,
            $exception instanceof RootMissing => TargetResult::ROOT_MISSING,
            $exception instanceof ApplyFailed => TargetResult::APPLY_FAILED,
            default => null,
        };
    }

    // Start, abort and finalize change the run's status under one lock of their own: the repository's is private.
    private function locked(Run $run, Closure $callback): mixed
    {
        return $this->cache()->lock('tolk:run-status:'.$run->id, 10)->betweenBlockedAttemptsSleepFor(10)->block(5, $callback);
    }

    /**
     * Held for a whole step on an item, so a retried request never works on it twice at once. While an earlier request
     * holds it, that request still works on the item; the caller's claim replaced its own, so it stops at its next write.
     */
    private function itemLock(Run $run, RunItem $item, int $ttl): Lock
    {
        return $this->cache()->lock("tolk:item:{$run->id}:{$item->index}", $ttl);
    }

    private function cache(): Repository
    {
        return Cache::store(config('tolk.cache_store'));
    }
}
