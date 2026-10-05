<?php

namespace Reach\StatamicTolk\Runs;

/**
 * What one RunProcessor::translate() call did, and when the driver should call again.
 */
final readonly class WorkReport
{
    /**
     * @param  int  $units  units run, each at most one provider call
     * @param  int  $items  items that finished translating: `translated`, `skipped` or `failed`
     * @param  bool  $remains  whether the run is running and an item is still to translate (`pending` or `translating`)
     * @param  ?int  $wait  seconds until the next call can do something: 0 at once, the provider's pause after a transient
     *                      failure, or until an item becomes available; null when no item has a known time
     */
    public function __construct(
        public int $units = 0,
        public int $items = 0,
        public bool $remains = false,
        public ?int $wait = null,
    ) {}

    public function toArray(): array
    {
        return [
            'units' => $this->units,
            'items' => $this->items,
            'remains' => $this->remains,
            'wait' => $this->wait,
        ];
    }
}
