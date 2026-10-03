<?php

use Illuminate\Support\Facades\Cache;
use Reach\StatamicTolk\Exceptions\WriterBusy;
use Reach\StatamicTolk\Support\ContentWriter;
use Statamic\Facades\Blink;

beforeEach(function () {
    $this->useFileCache();
});

it('runs the callback under the write lock and returns its result', function () {
    $inside = ContentWriter::write(fn () => [
        ContentWriter::active(),
        Cache::lock(ContentWriter::LOCK, 10)->get(),
    ]);

    expect(ContentWriter::active())->toBeFalse()
        ->and($inside)->toBe([true, false])
        ->and(Cache::lock(ContentWriter::LOCK, 10)->get())->toBeTrue();
});

it('releases the lock and leaves the writer when the callback throws', function () {
    expect(fn () => ContentWriter::write(fn () => throw new RuntimeException('failed')))->toThrow(RuntimeException::class, 'failed')
        ->and(ContentWriter::active())->toBeFalse()
        ->and(Cache::lock(ContentWriter::LOCK, 10)->get())->toBeTrue();
});

it('runs a nested write at once, inside the lock it already holds', function () {
    config(['tolk.write.wait_seconds' => 0]);

    $nested = ContentWriter::write(fn () => ContentWriter::write(fn () => ContentWriter::active()));

    expect($nested)->toBeTrue()
        ->and(ContentWriter::active())->toBeFalse();
});

it('throws WriterBusy when another process holds the lock longer than the writer waits', function () {
    config(['tolk.write.wait_seconds' => 0]);
    Cache::lock(ContentWriter::LOCK, 10)->get();
    $ran = false;

    expect(fn () => ContentWriter::write(function () use (&$ran) {
        $ran = true;
    }))->toThrow(WriterBusy::class);

    expect($ran)->toBeFalse()
        ->and(ContentWriter::active())->toBeFalse();
});

it('takes the lock from the configured cache store', function () {
    config([
        'cache.stores.tolk-locks' => ['driver' => 'file', 'path' => $this->temporaryPath('locks')],
        'tolk.cache_store' => 'tolk-locks',
    ]);

    $held = ContentWriter::write(fn () => [
        Cache::store('tolk-locks')->lock(ContentWriter::LOCK, 10)->get(),
        Cache::lock(ContentWriter::LOCK, 10)->get(),
    ]);

    expect($held)->toBe([false, true]);
});

it('refreshes Blink before the callback', function () {
    Blink::put('tolk-test', 'stale');

    expect(ContentWriter::write(fn () => Blink::has('tolk-test')))->toBeFalse();
});
