<?php

use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\TextResponse;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Ai\UsageMap;

it('reads the usage of a response as one call, with unreported counts as zero', function () {
    $usage = Usage::fromResponse(new TextResponse('', new TextUsage(2520, 60, 1800, null, 12), new Meta));

    expect($usage->toArray())->toBe(['input' => 2520, 'output' => 60, 'cache_read' => 1800, 'cache_write' => 0, 'reasoning' => 12, 'calls' => 1]);
});

it('adds usages without changing either', function () {
    $first = new Usage(100, 20, 10, 5, 2, 1);
    $second = new Usage(50, 10, 0, 0, 0, 1);

    expect($first->add($second)->toArray())->toBe(['input' => 150, 'output' => 30, 'cache_read' => 10, 'cache_write' => 5, 'reasoning' => 2, 'calls' => 2])
        ->and($first->input)->toBe(100)
        ->and($second->input)->toBe(50);
});

it('round-trips through an array', function () {
    $usage = new Usage(100, 20, 10, 5, 2, 3);

    expect(Usage::fromArray($usage->toArray()))->toEqual($usage);
});

it('reads an array leniently', function () {
    expect(Usage::fromArray(['input' => '12', 'output' => 'many', 'cache_read' => -4, 'calls' => 2.0, 'other' => 9])->toArray())
        ->toBe(['input' => 12, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'reasoning' => 0, 'calls' => 2]);
});

it('keys a usage map by the requested model ref and sums per model', function () {
    $sonnet = new ModelRef('anthropic', 'claude-sonnet-5-5');
    $luna = new ModelRef('openai', 'gpt-6-luna');

    $map = (new UsageMap)
        ->add($sonnet, new Usage(100, 10, calls: 1))
        ->add($luna, new Usage(40, 4, calls: 1))
        ->add($sonnet, new Usage(50, 5, calls: 1));

    expect($map->toArray())->toBe([
        'anthropic:claude-sonnet-5-5' => ['input' => 150, 'output' => 15, 'cache_read' => 0, 'cache_write' => 0, 'reasoning' => 0, 'calls' => 2],
        'openai:gpt-6-luna' => ['input' => 40, 'output' => 4, 'cache_read' => 0, 'cache_write' => 0, 'reasoning' => 0, 'calls' => 1],
    ])
        ->and($map->get($luna)->input)->toBe(40)
        ->and($map->get(new ModelRef('gemini', 'gemini-3.8-flash')))->toEqual(new Usage)
        ->and($map->isEmpty())->toBeFalse()
        ->and((new UsageMap)->isEmpty())->toBeTrue();
});

it('merges usage maps without changing either', function () {
    $sonnet = new ModelRef('anthropic', 'claude-sonnet-5-5');
    $flash = new ModelRef('gemini', 'gemini-3.8-flash');
    $first = (new UsageMap)->add($sonnet, new Usage(100, 10, calls: 1));
    $second = (new UsageMap)->add($sonnet, new Usage(1, 1, calls: 1))->add($flash, new Usage(7, 3, calls: 1));

    $merged = $first->merge($second);

    expect($merged->get($sonnet)->toArray())->toBe(['input' => 101, 'output' => 11, 'cache_read' => 0, 'cache_write' => 0, 'reasoning' => 0, 'calls' => 2])
        ->and($merged->get($flash)->input)->toBe(7)
        ->and($first->get($sonnet)->input)->toBe(100)
        ->and($first->get($flash)->calls)->toBe(0);
});

it('round-trips a usage map through an array and skips invalid entries', function () {
    $map = (new UsageMap)
        ->add(new ModelRef('anthropic', 'claude-sonnet-5-5'), new Usage(100, 10, 5, 1, 0, 1))
        ->add(new ModelRef('openai', 'gpt-6-luna'), new Usage(40, 4, calls: 1));

    expect(UsageMap::fromArray($map->toArray()))->toEqual($map)
        ->and(UsageMap::fromArray(['openai:gpt-6-luna' => 'nope', 0 => ['input' => 1]])->isEmpty())->toBeTrue();
});
