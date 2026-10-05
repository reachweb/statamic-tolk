<?php

namespace Reach\StatamicTolk\Runs;

/**
 * What RunPlanner::build() makes of a request, before anything is stored.
 */
final readonly class PlannedRun
{
    /**
     * @param  list<RunItem>  $items  indexed from 0 in the order they are handed out
     * @param  array<string, int>  $skipped  targets left out of the run, counted by reason
     */
    public function __construct(
        public Run $run,
        public array $items,
        public array $skipped,
    ) {}
}
