<?php

namespace Reach\StatamicTolk\Translation;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Plans, unit outcomes, unit locks and transient failure counts, in the cache store of `tolk.cache_store`. Plans and
 * outcomes are stored as arrays, never as objects (rule 12).
 */
final class PlanStore
{
    public function put(Plan $plan): void
    {
        $this->cache()->put($this->planKey($plan->id), $plan->toArray(), $this->ttl());
    }

    public function get(string $id): ?Plan
    {
        $data = $this->cache()->get($this->planKey($id));

        return is_array($data) ? Plan::fromArray($data) : null;
    }

    public function putOutcome(string $planId, UnitOutcome $outcome): void
    {
        $this->cache()->put($this->outcomeKey($planId, $outcome->unit), $outcome->toArray(), $this->ttl());
    }

    public function outcome(string $planId, string $unitId): ?UnitOutcome
    {
        $data = $this->cache()->get($this->outcomeKey($planId, $unitId));

        return is_array($data) ? UnitOutcome::fromArray($data) : null;
    }

    /**
     * Every unit of the plan with its outcome, null while it has none: the initial batches, each followed depth first by
     * the units its outcome spawned (§A8 step 2).
     *
     * @return array<string, array{unit: Unit, outcome: ?UnitOutcome}>
     */
    public function units(Plan $plan): array
    {
        $units = [];

        $visit = function (Unit $unit) use ($plan, &$units, &$visit): void {
            if (isset($units[$unit->id])) {
                return;
            }

            $outcome = $this->outcome($plan->id, $unit->id);
            $units[$unit->id] = ['unit' => $unit, 'outcome' => $outcome];

            foreach ($outcome->spawned ?? [] as $spawned) {
                $visit($spawned);
            }
        };

        foreach ($plan->batches as $batch) {
            $visit(Unit::batch($batch, $plan->kind));
        }

        return $units;
    }

    /**
     * Removes the plan, every outcome reachable from it and the units' failure counts.
     */
    public function forget(Plan $plan): void
    {
        foreach (array_keys($this->units($plan)) as $unitId) {
            $this->cache()->forget($this->outcomeKey($plan->id, $unitId));
            $this->cache()->forget($this->failuresKey($plan->id, $unitId));
        }

        $this->cache()->forget($this->planKey($plan->id));
    }

    public function failures(string $planId, string $unitId): int
    {
        return (int) $this->cache()->get($this->failuresKey($planId, $unitId), 0);
    }

    public function countFailure(string $planId, string $unitId): int
    {
        $key = $this->failuresKey($planId, $unitId);

        $this->cache()->add($key, 0, $this->ttl());

        return (int) $this->cache()->increment($key);
    }

    public function lock(string $planId, string $unitId, int $seconds): Lock
    {
        return $this->cache()->lock("tolk:unit:{$planId}:{$unitId}", $seconds);
    }

    private function planKey(string $id): string
    {
        return "tolk:plan:{$id}";
    }

    private function outcomeKey(string $planId, string $unitId): string
    {
        return "tolk:plan:{$planId}:unit:{$unitId}";
    }

    private function failuresKey(string $planId, string $unitId): string
    {
        return "tolk:unit-fails:{$planId}:{$unitId}";
    }

    private function ttl(): int
    {
        return max(1, (int) config('tolk.plan_ttl', 7200));
    }

    private function cache(): Repository
    {
        return Cache::store(config('tolk.cache_store'));
    }
}
