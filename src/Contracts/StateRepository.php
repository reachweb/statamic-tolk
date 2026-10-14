<?php

namespace Reach\StatamicTolk\Contracts;

use Closure;
use Reach\StatamicTolk\Exceptions\UnreadableState;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\Targets\TargetRef;

interface StateRepository
{
    /**
     * Returns an empty state when nothing is stored for the target.
     *
     * @throws UnreadableState for a record that exists but cannot be read, which is never read as empty
     */
    public function get(TargetRef $ref): ItemState;

    /**
     * Reads, changes and writes the target's state as one atomic step. An empty result deletes the record.
     *
     * @param  Closure(ItemState): ItemState  $callback
     *
     * @throws UnreadableState for a record that cannot be read, which is left as it is
     */
    public function update(TargetRef $ref, Closure $callback): ItemState;

    public function delete(TargetRef $ref): void;

    /**
     * Every target with stored state, read lazily.
     *
     * @return iterable<TargetRef>
     */
    public function references(): iterable;

    /**
     * Every stored record that cannot be read, which references() leaves out, read lazily.
     *
     * @return iterable<UnreadableState> as get() throws it for the record
     */
    public function unreadable(): iterable;
}
