<?php

namespace Reach\StatamicTolk\Runs;

/**
 * What one RunProcessor::applyReady() call did, and when the driver should call again.
 */
final readonly class ApplyReport
{
    /**
     * @param  int  $items  items that finished applying: `done`, `skipped` or `failed`
     * @param  int  $returned  items sent back to `pending` to be planned and translated again
     * @param  bool  $remains  whether the run is running and an item is still to apply (`translated` or `applying`)
     * @param  ?int  $wait  seconds until the next call can do something: 0 at once, a few while another process saves
     *                      content or holds the item; null when nothing remains to apply
     */
    public function __construct(
        public int $items = 0,
        public int $returned = 0,
        public bool $remains = false,
        public ?int $wait = null,
    ) {}

    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'returned' => $this->returned,
            'remains' => $this->remains,
            'wait' => $this->wait,
        ];
    }
}
