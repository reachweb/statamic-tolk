<?php

namespace Reach\StatamicTolk\Runs;

use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Enums\UsageSource;
use Throwable;

/**
 * The default recorder of provider calls: each call goes into the monthly usage log (§A12 "Usage"). It runs inside
 * `Pipeline::runUnit()`, between a paid call and the storing of its outcome, so it never throws: a failure there would
 * make the caller repeat the call.
 */
final readonly class UsageRecorder
{
    public function __construct(private UsageLog $log) {}

    public function record(ModelRef $model, Usage $usage, UsageSource $source): void
    {
        try {
            $this->log->add($model, $usage, $source);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
