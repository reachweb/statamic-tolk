<?php

namespace Reach\StatamicTolk\Translation;

use Closure;
use Illuminate\Support\Sleep;
use LogicException;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Ai\UsageMap;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\TargetResultStatus;
use Reach\StatamicTolk\Enums\UsageSource;
use Reach\StatamicTolk\Events\TargetTranslated;
use Reach\StatamicTolk\Exceptions\ApplyFailed;
use Reach\StatamicTolk\Exceptions\NotSupportedYet;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\Exceptions\RootMissing;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\Exceptions\TransientProviderFailure;
use Reach\StatamicTolk\Exceptions\WriterBusy;
use Reach\StatamicTolk\Runs\UsageRecorder;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\EntryTarget;
use RuntimeException;

/**
 * Translates one target and saves it, all in this process (§A8, §A11). It sleeps through transient provider failures,
 * so it serves the CLI and background work, never a web request.
 */
final readonly class TargetTranslator
{
    public const int MAX_WAITS = 10;

    public function __construct(
        private Planner $planner,
        private PlanStore $store,
        private Pipeline $pipeline,
        private StateWriter $stateWriter,
        private UsageRecorder $recorder,
        private PlanApplier $applier,
    ) {}

    /**
     * @throws ProviderRejected when the provider refuses the account or the model, which every other target would meet too
     */
    public function translate(Target $target, PlanOptions $options, ApplyOptions $apply): TargetResult
    {
        return $this->process($target, $apply, function (Target $target) use ($options) {
            // Checked before planning, so no tokens are spent on an entry that could not be saved (§A11 "Root page").
            if ($target instanceof EntryTarget) {
                $target->ensureRootExists();
            }

            return $this->planner->plan($target, $options);
        });
    }

    /**
     * Reviews the target's existing translations (T7.4) and saves only the fields the reviewer corrected.
     *
     * @throws ProviderRejected when the provider refuses the account or the model, which every other target would meet too
     */
    public function review(Target $target, PlanOptions $options, ApplyOptions $apply): TargetResult
    {
        return $this->process($target, $apply, fn (Target $target) => $this->planner->planReview($target, $options));
    }

    /**
     * @param  Closure(Target): Plan  $plan
     */
    private function process(Target $target, ApplyOptions $apply, Closure $plan): TargetResult
    {
        $usage = new UsageMap;
        $record = function (ModelRef $model, Usage $call) use (&$usage): void {
            $usage = $usage->add($model, $call);
            $this->recorder->record($model, $call, UsageSource::Cli);
        };

        try {
            $this->stateWriter->confirm($target);

            try {
                $result = $this->runPlan($plan($target), $target, $apply, $record);
            } catch (SourceChanged) {
                $target = $target->fresh();
                $result = $this->runPlan($plan($target), $target, $apply, $record);
            }
        } catch (RootMissing|SourceChanged|TransientProviderFailure|WriterBusy|ApplyFailed|NotSupportedYet $exception) {
            $result = $this->failed($target, $exception);
        }

        $result = $result->withUsage($usage);

        if ($result->status === TargetResultStatus::Done) {
            TargetTranslated::dispatch($result->ref, $result->appliedFields(), array_keys($usage->usages), $usage);
        }

        return $result;
    }

    private function runPlan(Plan $plan, Target $target, ApplyOptions $apply, Closure $record): TargetResult
    {
        if ($plan->isEmpty()) {
            return new TargetResult(TargetResultStatus::Skipped, $target->ref(), fields: $plan->skipped, reason: TargetResult::NOTHING_TO_DO);
        }

        $this->store->put($plan);

        try {
            $this->run($plan, $record);

            return $this->applier->apply($plan, $target->fresh(), $apply);
        } finally {
            $this->store->forget($plan);
        }
    }

    /**
     * Runs the plan's units until it is finished, waiting as long as each transient failure asks.
     *
     * @throws TransientProviderFailure after MAX_WAITS waits in a row
     */
    private function run(Plan $plan, Closure $record): void
    {
        $waits = 0;

        while (($runnable = $this->pipeline->runnable($plan)) !== []) {
            try {
                $this->pipeline->runUnit($plan, array_key_first($runnable), $record);
                $waits = 0;
            } catch (TransientProviderFailure $failure) {
                if ($waits++ === self::MAX_WAITS) {
                    throw $failure;
                }

                Sleep::for(max(1, $failure->seconds))->seconds();
            }
        }
    }

    private function failed(Target $target, RuntimeException|LogicException $exception): TargetResult
    {
        $reason = match (true) {
            $exception instanceof RootMissing => TargetResult::ROOT_MISSING,
            $exception instanceof SourceChanged => TargetResult::SOURCE_CHANGED,
            $exception instanceof TransientProviderFailure => TargetResult::PROVIDER_UNAVAILABLE,
            $exception instanceof WriterBusy => TargetResult::WRITER_BUSY,
            $exception instanceof ApplyFailed => TargetResult::APPLY_FAILED,
            default => TargetResult::NOT_SUPPORTED,
        };

        $message = $exception instanceof TransientProviderFailure
            ? "The AI provider [{$exception->provider}] was still unavailable after ".self::MAX_WAITS.' waits.'
            : $exception->getMessage();

        return TargetResult::failed($reason, $message, $target->fresh()->ref());
    }
}
