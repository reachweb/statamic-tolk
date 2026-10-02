<?php

use Illuminate\Support\Facades\Event;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Events\EntryCreated;
use Statamic\Events\EntryCreating;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;

function pagesWithTree(array $shape, bool $root): array
{
    $collection = test()->makeCollection('pages', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
    ], structured: true);

    $collection->structureContents(['root' => $root, 'max_depth' => 3])->save();

    $entries = [];
    $branches = function (array $shape) use (&$branches, &$entries): array {
        return collect($shape)->map(function ($children, $slug) use (&$branches, &$entries) {
            [$slug, $children] = is_int($slug) ? [$children, []] : [$slug, $children];
            $entries[$slug] = test()->makeEntry('pages', 'en', ['title' => ucfirst($slug)], $slug);

            return array_filter(['entry' => $entries[$slug]->id(), 'children' => $branches($children) ?: null]);
        })->values()->all();
    };

    $tree = $branches($shape);
    Collection::find('pages')->structure()->in('en')->tree($tree)->save();

    return $entries;
}

function treeOfSlugs(string $site): array
{
    $slugs = function (array $branches) use (&$slugs): array {
        return collect($branches)->mapWithKeys(fn (array $branch) => [
            Entry::find($branch['entry'])->slug() => isset($branch['children']) ? $slugs($branch['children']) : [],
        ])->all();
    };

    return $slugs(Collection::find('pages')->structure()->in($site)->tree());
}

function localizeFresh(EntryContract $origin, string $site): EntryContract
{
    return test()->localize($origin->fresh(), $site);
}

describe('tree placement', function () {
    it('places a localization under its localized parent', function () {
        $pages = pagesWithTree(['about', 'rooms' => ['suites']], root: false);

        localizeFresh($pages['rooms'], 'el');
        localizeFresh($pages['suites'], 'el');

        expect(treeOfSlugs('el'))->toBe(['rooms' => ['suites' => []]]);
    });

    it('places a child localized before its parent at the top level and leaves it there', function () {
        $pages = pagesWithTree(['about', 'rooms' => ['suites']], root: false);

        localizeFresh($pages['suites'], 'el');
        localizeFresh($pages['rooms'], 'el');

        expect(treeOfSlugs('el'))->toBe(['suites' => [], 'rooms' => []]);
    });

    it('makes the first localization saved into an empty tree the home page when the structure expects a root', function () {
        $pages = pagesWithTree(['home', 'rooms' => ['suites' => ['honeymoon']]], root: true);

        localizeFresh($pages['rooms'], 'el');
        localizeFresh($pages['suites'], 'el');
        localizeFresh($pages['honeymoon'], 'el');
        $home = localizeFresh($pages['home'], 'el');

        $tree = Collection::find('pages')->structure()->in('el');

        expect(treeOfSlugs('el'))->toBe(['rooms' => [], 'suites' => ['honeymoon' => []], 'home' => []])
            ->and($tree->root()['entry'])->toBe($pages['rooms']->in('el')->id())
            ->and($home->fresh()->uri())->toBe('/home')
            ->and($pages['rooms']->in('el')->fresh()->uri())->toBe('/');
    });

    it('keeps the origin tree when the root is localized first', function () {
        $pages = pagesWithTree(['home', 'rooms' => ['suites' => ['honeymoon']]], root: true);

        foreach (['home', 'rooms', 'suites', 'honeymoon'] as $slug) {
            localizeFresh($pages[$slug], 'de');
        }

        expect(treeOfSlugs('de'))->toBe(['home' => [], 'rooms' => ['suites' => ['honeymoon' => []]]])
            ->and(treeOfSlugs('de'))->toBe(treeOfSlugs('en'));
    });
});

describe('data', function () {
    beforeEach(function () {
        $this->makeCollection('articles', ['en', 'el'], [
            'title' => ['type' => 'text', 'localizable' => true],
            'intro' => ['type' => 'textarea', 'localizable' => true],
            'note' => ['type' => 'text', 'localizable' => true],
        ]);
    });

    it('holds only the overridden keys in a localization, keeps an explicit null and never falls back in get()', function () {
        $origin = $this->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'Intro', 'note' => 'Note']);
        $localization = $this->localize($origin, 'el', ['title' => 'Γεια', 'intro' => null]);

        $fresh = $localization->fresh();

        expect($fresh->data()->all())->toBe(['title' => 'Γεια', 'intro' => null])
            ->and($fresh->has('intro'))->toBeTrue()
            ->and($fresh->get('intro'))->toBeNull()
            ->and($fresh->value('intro'))->toBeNull()
            ->and($fresh->has('note'))->toBeFalse()
            ->and($fresh->get('note'))->toBeNull()
            ->and($fresh->value('note'))->toBe('Note')
            ->and($fresh->fileData())->toHaveKey('intro')
            ->and($fresh->fileData()['intro'])->toBeNull();
    });

    it('drops null, empty strings and empty arrays from a root entry file but keeps them in a localization file', function () {
        $origin = $this->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => '', 'note' => null]);
        $localization = $this->localize($origin, 'el', ['intro' => '', 'note' => []]);

        expect($origin->fileData())->not->toHaveKeys(['intro', 'note'])
            ->and($localization->fileData())->toHaveKeys(['intro', 'note']);
    });

    it('re-saves the direct localizations when the origin is saved, and they are not the initial save', function () {
        $origin = $this->makeEntry('articles', 'en', ['title' => 'Hello']);
        $el = $this->localize($origin, 'el', ['title' => 'Γεια']);

        $saved = [];
        Event::listen(EntrySaved::class, function (EntrySaved $event) use (&$saved) {
            $saved[] = [$event->entry->locale(), $event->isInitial()];
        });

        $origin->fresh()->set('intro', 'Changed')->save();

        expect($saved)->toBe([['el', false], ['en', true]])
            ->and($el->fresh()->data()->all())->toBe(['title' => 'Γεια']);
    });
});

describe('unsaved localizations', function () {
    beforeEach(function () {
        $this->makeCollection('pages', ['en', 'el', 'de'], [
            'title' => ['type' => 'text', 'localizable' => true],
            'page_only' => ['type' => 'text'],
        ]);
        $this->makeCollection('articles', ['en', 'el', 'de'], [
            'title' => ['type' => 'text', 'localizable' => true],
            'article_only' => ['type' => 'text'],
        ]);
    });

    it('has no id and reads its blueprint from a Blink key that another unsaved entry can have filled', function () {
        $page = $this->makeEntry('pages', 'en', ['title' => 'Page']);
        $article = $this->makeEntry('articles', 'en', ['title' => 'Article']);

        $localization = $article->fresh()->makeLocalization('el');

        // Before 6.35.0 every unsaved entry uses `entry--blueprint`. From 6.35.0 the
        // key holds the object id, which PHP gives to a new object once the old one
        // is freed: this stands in for a freed unsaved page.
        $pageBlueprint = $page->fresh()->blueprint();
        Blink::put('entry--blueprint', $pageBlueprint);
        Blink::put('entry-obj-'.spl_object_id($localization).'-blueprint', $pageBlueprint);

        expect($localization->id())->toBeNull()
            ->and($localization->blueprint()->namespace())->toBe('collections.pages');

        $localization->id(Stache::generateId());

        expect($localization->blueprint()->namespace())->toBe('collections.articles');
    });

    it('shares one origin Blink key between all unsaved entries', function () {
        $page = $this->makeEntry('pages', 'en', ['title' => 'Page']);
        $article = $this->makeEntry('articles', 'en', ['title' => 'Article']);

        $pageLocalization = $page->fresh()->makeLocalization('el');
        $articleLocalization = $article->fresh()->makeLocalization('el');

        expect($pageLocalization->origin()->id())->toBe($page->id())
            ->and($articleLocalization->origin()->id())->toBe($page->id())
            ->and($articleLocalization->value('title'))->toBe('Page');

        $pageLocalization = $page->fresh()->makeLocalization('de')->id(Stache::generateId());
        $articleLocalization = $article->fresh()->makeLocalization('de')->id(Stache::generateId());

        expect($pageLocalization->origin()->id())->toBe($page->id())
            ->and($articleLocalization->origin()->id())->toBe($article->id())
            ->and($articleLocalization->value('title'))->toBe('Article');
    });

    it('resolves its own blueprint and origin everywhere once an id is assigned right after makeLocalization()', function () {
        $origins = collect(range(1, 6))->flatMap(fn (int $i) => [
            $this->makeEntry('pages', 'en', ['title' => "Page {$i}"]),
            $this->makeEntry('articles', 'en', ['title' => "Article {$i}"]),
        ]);

        $seen = [];
        $record = function (string $moment, EntryContract $entry) use (&$seen) {
            $seen[] = [$moment, $entry->collectionHandle(), $entry->blueprint()->namespace(), $entry->origin()->collectionHandle()];
        };
        Event::listen(EntryCreating::class, fn (EntryCreating $event) => $record('creating', $event->entry));
        Event::listen(EntrySaving::class, fn (EntrySaving $event) => $record('saving', $event->entry));

        foreach (['el', 'de'] as $site) {
            foreach ($origins as $origin) {
                $discarded = $origin->fresh()->makeLocalization($site)->id(Stache::generateId());
                $record('discarded', $discarded);
                unset($discarded);

                $localization = $origin->fresh()->makeLocalization($site)->id(Stache::generateId());
                $record('before save', $localization);
                $localization->save();
                unset($localization);
            }
        }

        expect($seen)->toHaveCount(96);

        foreach ($seen as [$moment, $collection, $blueprint, $originCollection]) {
            expect("{$moment}: {$blueprint}, origin in {$originCollection}")
                ->toBe("{$moment}: collections.{$collection}, origin in {$collection}");
        }
    });

    it('saves a localization with an id assigned in advance as a new entry and places it in the tree', function () {
        $pages = pagesWithTree(['about', 'rooms' => ['suites']], root: false);
        localizeFresh($pages['rooms'], 'el');

        $created = [];
        Event::listen(EntryCreated::class, function (EntryCreated $event) use (&$created) {
            $created[] = $event->entry->id();
        });

        $localization = $pages['suites']->fresh()->makeLocalization('el');
        $localization->id($id = Stache::generateId());

        expect($localization->save())->toBeTrue()
            ->and($localization->id())->toBe($id)
            ->and($created)->toBe([$id])
            ->and(Entry::find($id)->origin()->id())->toBe($pages['suites']->id())
            ->and(treeOfSlugs('el'))->toBe(['rooms' => ['suites' => []]]);
    });
});
