<?php

namespace Reach\StatamicTolk\State;

use InvalidArgumentException;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\UnknownSite;

/**
 * Scans the Overview's cells with StatusScanner, one budgeted slice per request, keeping each cell's running totals in
 * OverviewCache between requests and the finished cell for OverviewCache::TTL.
 */
final readonly class OverviewScanner
{
    public const int BUDGET_MS = 1500;

    public function __construct(
        private StatusScanner $scanner,
        private OverviewCache $cache,
        private int $budgetMs = self::BUDGET_MS,
    ) {}

    public function cached(ContentType $type, string $container, string $site): ?ScanResult
    {
        return $this->cache->finished($this->cache->slot($type, $container, $site));
    }

    /**
     * The cell's totals after one more slice: from $cursor on when the totals before it are still cached under the
     * current versions, else from the start. The result's cursor is null once the cell is finished.
     *
     * @throws InvalidArgumentException|UnknownSite
     */
    public function scan(ContentType $type, string $container, string $site, ?string $cursor = null): ScanResult
    {
        $slot = $this->cache->slot($type, $container, $site);
        $before = $cursor === null ? null : $this->cache->partial($slot, $cursor);
        $totals = ($before ?? ScanResult::empty())->add($this->scanner->scan($type, $container, $site, $before?->cursor, $this->budgetMs));

        $this->cache->put($slot, $totals);

        return $totals;
    }
}
