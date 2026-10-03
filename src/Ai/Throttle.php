<?php

namespace Reach\StatamicTolk\Ai;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A provider-wide pause after a rate limit or an overload, shared by every process through the cache (§A10).
 */
class Throttle
{
    public function pause(string $provider, int $seconds): void
    {
        $until = Carbon::now()->getTimestamp() + $seconds;

        if ($seconds <= 0 || $until <= $this->until($provider)) {
            return;
        }

        $this->cache()->put($this->key($provider), $until, $seconds);
    }

    public function pausedFor(string $provider): int
    {
        return max(0, $this->until($provider) - Carbon::now()->getTimestamp());
    }

    private function until(string $provider): int
    {
        $until = $this->cache()->get($this->key($provider));

        return is_int($until) ? $until : 0;
    }

    private function key(string $provider): string
    {
        return "tolk:throttle:{$provider}";
    }

    private function cache(): Repository
    {
        return Cache::store(config('tolk.cache_store'));
    }
}
