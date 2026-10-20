<?php

use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Targets\EntryFamilies;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

beforeEach(function () {
    $this->makeCollection('articles', ['en', 'el', 'de'], ['title' => ['type' => 'text', 'localizable' => true]]);
    $this->makeCollection('news', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);

    $this->hello = $this->makeEntry('articles', 'en', ['title' => 'Hello'], 'hello');
    $this->world = $this->makeEntry('articles', 'en', ['title' => 'World'], 'world');
    $this->greek = $this->makeEntry('articles', 'el', ['title' => 'Γεια'], 'geia');
    $this->headline = $this->makeEntry('news', 'en', ['title' => 'Headline'], 'headline');
});

function familySlugs(iterable $roots): array
{
    return array_map(fn (EntryContract $root) => $root->slug(), [...$roots]);
}

/**
 * The slugs of entries, by id.
 *
 * @param  list<string>  $ids
 */
function slugsOf(array $ids): array
{
    return array_map(fn (string $id) => Entry::find($id)->slug(), $ids);
}

describe('roots', function () {
    it('keeps the roots of a collection, in every site, and leaves localizations out', function () {
        $this->localize($this->hello, 'el', ['title' => 'Γεια σου']);

        expect(familySlugs(app(EntryFamilies::class)->roots(['articles'], [])))->toEqualCanonicalizing(['hello', 'world', 'geia']);
    });

    it('resolves an entry to the root of its family and names each family once', function () {
        $greek = $this->localize($this->hello, 'el', ['title' => 'Γεια σου']);
        $swiss = $this->localize($greek, 'de');

        $roots = [...app(EntryFamilies::class)->roots([], [$swiss->id(), $this->hello->id(), $greek->id()])];

        expect(familySlugs($roots))->toBe(['hello'])
            ->and($roots[0]->id())->toBe($this->hello->id());
    });

    it('groups the roots by collection in the order the collections were first named', function () {
        $roots = app(EntryFamilies::class)->roots(['news'], [$this->world->id(), $this->headline->id(), $this->hello->id()]);

        expect(familySlugs($roots))->toBe(['headline', 'world', 'hello']);
    });

    it('orders a structured collection by its tree, depth first, whatever the order the entries were created in', function () {
        $this->makePages(['home', 'rooms' => ['suites' => ['honeymoon']], 'dining'], creationOrder: ['honeymoon', 'dining', 'suites', 'rooms', 'home']);

        expect(Entry::query()->where('collection', 'pages')->get()->map->slug()->last())->toBe('home')
            ->and(familySlugs(app(EntryFamilies::class)->roots(['pages'], [])))->toBe(['home', 'rooms', 'suites', 'honeymoon', 'dining']);
    });

    it('puts roots that are in no tree after the others, in their own order', function () {
        $this->makePages(['home', 'rooms']);
        $orphan = $this->makeEntry('pages', 'en', ['title' => 'Orphan'], 'orphan');
        $tree = Collection::find('pages')->structure()->in('en');
        $tree->tree(array_values(array_filter($tree->tree(), fn (array $branch) => $branch['entry'] !== $orphan->id())))->save();

        expect(familySlugs(app(EntryFamilies::class)->roots([], [$orphan->id(), Entry::query()->where('slug', 'rooms')->first()->id(), Entry::query()->where('slug', 'home')->first()->id()])))
            ->toBe(['home', 'rooms', 'orphan']);
    });

    it('throws for an unknown entry', function () {
        expect(fn () => app(EntryFamilies::class)->roots([], ['missing-id']))->toThrow(TargetNotFound::class, 'Entry [missing-id] not found.');
    });

    it('loads the roots a chunk at a time, as the caller reads them', function () {
        $this->useFileCache();

        foreach (range(1, EntryFamilies::CHUNK) as $number) {
            $this->makeEntry('articles', 'en', ['title' => "More {$number}"], "more-{$number}");
        }

        $families = app(EntryFamilies::class);
        $ids = array_map(fn (EntryContract $root) => (string) $root->id(), [...$families->roots(['articles'], [])]);
        $titles = [];

        foreach ($families->roots(['articles'], []) as $position => $root) {
            if ($position === 0) {
                foreach ([$ids[1], $ids[EntryFamilies::CHUNK]] as $id) {
                    Entry::find($id)->set('title', 'Changed meanwhile')->save();
                }
            }

            $titles[] = $root->get('title');
        }

        expect(count($titles))->toBe(EntryFamilies::CHUNK + 3)
            ->and($titles[1])->not->toBe('Changed meanwhile')
            ->and($titles[EntryFamilies::CHUNK])->toBe('Changed meanwhile');
    });

    it('forgets what core memoized for the entries of a chunk before it loads the next, and keeps what was memoized before', function () {
        foreach (range(1, EntryFamilies::CHUNK) as $number) {
            $this->localize($this->makeEntry('articles', 'en', ['title' => "More {$number}"], "more-{$number}"), 'el');
        }

        Blink::flush();
        Blink::put('origin-Entry-earlier', 'kept');
        $memoized = [];
        $leftOver = null;

        foreach (app(EntryFamilies::class)->roots(['articles'], []) as $position => $root) {
            if ($position === EntryFamilies::CHUNK) {
                $leftOver = array_values(array_filter($memoized, fn (string $key) => Blink::has($key)));
            }

            // An entry's blueprint and localizations, and a localization's origin, as building a target reads them.
            $root->blueprint();
            $localization = $root->in('el');
            $localization?->origin();

            if ($position < EntryFamilies::CHUNK) {
                $keys = ["entry-{$root->id()}-blueprint", "entry-descendants-{$root->id()}", "entry-all-descendants-{$root->id()}", 'origin-Entry-'.$localization?->id()];
                $memoized = [...$memoized, ...array_filter($keys, fn (string $key) => Blink::has($key))];
            }
        }

        expect(count($memoized))->toBeGreaterThan(2 * EntryFamilies::CHUNK)
            ->and($leftOver)->toBe([])
            ->and(Blink::get('origin-Entry-earlier'))->toBe('kept');
    });
});

describe('rootIds', function () {
    it('lists the ids of the roots in the order of roots(), without loading the collections\' entries', function () {
        $this->makePages(['home', 'rooms' => ['suites'], 'dining'], creationOrder: ['suites', 'dining', 'rooms', 'home']);
        $suites = Entry::query()->where('slug', 'suites')->first();
        $families = app(EntryFamilies::class);

        expect(slugsOf($families->rootIds(['pages', 'news'], [$this->greek->id(), $suites->id()])))->toBe(['home', 'rooms', 'suites', 'dining', 'headline', 'geia'])
            ->and($families->rootIds(['pages', 'news'], [$this->greek->id()]))->toBe(array_map(fn (EntryContract $root) => (string) $root->id(), [...$families->roots(['pages', 'news'], [$this->greek->id()])]));
    });
});

describe('rootIdsOutside', function () {
    it('lists the ids of the roots in other sites than the given one, in the order of roots()', function (string $site) {
        $this->localize($this->hello, 'el', ['title' => 'Γεια σου']);
        $families = app(EntryFamilies::class);
        $roots = array_filter([...$families->roots(['articles'], [])], fn (EntryContract $root) => $root->locale() !== $site);

        expect($families->rootIdsOutside(Collection::find('articles'), $site))->toBe(array_values(array_map(fn (EntryContract $root) => $root->id(), $roots)));
    })->with(['en', 'el', 'de']);

    it('follows a structured collection\'s tree, with the roots in no tree after the others', function () {
        $pages = $this->makePages(['home', 'rooms' => ['suites'], 'dining'], creationOrder: ['suites', 'dining', 'rooms', 'home']);
        $orphan = $this->makeEntry('pages', 'en', ['title' => 'Orphan'], 'orphan');
        $tree = Collection::find('pages')->structure()->in('en');
        $tree->tree(array_values(array_filter($tree->tree(), fn (array $branch) => $branch['entry'] !== $orphan->id())))->save();

        expect(app(EntryFamilies::class)->rootIdsOutside(Collection::find('pages'), 'el'))
            ->toBe([...array_map(fn (string $slug) => $pages[$slug]->id(), ['home', 'rooms', 'suites', 'dining']), $orphan->id()]);
    });
});

describe('targets', function () {
    it('pairs every root with each site of its collection but its own, family by family', function () {
        $families = app(EntryFamilies::class);

        $targets = $families->targets([$this->hello, $this->greek, $this->headline], ['el', 'en', 'de']);

        expect($targets)->toBe([
            ['type' => 'entry', 'source_id' => $this->hello->id(), 'site' => 'el'],
            ['type' => 'entry', 'source_id' => $this->hello->id(), 'site' => 'de'],
            ['type' => 'entry', 'source_id' => $this->greek->id(), 'site' => 'en'],
            ['type' => 'entry', 'source_id' => $this->greek->id(), 'site' => 'de'],
            ['type' => 'entry', 'source_id' => $this->headline->id(), 'site' => 'el'],
        ]);
    });
});
