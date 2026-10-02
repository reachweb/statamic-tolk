<?php

use Reach\StatamicTolk\Tests\Support\LiveProbe;
use Reach\StatamicTolk\Tests\Support\PlaceholderCheck;
use Reach\StatamicTolk\Tests\Support\ProviderFixtures;

it('answers one tiny call with the catalog options', function (string $provider, string $tier) {
    $this->requireProvider($provider);

    $agent = fn (array $overrides = []) => ProviderFixtures::agent($provider, $tier, [
        'systemPrompt' => LiveProbe::systemPrompt('el'),
        'timeoutSeconds' => 180,
        'outputCap' => 4096,
        ...$overrides,
    ]);
    $user = LiveProbe::user([['id' => 's1', 'text' => 'Good morning']]);

    $result = LiveProbe::call($agent(), $user);

    if (($result['error']['status'] ?? null) === 400) {
        $result['without_options'] = LiveProbe::call($agent(['options' => []]), $user);
    }

    LiveProbe::record($provider, $tier, 'tiny', $result);

    expect($result['error'])->toBeNull()
        ->and($result['finish'])->toBe('stop')
        ->and($result['translations'])->toHaveKey('s1');
})->with(ProviderFixtures::PROVIDERS)->with(['quality', 'fast']);

it('keeps the placeholders of twenty inline segments', function (string $provider, string $tier) {
    $this->requireProvider($provider);

    $segments = LiveProbe::fixture('placeholders');
    $report = [];

    foreach (array_keys(PlaceholderCheck::SYNTAXES) as $syntax) {
        foreach (array_keys(LiveProbe::LANGUAGES) as $target) {
            $source = array_map(fn (array $segment) => [
                'id' => $segment['id'],
                'text' => PlaceholderCheck::convert($segment['text'], $syntax),
            ], $segments);

            $result = LiveProbe::call(ProviderFixtures::agent($provider, $tier, [
                'systemPrompt' => LiveProbe::systemPrompt($target, $syntax),
                'timeoutSeconds' => 180,
                'outputCap' => 8192,
            ]), LiveProbe::user($source, 'inline'));

            $problems = PlaceholderCheck::failures($source, $result['translations'], $syntax);

            $report[$syntax][$target] = [
                'failures' => count($problems),
                'problems' => $problems,
                ...$result,
            ];
        }
    }

    LiveProbe::record($provider, $tier, 'placeholders', $report);

    foreach ($report as $syntax => $targets) {
        foreach ($targets as $target => $result) {
            expect($result['error'])->toBeNull("{$syntax} → {$target} failed")
                ->and($result['translations'])->not->toBeEmpty();
        }
    }
})->with(ProviderFixtures::PROVIDERS)->with(['quality', 'fast']);

it('measures 6,000 characters as one call and as four calls of 1,500', function (string $provider, string $tier) {
    $this->requireProvider($provider);

    $paragraphs = LiveProbe::fixture('latency');
    $call = fn (array $items) => LiveProbe::call(ProviderFixtures::agent($provider, $tier, [
        'systemPrompt' => LiveProbe::systemPrompt('el'),
        'timeoutSeconds' => 300,
        'outputCap' => 16000,
    ]), LiveProbe::user($items));

    $one = $call($paragraphs);
    $four = array_map(fn (array $group) => [
        'chars' => LiveProbe::chars($group),
        ...$call($group),
    ], LiveProbe::groups($paragraphs, 4));

    $times = array_column($four, 'ms');

    LiveProbe::record($provider, $tier, 'latency', [
        'chars' => LiveProbe::chars($paragraphs),
        'one_call' => $one,
        'four_calls' => $four,
        'four_calls_slowest_ms' => max($times),
        'four_calls_total_ms' => array_sum($times),
    ]);

    expect($one['error'])->toBeNull()
        ->and($one['finish'])->toBe('stop')
        ->and(array_column($four, 'error'))->each->toBeNull();
})->with(ProviderFixtures::PROVIDERS)->with(['quality', 'fast']);
