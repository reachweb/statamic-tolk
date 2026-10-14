<?php

namespace Reach\StatamicTolk\Support;

/**
 * What Pruner::prune() did, or would do in a dry run.
 */
final readonly class PruneReport
{
    /**
     * The target no longer exists: the content was deleted, or a term's default slug was renamed without Statamic's events.
     */
    public const string DELETED = 'deleted';

    /**
     * The content exists but is no longer a localization, such as an entry whose origin was deleted.
     */
    public const string NOT_A_LOCALIZATION = 'not_a_localization';

    /**
     * @param  list<array{reference: string, reason: string}>  $orphans  the state records deleted, with the reason
     * @param  int  $resolved  fields whose pending entries were confirmed: promoted, or turned into a baseline (§A5)
     * @param  int  $expired  fields whose pending entries older than FieldState::PENDING_DAYS were dropped
     * @param  list<string>  $stoppedRuns  the ids of the runs stopped for making no progress
     * @param  int  $deletedRuns  the runs deleted for having had no activity for `runs.retention_days`
     * @param  int  $located  entries' state records given the entry's collection and site, which those written by
     *                        1.0.0-beta.1 lack (§A5)
     */
    public function __construct(
        public bool $dryRun = false,
        public array $orphans = [],
        public int $resolved = 0,
        public int $expired = 0,
        public array $stoppedRuns = [],
        public int $deletedRuns = 0,
        public int $located = 0,
    ) {}

    public function changesState(): bool
    {
        return $this->orphans !== [] || $this->resolved > 0 || $this->expired > 0 || $this->located > 0;
    }

    public function isEmpty(): bool
    {
        return ! $this->changesState() && $this->stoppedRuns === [] && $this->deletedRuns === 0;
    }

    /**
     * @return list<string> the references of the orphans with that reason
     */
    public function orphaned(string $reason): array
    {
        return array_column(array_filter($this->orphans, fn (array $orphan) => $orphan['reason'] === $reason), 'reference');
    }
}
