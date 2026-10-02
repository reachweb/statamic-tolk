<?php

use Illuminate\Cache\FileStore;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Term;
use Statamic\Facades\User;

it('makes a collection, an entry and a localization', function () {
    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'body' => ['type' => 'textarea', 'localizable' => true],
    ]);

    $origin = $this->makeEntry('articles', 'en', ['title' => 'Hello world', 'body' => 'Body']);
    $localization = $this->localize($origin, 'el', ['title' => 'Γεια σου κόσμε']);

    $fresh = Entry::find($localization->id());

    expect(Blueprint::find('collections.articles.article')->fields()->all()->keys()->all())->toContain('title', 'body')
        ->and($origin->slug())->toBe('hello-world')
        ->and($fresh->locale())->toBe('el')
        ->and($fresh->origin()->id())->toBe($origin->id())
        ->and($fresh->slug())->toBe('hello-world')
        ->and($fresh->data()->all())->toBe(['title' => 'Γεια σου κόσμε'])
        ->and($fresh->value('body'))->toBe('Body');
});

it('makes a structured collection', function () {
    $collection = $this->makeCollection('pages', ['en', 'el'], ['title' => ['type' => 'text']], structured: true);
    $entry = $this->makeEntry('pages', 'en', ['title' => 'About'], 'about');

    expect($collection->hasStructure())->toBeTrue()
        ->and($collection->structure()->in('en')->flattenedPages()->map->id()->all())->toBe([$entry->id()]);
});

it('makes a taxonomy and a term', function () {
    $this->makeTaxonomy('topics', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
    $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food'], 'el' => ['title' => 'Φαγητό']]);

    $term = Term::find('topics::food');

    expect(Blueprint::find('taxonomies.topics.topic'))->not->toBeNull()
        ->and($term->in('en')->get('title'))->toBe('Food')
        ->and($term->in('el')->get('title'))->toBe('Φαγητό')
        ->and($term->in('el')->hasOrigin())->toBeTrue();
});

it('makes a global set with an origin per site', function () {
    $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en'], [
        'tagline' => ['type' => 'text', 'localizable' => true],
    ], [
        'en' => ['tagline' => 'By the sea'],
    ]);

    $set = GlobalSet::findByHandle('site_settings');

    expect(Blueprint::find('globals.site_settings'))->not->toBeNull()
        ->and($set->in('en')->get('tagline'))->toBe('By the sea')
        ->and($set->in('el')->origin()->locale())->toBe('en')
        ->and($set->in('el')->get('tagline'))->toBeNull()
        ->and($set->in('el')->value('tagline'))->toBe('By the sea');
});

it('makes a navigation with a tree per site', function () {
    $this->makeNav('main', [
        'en' => [['id' => 'home-en', 'title' => 'Home', 'url' => '/']],
        'el' => [['id' => 'home-el', 'title' => 'Αρχική', 'url' => '/el/']],
    ]);

    $nav = Nav::findByHandle('main');

    expect($nav->sites()->all())->toEqualCanonicalizing(['en', 'el'])
        ->and($nav->in('el')->tree()[0]['title'])->toBe('Αρχική');
});

it('signs in an admin, a user with permissions and a user without', function () {
    expect($this->signInAdmin()->isSuper())->toBeTrue()
        ->and(User::current()->isSuper())->toBeTrue();

    $editor = $this->signInWithPermissions(['access cp', 'access el site']);

    expect($editor->isSuper())->toBeFalse()
        ->and($editor->hasPermission('access cp'))->toBeTrue()
        ->and($editor->hasPermission('access el site'))->toBeTrue()
        ->and($editor->hasPermission('access de site'))->toBeFalse();

    $user = $this->signInUserWithoutPermissions();

    expect(User::current()->id())->toBe($user->id())
        ->and($user->hasPermission('access cp'))->toBeFalse();
});

it('switches to a file cache in the temporary directory', function () {
    $this->useFileCache();

    Cache::put('tolk-test', ['a' => 1], 60);

    expect(Cache::getStore())->toBeInstanceOf(FileStore::class)
        ->and(Cache::getStore()->getDirectory())->toStartWith($this->temporaryPath())
        ->and(Cache::get('tolk-test'))->toBe(['a' => 1])
        ->and(Cache::lock('tolk-test-lock', 10)->get())->toBeTrue()
        ->and(Cache::lock('tolk-test-lock', 10)->get())->toBeFalse();
});

it('blocks stray HTTP requests', function () {
    expect(fn () => Http::get('https://api.anthropic.com/v1/messages'))->toThrow(StrayRequestException::class);
});
