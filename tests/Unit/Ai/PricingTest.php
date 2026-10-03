<?php

use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Pricing;
use Reach\StatamicTolk\Ai\Usage;

it('bills uncached input, cache reads at 10%, cache writes at 125% and output at the catalog prices', function () {
    $usage = new Usage(input: 2520, output: 48, cacheRead: 1800, cacheWrite: 600, calls: 1);

    // 120 × 2 + 1800 × 0.2 + 600 × 2.5 + 48 × 10 = 2580 dollars per million tokens
    expect(app(Pricing::class)->cost(new ModelRef('anthropic', 'claude-sonnet-5-5'), $usage))->toEqualWithDelta(0.00258, 1e-12);
});

it('never adds reasoning tokens on top of the output', function () {
    $model = new ModelRef('openai', 'gpt-6.1-sol');
    $pricing = app(Pricing::class);

    expect($pricing->cost($model, new Usage(input: 1000, output: 500, reasoning: 400)))
        ->toBe($pricing->cost($model, new Usage(input: 1000, output: 500)))
        ->toEqualWithDelta(0.007, 1e-12);
});

it('never bills a negative uncached input', function () {
    expect(app(Pricing::class)->cost(new ModelRef('gemini', 'gemini-3.5-flash-lite'), new Usage(input: 100, cacheRead: 150)))
        ->toEqualWithDelta(0.0000045, 1e-12);
});

it('has no cost for a model without a price', function () {
    config(['tolk.models.openai.1.price' => null]);

    expect(app(Pricing::class)->cost(new ModelRef('openai', 'gpt-6-luna'), new Usage(1000, 100)))->toBeNull()
        ->and(app(Pricing::class)->cost(new ModelRef('anthropic', 'claude-custom'), new Usage(1000, 100)))->toBeNull();
});
