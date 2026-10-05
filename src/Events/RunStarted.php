<?php

namespace Reach\StatamicTolk\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Reach\StatamicTolk\Enums\RunDriver;

/**
 * A run moved from `pending` to `running` (RunProcessor::start()).
 */
final readonly class RunStarted
{
    use Dispatchable;

    /**
     * @param  ?array{id: string, name: string}  $createdBy  null for the CLI
     * @param  array<string, int>  $counts  the number of items per status
     */
    public function __construct(
        public string $runId,
        public RunDriver $driver,
        public ?array $createdBy,
        public array $counts,
    ) {}
}
