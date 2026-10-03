<?php

use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Targets\EntryFamilies;
use Statamic\Contracts\Entries\Entry as EntryContract;
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

function familySlugs(array $roots): array
{
    return array_map(fn (EntryContract $root) => $root->slug(), $roots);
}

/**
 * A structured `pages` collection with the English tree of the given shape, its entries created in the given order.
 */
function familyPages(array $shape, array $creationOrder): array
{
    test()->makeCollection('pages', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]], structured: true)
        ->structureContents(['root' => true, 'max_depth' => 3])
        ->save();

    $entries = [];

    foreach ($creationOrder as $slug) {
        $entries[$slug] = test()->makeEntry('pages', 'en', ['title' => ucfirst($slug)], $slug);
    }

    $branches = function (array $shape) use (&$branches, $entries): array {
        return collect($shape)->map(function ($children, $slug) use (&$branches, $entries) {
            [$slug, $children] = is_int($slug) ? [$children, []] : [$slug, $children];

            return array_filter(['entry' => $entries[$slug]->id(), 'children' => $branches($children) ?: null]);
        })->values()->all();
    };

    Collection::find('pages')->structure()->in('en')->tree($branches($shape))->save();

    return $entries;
}

describe('roots', function () {
    it('keeps the roots of a collection, in every site, and leaves localizations out', function () {
        $this->localize($this->hello, 'el', ['title' => 'Γεια σου']);

        expect(familySlugs(app(EntryFamilies::class)->roots(['articles'], [])))->toEqualCanonicalizing(['hello', 'world', 'geia']);
    });

    it('resolves an entry to the root of its family and names each family once', function () {
        $greek = $this->localize($this->hello, 'el', ['title' => 'Γεια σου']);
        $swiss = $this->localize($greek, 'de');

        $roots = app(EntryFamilies::class)->roots([], [$swiss->id(), $this->hello->id(), $greek->id()]);

        expect(familySlugs($roots))->toBe(['hello'])
            ->and($roots[0]->id())->toBe($this->hello->id());
    });

    it('groups the roots by collection in the order the collections were first named', function () {
        $roots = app(EntryFamilies::class)->roots(['news'], [$this->world->id(), $this->headline->id(), $this->hello->id()]);

        expect(familySlugs($roots))->toBe(['headline', 'world', 'hello']);
    });

    it('orders a structured collection by its tree, depth first, whatever the order the entries were created in', function () {
        familyPages(['home', 'rooms' => ['suites' => ['honeymoon']], 'dining'], ['honeymoon', 'dining', 'suites', 'rooms', 'home']);

        expect(Entry::query()->where('collection', 'pages')->get()->map->slug()->last())->toBe('home')
            ->and(familySlugs(app(EntryFamilies::class)->roots(['pages'], [])))->toBe(['home', 'rooms', 'suites', 'honeymoon', 'dining']);
    });

    it('puts roots that are in no tree after the others, in their own order', function () {
        familyPages(['home', 'rooms'], ['home', 'rooms']);
        $orphan = $this->makeEntry('pages', 'en', ['title' => 'Orphan'], 'orphan');
        $tree = Collection::find('pages')->structure()->in('en');
        $tree->tree(array_values(array_filter($tree->tree(), fn (array $branch) => $branch['entry'] !== $orphan->id())))->save();

        expect(familySlugs(app(EntryFamilies::class)->roots([], [$orphan->id(), Entry::query()->where('slug', 'rooms')->first()->id(), Entry::query()->where('slug', 'home')->first()->id()])))
            ->toBe(['home', 'rooms', 'orphan']);
    });

    it('throws for an unknown entry', function () {
        expect(fn () => app(EntryFamilies::class)->roots([], ['missing-id']))->toThrow(TargetNotFound::class, 'Entry [missing-id] not found.');
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
