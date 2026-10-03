<?php

use Illuminate\Support\Facades\Cache;
use Reach\StatamicTolk\Ai\Throttle;

beforeEach(function () {
    $this->useFileCache();
    $this->freezeTime();
});

it('is not paused by default', function () {
    expect(app(Throttle::class)->pausedFor('anthropic'))->toBe(0);
});

it('pauses one provider for the given seconds and counts down', function () {
    $throttle = app(Throttle::class);

    $throttle->pause('openai', 30);

    expect($throttle->pausedFor('openai'))->toBe(30)
        ->and($throttle->pausedFor('anthropic'))->toBe(0)
        ->and(Cache::get('tolk:throttle:openai'))->toBeInt();

    $this->travel(12)->seconds();
    expect($throttle->pausedFor('openai'))->toBe(18);

    $this->travel(19)->seconds();
    expect($throttle->pausedFor('openai'))->toBe(0);
});

it('keeps the longer of two pauses', function () {
    $throttle = app(Throttle::class);

    $throttle->pause('gemini', 30);
    $throttle->pause('gemini', 5);
    expect($throttle->pausedFor('gemini'))->toBe(30);

    $throttle->pause('gemini', 60);
    expect($throttle->pausedFor('gemini'))->toBe(60);
});

it('ignores a pause of no seconds', function (int $seconds) {
    app(Throttle::class)->pause('anthropic', $seconds);

    expect(app(Throttle::class)->pausedFor('anthropic'))->toBe(0);
})->with([0, -5]);

it('uses the configured cache store', function () {
    config(['cache.stores.tolk-throttle' => ['driver' => 'file', 'path' => $this->temporaryPath('throttle-cache')], 'tolk.cache_store' => 'tolk-throttle']);

    app(Throttle::class)->pause('openai', 10);

    expect(Cache::store('tolk-throttle')->has('tolk:throttle:openai'))->toBeTrue()
        ->and(Cache::has('tolk:throttle:openai'))->toBeFalse();
});
