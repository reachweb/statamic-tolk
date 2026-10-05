<?php

namespace Reach\StatamicTolk\Runs;

/**
 * How far RunPlanner::estimate() got: the run's estimate so far and the index to continue from.
 */
final readonly class EstimateProgress
{
    /**
     * @param  ?int  $next  null once every item is estimated
     */
    public function __construct(
        public Estimate $estimate,
        public ?int $next,
    ) {}

    public function isFinished(): bool
    {
        return $this->next === null;
    }

    public function toArray(): array
    {
        return [...$this->estimate->toArray(), 'next' => $this->next];
    }

    public static function fromArray(array $data): self
    {
        return new self(Estimate::fromArray($data), is_numeric($data['next'] ?? null) ? max(0, (int) $data['next']) : null);
    }
}
