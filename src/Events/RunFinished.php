<?php

namespace Reach\StatamicTolk\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Reach\StatamicTolk\Ai\UsageMap;
use Reach\StatamicTolk\Enums\RunStatus;

/**
 * A run ended: completed, completed with errors, cancelled or aborted (RunProcessor::finalize()). Fired once per end; a
 * run that is resumed and ends again fires it again.
 */
final readonly class RunFinished
{
    use Dispatchable;

    /**
     * @param  array<string, int>  $counts  the number of items per status
     */
    public function __construct(
        public string $runId,
        public RunStatus $status,
        public array $counts,
        public UsageMap $usage,
    ) {}
}
