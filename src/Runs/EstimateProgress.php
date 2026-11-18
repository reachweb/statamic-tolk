<?php

namespace Reach\StatamicTolk\Runs;

/**
 * How far RunPlanner::estimate() got: the run's estimate so far, the index to continue from, and how many items it went
 * through. Removed items leave gaps in the indexes, so the index does not count the items.
 */
final readonly class EstimateProgress
{
    /**
     * @param  ?int  $next  null once every item is estimated
     */
    public function __construct(
        public Estimate $estimate,
        public ?int $next,
        public int $items = 0,
    ) {}

    public function isFinished(): bool
    {
        return $this->next === null;
    }

    public function toArray(): array
    {
        return [...$this->estimate->toArray(), 'next' => $this->next, 'items' => $this->items];
    }

    public static function fromArray(array $data): self
    {
        $next = is_numeric($data['next'] ?? null) ? max(0, (int) $data['next']) : null;

        // An estimate stored before `items` counted its items by its index.
        return new self(Estimate::fromArray($data), $next, is_numeric($data['items'] ?? null) ? max(0, (int) $data['items']) : ($next ?? 0));
    }
}
