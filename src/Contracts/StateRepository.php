<?php

namespace Reach\StatamicTolk\Contracts;

use Closure;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\Targets\TargetRef;

interface StateRepository
{
    /**
     * Returns an empty state when nothing is stored for the target.
     */
    public function get(TargetRef $ref): ItemState;

    /**
     * Reads, changes and writes the target's state as one atomic step. An empty result deletes the record.
     *
     * @param  Closure(ItemState): ItemState  $callback
     */
    public function update(TargetRef $ref, Closure $callback): ItemState;

    public function delete(TargetRef $ref): void;

    /**
     * Every target with stored state, read lazily.
     *
     * @return iterable<TargetRef>
     */
    public function references(): iterable;
}
