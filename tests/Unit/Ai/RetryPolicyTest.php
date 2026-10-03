<?php

use Reach\StatamicTolk\Ai\RetryPolicy;

function retryDelays(int $attempt, int $draws = 200): array
{
    mt_srand(11);

    return array_map(fn () => (new RetryPolicy)->delay($attempt, null), range(1, $draws));
}

it('waits the retry-after the provider sent, at least one second', function (int $retryAfter, int $expected) {
    expect((new RetryPolicy)->delay(5, $retryAfter))->toBe($expected);
})->with([[17, 17], [0, 1], [90, 90]]);

it('backs off exponentially with full jitter from the base delay', function (int $attempt, int $max) {
    $delays = retryDelays($attempt);

    expect(min($delays))->toBe(1)
        ->and(max($delays))->toBe($max);
})->with([
    'first attempt' => [1, 1],
    'second' => [2, 2],
    'third' => [3, 4],
    'fourth' => [4, 8],
]);

it('caps the backoff at the maximum delay', function () {
    expect(max(retryDelays(30)))->toBe(20)
        ->and(max(retryDelays(PHP_INT_MAX)))->toBe(20);
});

it('reads the bounds from the config', function () {
    config(['tolk.retry.base_delay_ms' => 3000, 'tolk.retry.max_delay_ms' => 5000]);

    expect(max(retryDelays(1)))->toBe(3)
        ->and(max(retryDelays(4)))->toBe(5);
});

it('treats an attempt below one as the first', function (int $attempt) {
    expect(max(retryDelays($attempt)))->toBe(1);
})->with([0, -3]);
