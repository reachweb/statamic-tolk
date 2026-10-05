<?php

namespace Reach\StatamicTolk\Contracts;

use Carbon\CarbonInterface;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Exceptions\ClaimLost;
use Reach\StatamicTolk\Exceptions\RunNotFound;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunItem;

/**
 * Runs and their items (§A12). Every method that writes refreshes the Run it is given from what is stored, and updates
 * the RunItem it is given. The repository owns the claims: an item holds one while it is `translating` or `applying`.
 */
interface RunRepository
{
    /**
     * Stores a new run with its items, each with its own index.
     *
     * @param  iterable<RunItem>  $items
     */
    public function create(Run $run, iterable $items): Run;

    public function find(string $id): ?Run;

    /**
     * Writes the values changed since the run was read; values another process changed meanwhile are kept.
     *
     * @throws RunNotFound
     */
    public function save(Run $run): void;

    public function delete(Run $run): void;

    /**
     * @return list<Run> newest first
     */
    public function recent(int $limit): array;

    /**
     * @return list<Run> running runs without progress for that many seconds
     */
    public function stale(int $seconds): array;

    /**
     * Deletes the runs without activity for that many days and returns how many.
     */
    public function prune(int $olderThanDays): int;

    public function item(Run $run, int $index): ?RunItem;

    /**
     * @return list<RunItem> one page (from 1) of the items, in index order
     */
    public function items(Run $run, ?RunItemStatus $status = null, int $page = 1, int $perPage = 50): array;

    /**
     * @return array<string, int> the number of items per status, every status included
     */
    public function counts(Run $run): array;

    /**
     * Hands out, atomically, the item this worker already holds, else the lowest eligible `pending` item that is
     * available, else a `translating` item whose claim expired (an attempt; the third fails it). Null while the run is
     * pending, cancelled or aborted, or when nothing can be claimed now.
     */
    public function claimNext(Run $run, string $workerId, int $ttl): ?RunItem;

    /**
     * Hands out, atomically and moved to `applying`, the item this worker already holds, else the lowest `translated`
     * item that is available, else an `applying` item whose claim expired (an attempt; the third fails it).
     */
    public function claimTranslated(Run $run, string $workerId, int $ttl): ?RunItem;

    /**
     * @throws ClaimLost
     */
    public function extendClaim(Run $run, RunItem $item): void;

    /**
     * Stores the item. A claim is dropped once the item is neither `translating` nor `applying`.
     *
     * @throws ClaimLost when the caller's claim token is not the stored one
     */
    public function saveItem(Run $run, RunItem $item): void;

    /**
     * Stores the item and gives up its claim: an `applying` item is `translated` again, any other unfinished item
     * `pending`, available from $availableAt.
     *
     * @throws ClaimLost
     */
    public function release(Run $run, RunItem $item, ?CarbonInterface $availableAt = null): void;

    /**
     * Puts the failed items, and the items skipped because a dependency failed, back to `pending`, atomically: their
     * attempts, waits, reason, error and availability are cleared, their plan is kept. Returns how many.
     *
     * @throws RunNotFound
     */
    public function retryFailed(Run $run): int;

    /**
     * Seconds until some item can be claimed with claimNext(), 0 when one can be now, null when nothing is waiting.
     */
    public function nextAvailableIn(Run $run): ?int;

    /**
     * @throws RunNotFound
     */
    public function addUsage(Run $run, ModelRef $model, Usage $usage): void;
}
