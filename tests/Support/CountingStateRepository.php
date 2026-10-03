<?php

namespace Reach\StatamicTolk\Tests\Support;

use Closure;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\Targets\TargetRef;

/**
 * Wraps a repository and counts its writes.
 */
final class CountingStateRepository implements StateRepository
{
    public int $writes = 0;

    public function __construct(private readonly StateRepository $inner) {}

    public function get(TargetRef $ref): ItemState
    {
        return $this->inner->get($ref);
    }

    public function update(TargetRef $ref, Closure $callback): ItemState
    {
        $this->writes++;

        return $this->inner->update($ref, $callback);
    }

    public function delete(TargetRef $ref): void
    {
        $this->writes++;

        $this->inner->delete($ref);
    }

    public function references(): iterable
    {
        return $this->inner->references();
    }
}
