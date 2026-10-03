<?php

namespace Reach\StatamicTolk\Ai;

class Pricing
{
    /**
     * An approximation: providers bill cache reads at about 10% of the input price and cache writes at about 125% (R6 §0),
     * but the exact rates differ per provider and cache lifetime.
     */
    public const float CACHE_READ_RATE = 0.1;

    public const float CACHE_WRITE_RATE = 1.25;

    public function __construct(private readonly ModelCatalog $catalog) {}

    /**
     * USD, or null when the catalog has no price for the model. `input` already includes the cached tokens and `output`
     * the reasoning tokens, so neither is counted twice.
     */
    public function cost(ModelRef $model, Usage $usage): ?float
    {
        if (! $price = $this->catalog->price($model)) {
            return null;
        }

        $uncached = max(0, $usage->input - $usage->cacheRead - $usage->cacheWrite);

        return ($uncached * $price['input']
            + $usage->cacheRead * $price['input'] * self::CACHE_READ_RATE
            + $usage->cacheWrite * $price['input'] * self::CACHE_WRITE_RATE
            + $usage->output * $price['output']) / 1_000_000;
    }
}
