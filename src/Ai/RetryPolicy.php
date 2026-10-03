<?php

namespace Reach\StatamicTolk\Ai;

class RetryPolicy
{
    /**
     * Seconds to wait before attempt `$attempt + 1`: the provider's retry-after when it sent one, otherwise exponential
     * backoff from retry.base_delay_ms with full jitter, capped at retry.max_delay_ms. Never less than one second.
     */
    public function delay(int $attempt, ?int $retryAfterSeconds): int
    {
        if ($retryAfterSeconds !== null) {
            return max(1, $retryAfterSeconds);
        }

        $base = max(1, (int) config('tolk.retry.base_delay_ms', 1000));
        $max = max($base, (int) config('tolk.retry.max_delay_ms', 20000));
        $ceiling = min($max, $base * 2 ** min(max($attempt, 1) - 1, 30));

        return max(1, (int) ceil(mt_rand(0, $ceiling) / 1000));
    }
}
