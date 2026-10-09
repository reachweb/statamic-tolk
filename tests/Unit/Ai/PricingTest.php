<?php

use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Pricing;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Ai\UsageMap;

it('bills uncached input, cache reads at 10%, cache writes at 125% and output at the catalog prices', function () {
    $usage = new Usage(input: 2520, output: 48, cacheRead: 1800, cacheWrite: 600, calls: 1);

    // 120 × 2 + 1800 × 0.2 + 600 × 2.5 + 48 × 10 = 2580 dollars per million tokens
    expect(app(Pricing::class)->cost(new ModelRef('anthropic', 'claude-sonnet-5-5'), $usage))->toEqualWithDelta(0.00258, 1e-12);
});

it('bills cache reads and writes at the prices of the catalog entry when it has them', function () {
    $pricing = app(Pricing::class);

    // GPT-6.1 Sol reads at $0.10 per million tokens, 0.05 times its input price, and writes at the usual 125%.
    expect($pricing->cost(new ModelRef('openai', 'gpt-6.1-sol'), new Usage(input: 3000, cacheRead: 1000, cacheWrite: 1000)))
        ->toEqualWithDelta((1000 * 2 + 1000 * 0.1 + 1000 * 2.5) / 1_000_000, 1e-12);

    config(['tolk.models.openai' => [['id' => 'gpt-6-luna', 'price' => ['input' => 0.1, 'output' => 0.5, 'cache_read' => 'free', 'cache_write' => 0.2]]]]);

    // Luna: a written price, and a read price that is not a number, so 10% of the input price.
    expect($pricing->cost(new ModelRef('openai', 'gpt-6-luna'), new Usage(input: 3000, cacheRead: 1000, cacheWrite: 1000)))
        ->toEqualWithDelta((1000 * 0.1 + 1000 * 0.01 + 1000 * 0.2) / 1_000_000, 1e-12);
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
    config(['tolk.models.openai' => [['id' => 'gpt-6-luna', 'price' => null]]]);

    expect(app(Pricing::class)->cost(new ModelRef('openai', 'gpt-6-luna'), new Usage(1000, 100)))->toBeNull()
        ->and(app(Pricing::class)->cost(new ModelRef('anthropic', 'claude-custom'), new Usage(1000, 100)))->toBeNull();
});

it('totals a usage map, and has no total when one of its models has no price', function () {
    $pricing = app(Pricing::class);
    $usages = (new UsageMap)
        ->add(new ModelRef('openai', 'gpt-6.1-sol'), new Usage(input: 1000, output: 500))
        ->add(new ModelRef('gemini', 'gemini-3.5-flash-lite'), new Usage(input: 100, cacheRead: 150));

    expect($pricing->total($usages))->toEqualWithDelta(0.0070045, 1e-12)
        ->and($pricing->total(new UsageMap))->toBe(0.0)
        ->and($pricing->total($usages->add(new ModelRef('anthropic', 'claude-custom'), new Usage(1000, 100))))->toBeNull()
        ->and($pricing->total(new UsageMap(['not a model' => new Usage(1000, 100)])))->toBeNull();
});
