<?php

use Illuminate\Cache\ArrayStore;
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

it('reads a pause back that the cache store returns as a numeric string, as the Redis store does', function () {
    Cache::extend('numeric-strings', fn () => Cache::repository(new class extends ArrayStore
    {
        public function get($key)
        {
            $value = parent::get($key);

            return is_int($value) ? (string) $value : $value;
        }
    }));
    config(['cache.stores.tolk-redis' => ['driver' => 'numeric-strings'], 'tolk.cache_store' => 'tolk-redis']);

    $throttle = app(Throttle::class);
    $throttle->pause('anthropic', 30);
    $throttle->pause('anthropic', 5);

    expect(Cache::store('tolk-redis')->get('tolk:throttle:anthropic'))->toBeString()
        ->and($throttle->pausedFor('anthropic'))->toBe(30);

    $this->travel(12)->seconds();
    expect($throttle->pausedFor('anthropic'))->toBe(18);
});
