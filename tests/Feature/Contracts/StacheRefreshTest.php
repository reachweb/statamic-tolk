<?php

use Reach\StatamicTolk\Support\ContentWriter;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;

/*
 * Guards ContentWriter::refreshStache(), which ContentWriter runs after taking the write
 * lock; see docs/research/07-spike-findings.md "Content model" for the experiment.
 */

beforeEach(function () {
    $this->makeCollection('articles', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
    $this->origin = $this->makeEntry('articles', 'en', ['title' => 'Hello']);

    $this->store = Stache::store('entries::articles');
    Entry::query()->where('collection', 'articles')->where('slug', 'hello')->get();
    $this->store->keys();

    $cache = Stache::cacheStore();
    $this->written = fn () => [
        'path' => $this->store->paths()->has('other'),
        'slug index' => array_key_exists('other', $this->store->index('slug')->items()->all()),
        'index usage' => $this->store->indexUsage()->contains('late_index'),
        'keys' => array_key_exists('other', $this->store->keys()->all()),
    ];

    // Another process saves an entry and starts using a new index.
    $cache->forever('stache::indexes::entries::articles::path', [...$cache->get('stache::indexes::entries::articles::path'), 'other' => '/content/collections/articles/en/other.md']);
    $cache->forever('stache::indexes::entries::articles::slug', [...$cache->get('stache::indexes::entries::articles::slug'), 'other' => 'other']);
    $cache->forever('stache::indexes::entries::articles::_indexes', [...$cache->get('stache::indexes::entries::articles::_indexes'), 'late_index']);
    $cache->forever('stache::keys/entries', [...$cache->get('stache::keys/entries', []), 'other' => '/content/collections/articles/en/other.md']);
});

it('keeps paths, index items, index usage and keys in process memory', function () {
    expect(($this->written)())->toBe(['path' => false, 'slug index' => false, 'index usage' => false, 'keys' => false]);
});

it('reloads paths and index items, but not index usage and keys, with the reset core runs between queue jobs', function () {
    Stache::stores()->each->resetMemoizedState();
    app('stache.indexes')->each->resetMemoizedState();

    expect(($this->written)())->toBe(['path' => true, 'slug index' => true, 'index usage' => false, 'keys' => false]);
});

it('reloads everything with the full refresh', function () {
    ContentWriter::refreshStache();

    expect(($this->written)())->toBe(['path' => true, 'slug index' => true, 'index usage' => true, 'keys' => true]);
});

it('drops Blink caches such as the descendants behind existsIn()', function () {
    $localization = $this->localize($this->origin, 'el');
    Blink::put('entry-descendants-'.$this->origin->id(), collect());

    expect($this->origin->fresh()->existsIn('el'))->toBeFalse();

    ContentWriter::refreshStache();

    expect(Blink::has('entry-descendants-'.$this->origin->id()))->toBeFalse()
        ->and($this->origin->fresh()->in('el')->id())->toBe($localization->id());
});
