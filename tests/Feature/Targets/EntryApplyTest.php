<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Exceptions\ApplyFailed;
use Reach\StatamicTolk\Exceptions\RootMissing;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\ApplyResult;
use Reach\StatamicTolk\Targets\EntryTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Tests\Support\ContentFixtures;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Translation\AssembledField;
use Reach\StatamicTolk\Translation\Pipeline;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\PlanStore;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Events\EntryCreating;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Git\CommitJob;
use Statamic\Git\Subscriber;
use Statamic\Support\Arr;

beforeEach(function () {
    $this->useFileCache();
    $this->fakeTranslations();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true],
        'body' => ['type' => 'textarea', 'localizable' => true],
        'note' => ['type' => 'text'],
        'featured' => ['type' => 'toggle', 'localizable' => true],
    ]);

    $this->origin = $this->makeEntry('articles', 'en', [
        'title' => 'Hello',
        'intro' => 'A short intro.',
        'body' => 'The body.',
        'note' => 'Internal',
        'featured' => true,
    ], 'hello');
});

function applyTarget(EntryContract|string $source, string $site = 'el'): EntryTarget
{
    return app(TargetFactory::class)->forEntry($source, $site);
}

/**
 * Plans the target, runs every unit and assembles every planned field from a fresh target.
 *
 * @return list<AssembledField>
 */
function applyAssembled(EntryContract|string $source, string $site = 'el', array $options = []): array
{
    $target = applyTarget($source, $site);
    $plan = app(Planner::class)->plan($target, PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), $options));
    app(PlanStore::class)->put($plan);
    $pipeline = app(Pipeline::class);

    while (($runnable = $pipeline->runnable($plan)) !== []) {
        $pipeline->runUnit($plan, array_key_first($runnable));
    }

    $fresh = $target->fresh();

    return array_map(fn (string $handle) => $pipeline->assemble($plan, $fresh, $handle), $plan->fieldHandles());
}

function applyInWriter(EntryContract|string $source, array $fields, ApplyOptions $options = new ApplyOptions, string $site = 'el'): ApplyResult
{
    return ContentWriter::write(fn () => applyTarget($source, $site)->apply($fields, $options));
}

function translateInto(EntryContract|string $source, string $site = 'el', ApplyOptions $options = new ApplyOptions, array $plan = []): ApplyResult
{
    return applyInWriter($source, applyAssembled($source, $site, $plan), $options, $site);
}

describe('creating a localization', function () {
    it('creates a draft whose own data holds the applied fields and no other', function () {
        $result = translateInto($this->origin);

        $localization = $this->origin->fresh()->in('el');

        expect($result->created)->toBeTrue()
            ->and($result->ref)->toEqual(new TargetRef(ContentType::Entry, $localization->id()))
            ->and($result->applied)->toBe(['title', 'intro', 'body'])
            ->and($result->skipped)->toBe([])
            ->and($result->warnings)->toBe([])
            ->and($localization->data()->all())->toBe(['title' => '[T] Hello', 'intro' => '[T] A short intro.', 'body' => '[T] The body.'])
            ->and($localization->published())->toBeFalse()
            ->and($localization->origin()->id())->toBe($this->origin->id())
            ->and(StateFixtures::statuses($this->origin))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current, 'body' => FieldStatus::Current]);

        $state = app(StateRepository::class)->get($result->ref);

        expect($state->hasPending())->toBeFalse()
            ->and($state->sourceSite)->toBe('en')
            ->and($state->field('title')->targetHash)->toBe(Fingerprint::ofLeaves(['[T] Hello']))
            ->and($state->field('title')->translator)->toBe('anthropic:claude-sonnet-5-5');
    });

    it('adds updated_at and updated_by with a user', function () {
        $user = $this->signInAdmin();

        translateInto($this->origin, options: new ApplyOptions(user: $user));

        $localization = $this->origin->fresh()->in('el');

        expect(array_keys($localization->data()->all()))->toEqualCanonicalizing(['title', 'intro', 'body', 'updated_at', 'updated_by'])
            ->and($localization->get('updated_by'))->toBe($user->id());
    });

    it('mirrors the origin\'s published state with mirror', function () {
        translateInto($this->origin, 'el', new ApplyOptions(newStatus: 'mirror'));

        $draft = $this->makeEntry('articles', 'en', ['title' => 'Draft'], 'draft');
        $draft->published(false)->save();
        translateInto($draft, 'el', new ApplyOptions(newStatus: 'mirror'));

        expect($this->origin->fresh()->in('el')->published())->toBeTrue()
            ->and($draft->fresh()->in('el')->published())->toBeFalse();
    });

    it('mirrors for a user who may publish, and stays a draft for one who may not', function () {
        $editor = $this->signInWithPermissions(['access el site', 'access de site', 'view articles entries', 'edit articles entries']);
        translateInto($this->origin, 'el', new ApplyOptions(user: $editor, newStatus: 'mirror'));

        $publisher = $this->signInWithPermissions(['access el site', 'access de site', 'view articles entries', 'edit articles entries', 'publish articles entries']);
        translateInto($this->origin, 'de', new ApplyOptions(user: $publisher, newStatus: 'mirror'));

        expect($this->origin->fresh()->in('el')->published())->toBeFalse()
            ->and($this->origin->fresh()->in('de')->published())->toBeTrue();
    });

    it('stays a draft with mirror when a field is incomplete', function () {
        $this->fakeTranslations(fn (string $text) => $text === 'A short intro.' ? null : '[T] '.$text);

        $result = translateInto($this->origin, 'el', new ApplyOptions(newStatus: 'mirror'));

        $localization = $this->origin->fresh()->in('el');

        expect($result->applied)->toBe(['title', 'intro', 'body'])
            ->and($localization->get('intro'))->toBe('A short intro.')
            ->and($localization->published())->toBeFalse()
            ->and(StateFixtures::statuses($this->origin)['intro'])->toBe(FieldStatus::Outdated);
    });

    it('stays a draft with mirror when a field with source text was not delivered', function () {
        $fields = array_values(array_filter(applyAssembled($this->origin), fn (AssembledField $field) => $field->handle !== 'body'));

        applyInWriter($this->origin, $fields, new ApplyOptions(newStatus: 'mirror'));

        expect($this->origin->fresh()->in('el')->published())->toBeFalse()
            ->and(StateFixtures::statuses($this->origin)['body'])->toBe(FieldStatus::Untranslated);
    });

    it('creates the localization of a localization from that source', function () {
        $el = $this->localize($this->origin, 'el', ['title' => 'Γεια']);

        translateInto($el->fresh(), 'de');

        $de = $this->origin->fresh()->in('de');

        expect($de->origin()->id())->toBe($el->id())
            ->and($de->data()->all())->toBe(['title' => '[T] Γεια']);
    });
});

describe('slugs', function () {
    it('keeps the origin\'s slug although the title is translated', function () {
        $result = translateInto($this->origin);

        expect($result->slug)->toBe('hello')
            ->and($this->origin->fresh()->in('el')->slug())->toBe('hello');
    });

    it('adds a suffix and a warning when another entry in the target site has the slug', function () {
        $this->makeEntry('articles', 'el', ['title' => 'Άλλο'], 'hello');
        $this->makeEntry('articles', 'el', ['title' => 'Τρίτο'], 'hello-2');
        $this->makeEntry('articles', 'de', ['title' => 'Andere'], 'other');

        $el = translateInto($this->origin);
        $de = translateInto($this->origin, 'de');

        expect($el->slug)->toBe('hello-3')
            ->and($this->origin->fresh()->in('el')->slug())->toBe('hello-3')
            ->and($el->warnings)->toBe(['The slug "hello" is already used in Greek, so the new localization got "hello-3".'])
            ->and($de->slug)->toBe('hello')
            ->and($de->warnings)->toBe([]);
    });

    it('keeps the slug and published state of an existing localization', function () {
        $localization = $this->localize($this->origin, 'el')->slug('geia')->published(false);
        $localization->save();
        $published = $this->localize($this->origin->fresh(), 'de')->slug('hallo');
        $published->save();

        $el = translateInto($this->origin, 'el', new ApplyOptions(newStatus: 'mirror'));
        $de = translateInto($this->origin, 'de', new ApplyOptions(newStatus: 'mirror'));

        expect($el->created)->toBeFalse()
            ->and($el->slug)->toBeNull()
            ->and($localization->fresh()->slug())->toBe('geia')
            ->and($localization->fresh()->published())->toBeFalse()
            ->and($localization->fresh()->get('title'))->toBe('[T] Hello')
            ->and($de->created)->toBeFalse()
            ->and($published->fresh()->slug())->toBe('hallo')
            ->and($published->fresh()->published())->toBeTrue();
    });

    describe('in a collection with translated slugs', function () {
        beforeEach(function () {
            $this->withSettings(['translated_slugs' => ['articles']]);
            $this->fakeTranslations(fn (string $text) => $text === 'Hello' ? 'Γεια σου κόσμε' : '[T] '.$text);
        });

        it('gives a new localization the slug of its translated title, as the CP form makes it', function () {
            $result = translateInto($this->origin);

            $localization = $this->origin->fresh()->in('el');

            expect($result->slug)->toBe('gia-su-kosme')
                ->and($result->warnings)->toBe([])
                ->and($localization->slug())->toBe('gia-su-kosme')
                ->and($localization->get('title'))->toBe('Γεια σου κόσμε')
                ->and(Entry::query()->where('collection', 'articles')->where('site', 'el')->count())->toBe(1)
                ->and(glob(dirname($localization->path()).'/*'))->toBe([$localization->path()])
                ->and(StateFixtures::statuses($this->origin)['title'])->toBe(FieldStatus::Current);
        });

        it('keeps the origin\'s slug in a collection that is not listed', function () {
            $this->makeCollection('news', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
            $news = $this->makeEntry('news', 'en', ['title' => 'Hello'], 'hello');

            expect(translateInto($news)->slug)->toBe('hello');
        });

        it('adds a suffix and one warning when another entry in the target site has the translated slug', function () {
            $this->makeEntry('articles', 'el', ['title' => 'Άλλο'], 'hello');
            $this->makeEntry('articles', 'el', ['title' => 'Τρίτο'], 'gia-su-kosme');

            $result = translateInto($this->origin);

            expect($result->slug)->toBe('gia-su-kosme-2')
                ->and($this->origin->fresh()->in('el')->slug())->toBe('gia-su-kosme-2')
                ->and($result->warnings)->toBe(['The slug "gia-su-kosme" is already used in Greek, so the new localization got "gia-su-kosme-2".']);
        });

        it('keeps the origin\'s slug while the title is not translated', function () {
            $this->withSettings(['translated_slugs' => ['articles'], 'excluded_fields' => ['title']]);
            $this->makeEntry('articles', 'el', ['title' => 'Άλλο'], 'hello');

            $result = translateInto($this->origin);

            expect($result->applied)->toBe(['intro', 'body'])
                ->and($result->slug)->toBe('hello-2')
                ->and($result->warnings)->toBe(['The slug "hello" is already used in Greek, so the new localization got "hello-2".']);
        });

        it('keeps the origin\'s slug, with a warning, when the title was not translated completely', function () {
            $this->fakeTranslations(fn (string $text) => $text === 'Hello' ? null : '[T] '.$text);

            $result = translateInto($this->origin);

            expect($result->slug)->toBe('hello')
                ->and($this->origin->fresh()->in('el')->get('title'))->toBe('Hello')
                ->and($result->warnings)->toContain('The title was not translated completely, so the new localization kept the slug of its origin.');
        });

        it('makes the slug from the generated title in a collection that generates its titles', function () {
            Collection::find('articles')->titleFormats('{intro}')->save();

            $result = translateInto($this->origin);

            expect($result->slug)->toBe('t-a-short-intro')
                ->and($this->origin->fresh()->in('el')->slug())->toBe('t-a-short-intro');
        });

        it('gives the slug to a localization created with revisions and published as its origin', function () {
            Collection::find('articles')->revisionsEnabled(true)->save();
            config(['statamic.revisions.enabled' => true]);
            $this->fakeTranslations(fn (string $text) => $text === 'Hello' ? 'Hallo Welt' : '[T] '.$text);

            $result = translateInto($this->origin, 'de', new ApplyOptions(newStatus: 'mirror'));

            $localization = $this->origin->fresh()->in('de');

            expect($result->slug)->toBe('hallo-welt')
                ->and($localization->slug())->toBe('hallo-welt')
                ->and($localization->published())->toBeTrue()
                ->and(Entry::query()->where('collection', 'articles')->where('site', 'de')->count())->toBe(1)
                ->and(glob(dirname($localization->path()).'/*'))->toBe([$localization->path()]);
        });

        it('never changes the slug of an existing localization', function () {
            $localization = $this->localize($this->origin, 'el');

            $result = translateInto($this->origin);

            expect($result->created)->toBeFalse()
                ->and($result->slug)->toBeNull()
                ->and($localization->fresh()->slug())->toBe('hello')
                ->and($localization->fresh()->get('title'))->toBe('Γεια σου κόσμε');
        });
    });
});

describe('trees', function () {
    it('places a child under its localized parent', function () {
        $pages = $this->makePages(['about', 'rooms' => ['suites']], root: false);

        translateInto($pages['rooms']->id());
        translateInto($pages['suites']->id());

        expect($this->treeOfSlugs('el'))->toBe(['rooms' => ['suites' => []]]);
    });

    it('refuses any entry but the origin root into an empty tree of a collection with a root, and saves nothing', function () {
        $pages = $this->makePages(['home', 'rooms' => ['suites']], root: true);
        $fields = applyAssembled($pages['rooms']->id());

        expect(applyTarget($pages['rooms']->id())->rootMissing())->toBeTrue()
            ->and(applyTarget($pages['home']->id())->rootMissing())->toBeFalse()
            ->and(fn () => applyInWriter($pages['rooms']->id(), $fields))->toThrow(RootMissing::class, 'Translate Home into Greek first.')
            ->and(Entry::query()->where('collection', 'pages')->where('site', 'el')->count())->toBe(0)
            ->and($this->treeOfSlugs('el'))->toBe([]);
    });

    it('gives the target the origin\'s tree when the root is applied first', function () {
        $pages = $this->makePages(['home', 'rooms' => ['suites' => ['honeymoon']]], root: true);

        foreach (['home', 'rooms', 'suites', 'honeymoon'] as $slug) {
            translateInto($pages[$slug]->id());
        }

        expect($this->treeOfSlugs('el'))->toBe($this->treeOfSlugs('en'))
            ->and($this->treeOfSlugs('el'))->toBe(['home' => [], 'rooms' => ['suites' => ['honeymoon' => []]]])
            ->and(applyTarget($pages['rooms']->id(), 'de')->rootMissing())->toBeTrue()
            ->and(applyTarget($pages['rooms']->id())->rootMissing())->toBeFalse();
    });

    it('places pages created out of tree order at their origin\'s position among their siblings', function () {
        $pages = $this->makePages(['home', 'about', 'rooms' => ['suites', 'villas'], 'dining'], root: true);

        // The order in which parallel lanes finish, or an editor translates pages over the weeks.
        foreach (['home', 'rooms', 'dining', 'villas', 'about', 'suites'] as $slug) {
            translateInto($pages[$slug]->id());
        }

        expect($this->treeOfSlugs('el'))->toBe(['home' => [], 'about' => [], 'rooms' => ['suites' => [], 'villas' => []], 'dining' => []]);
    });

    it('places a page translated later between its localized siblings, not at the end', function () {
        $pages = $this->makePages(['home', 'about', 'rooms', 'dining'], root: true);

        foreach (['home', 'about', 'dining', 'rooms'] as $slug) {
            translateInto($pages[$slug]->id());
        }

        expect($this->treeOfSlugs('el'))->toBe(['home' => [], 'about' => [], 'rooms' => [], 'dining' => []]);
    });

    it('keeps the order the editors gave the target tree and places a new page after its nearest localized preceding sibling', function () {
        $pages = $this->makePages(['home', 'about', 'rooms', 'dining', 'spa'], root: true);

        foreach (['home', 'about', 'dining', 'spa'] as $slug) {
            translateInto($pages[$slug]->id());
        }

        $tree = Collection::find('pages')->structure()->in('el');
        [$home, $about, $dining, $spa] = $tree->tree();
        $tree->tree([$home, $dining, $about, $spa])->save();

        translateInto($pages['rooms']->id());

        expect($this->treeOfSlugs('el'))->toBe(['home' => [], 'dining' => [], 'about' => [], 'rooms' => [], 'spa' => []]);
    });

    it('places a page before its nearest localized following sibling when no preceding one is localized', function () {
        $pages = $this->makePages(['about', 'rooms', 'dining'], root: false);

        foreach (['dining', 'rooms', 'about'] as $slug) {
            translateInto($pages[$slug]->id());
        }

        expect($this->treeOfSlugs('el'))->toBe(['about' => [], 'rooms' => [], 'dining' => []]);
    });

    it('places a page translated from a localization among its siblings\' pages in the target site, whichever entry those were translated from', function () {
        $pages = $this->makePages(['about', 'rooms', 'dining'], root: false);

        foreach (['about', 'rooms', 'dining'] as $slug) {
            translateInto($pages[$slug]->id());
        }

        $greek = fn (string $slug) => Entry::query()->where('collection', 'pages')->where('site', 'el')->where('origin', $pages[$slug]->id())->first()->id();
        translateInto($pages['dining']->id(), 'de');
        translateInto($greek('rooms'), 'de');
        translateInto($greek('about'), 'de');

        expect($this->treeOfSlugs('de'))->toBe(['about' => [], 'rooms' => [], 'dining' => []])
            ->and(Entry::query()->where('collection', 'pages')->where('site', 'de')->get()->mapWithKeys(fn ($entry) => [$entry->slug() => $entry->origin()->locale()])->sortKeys()->all())
            ->toBe(['about' => 'el', 'dining' => 'en', 'rooms' => 'el']);
    });

    it('never places a page before the home page of the target tree', function () {
        $pages = $this->makePages(['home', 'about', 'rooms'], root: true);
        // Core's localize action made Rooms the Greek home page, as the first page saved into the empty tree.
        ContentWriter::write(fn () => $this->localize($pages['rooms'], 'el'));

        translateInto($pages['about']->id());

        expect($this->treeOfSlugs('el'))->toBe(['rooms' => [], 'about' => []]);
    });

    it('places the entries of an orderable collection at their origin\'s position too', function () {
        $this->makeCollection('team', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]], structured: true)
            ->structureContents(['root' => false, 'max_depth' => 1])
            ->save();
        $members = collect(['anna', 'bob', 'chris', 'dora'])->mapWithKeys(fn (string $slug) => [$slug => $this->makeEntry('team', 'en', ['title' => ucfirst($slug)], $slug)]);
        Collection::find('team')->structure()->in('en')->tree($members->map(fn ($entry) => ['entry' => $entry->id()])->values()->all())->save();

        foreach (['dora', 'bob', 'anna', 'chris'] as $slug) {
            translateInto($members[$slug]->id());
        }

        $order = fn (string $site) => Entry::query()->where('collection', 'team')->where('site', $site)->orderBy('order')->get()->map->slug()->all();

        expect($order('el'))->toBe(['anna', 'bob', 'chris', 'dora'])
            ->and(array_map(fn (array $branch) => Entry::find($branch['entry'])->slug(), Collection::find('team')->structure()->in('el')->tree()))->toBe(['anna', 'bob', 'chris', 'dora']);
    });

    it('looks up a new page\'s nearest localized siblings to place it, not every page of its level', function () {
        $queries = 0;
        $counting = false;
        Event::listen(QueryExecuted::class, function () use (&$queries, &$counting) {
            $queries += $counting ? 1 : 0;
        });
        config(['statamic.system.fake_sql_queries' => true]);

        // An orderable collection whose members are in Greek, in a stored tree, up to two before the middle one, which is then translated.
        $place = function (string $handle, int $size) use (&$queries, &$counting): array {
            $this->makeCollection($handle, ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]], structured: true)
                ->structureContents(['root' => false, 'max_depth' => 1])
                ->save();
            $members = collect(range(1, $size))->map(fn (int $i) => $this->makeEntry($handle, 'en', ['title' => "Member {$i}"], "member-{$i}"));
            Collection::find($handle)->structure()->in('en')->tree($members->map(fn ($entry) => ['entry' => $entry->id()])->all())->save();
            $middle = intdiv($size, 2);
            $members->take($middle - 2)->each(fn (EntryContract $member) => ContentWriter::write(fn () => $this->localize($member, 'el')));
            $tree = Collection::find($handle)->structure()->in('el');
            $tree->tree($tree->tree())->save();
            $fields = applyAssembled($members[$middle]->id());

            [$queries, $counting] = [0, true];
            applyInWriter($members[$middle]->id(), $fields);
            $counting = false;

            return [
                array_map(fn (array $branch) => Entry::find($branch['entry'])->slug(), Collection::find($handle)->structure()->in('el')->tree()),
                $queries,
            ];
        };

        [$few, $fewQueries] = $place('crew', 6);
        [$many, $manyQueries] = $place('team', 40);

        expect($few)->toBe(['member-1', 'member-4'])
            ->and($many)->toBe([...array_map(fn (int $i) => "member-{$i}", range(1, 18)), 'member-21'])
            ->and($manyQueries)->toBe($fewQueries);
    });
});

it('gives a new localization its id before its first save, so it never reads another collection\'s blueprint or origin', function () {
    $this->makeCollection('pages', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'page_only' => ['type' => 'text'],
    ]);

    $origins = collect(range(1, 4))->flatMap(fn (int $i) => [
        $this->makeEntry('pages', 'en', ['title' => "Page {$i}"]),
        $this->makeEntry('articles', 'en', ['title' => "Article {$i}"]),
    ]);

    $seen = [];
    Event::listen(EntryCreating::class, function (EntryCreating $event) use (&$seen) {
        $entry = $event->entry;
        $seen[] = [$entry->id() !== null, $entry->collectionHandle(), $entry->blueprint()->namespace(), $entry->origin()->collectionHandle()];
    });

    foreach (['el', 'de'] as $site) {
        foreach ($origins as $origin) {
            translateInto($origin->id(), $site);
        }
    }

    expect($seen)->toHaveCount(16);

    foreach ($seen as [$hasId, $collection, $blueprint, $originCollection]) {
        expect([$hasId, $blueprint, $originCollection])->toBe([true, "collections.{$collection}", $collection]);
    }
});

it('lets the callback of ContentWriter::write() see what another process cached', function () {
    $store = Stache::store('entries::articles');
    Entry::query()->where('collection', 'articles')->where('slug', 'hello')->get();

    $cache = Stache::cacheStore();
    $cache->forever('stache::indexes::entries::articles::path', [...$cache->get('stache::indexes::entries::articles::path'), 'other' => '/content/collections/articles/en/other.md']);
    $cache->forever('stache::indexes::entries::articles::_indexes', [...$cache->get('stache::indexes::entries::articles::_indexes'), 'late_index']);

    $before = [$store->paths()->has('other'), $store->indexUsage()->contains('late_index')];
    $inside = ContentWriter::write(fn () => [$store->paths()->has('other'), $store->indexUsage()->contains('late_index')]);

    expect($before)->toBe([false, false])
        ->and($inside)->toBe([true, true]);
});

/**
 * Every row and set id of the value, at any depth, by path.
 *
 * @return array<string, string>
 */
function rowIdsOf(mixed $value, string $path = ''): array
{
    $ids = [];

    foreach (is_array($value) ? $value : [] as $key => $item) {
        if ($key === 'id' && is_string($item)) {
            $ids[$path] = $item;
        } else {
            $ids += rowIdsOf($item, "{$path}/{$key}");
        }
    }

    return $ids;
}

describe('row and set ids', function () {
    beforeEach(function () {
        $this->makeCollection('stories', ['en', 'el'], [
            'title' => ['type' => 'text', 'localizable' => true],
            'blocks' => ['type' => 'replicator', 'localizable' => true, 'sets' => ['main' => ['sets' => [
                'text' => ['display' => 'Text', 'fields' => [['handle' => 'heading', 'field' => ['type' => 'text']]]],
                'quote' => ['display' => 'Quote', 'fields' => [['handle' => 'text', 'field' => ['type' => 'textarea']]]],
            ]]]],
            'faq' => ['type' => 'grid', 'localizable' => true, 'fields' => [['handle' => 'question', 'field' => ['type' => 'text']]]],
            'body' => ['type' => 'bard', 'localizable' => true, 'sets' => ['main' => ['sets' => [
                'quote' => ['display' => 'Quote', 'fields' => [['handle' => 'text', 'field' => ['type' => 'textarea']]]],
            ]]]],
        ]);

        // Written by code, as an import or a seeder writes it: no row or set ids.
        $this->story = $this->makeEntry('stories', 'en', [
            'title' => 'Hello',
            'blocks' => [['type' => 'text', 'heading' => 'Rooms'], ['type' => 'text', 'heading' => 'Dining']],
            'faq' => [['question' => 'When is breakfast?']],
            'body' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Welcome.']]],
                ['type' => 'set', 'attrs' => ['values' => ['type' => 'quote', 'text' => 'Best stay ever.']]],
            ],
        ], 'hello');
        $this->storyIds = fn () => rowIdsOf(Arr::only($this->story->fresh()->in('el')->data()->all(), ['blocks', 'faq', 'body']));
    });

    it('keeps the ids of the localization\'s rows and sets when an update rebuilds them from an origin without ids', function () {
        translateInto($this->story->id());
        $before = ($this->storyIds)();
        $blocks = $this->story->get('blocks');
        $blocks[1]['heading'] = 'Dining room';
        $this->story->set('blocks', $blocks)->set('faq', [['question' => 'When is lunch?']])->set('body', [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Welcome home.']]],
            ...array_slice($this->story->get('body'), 1),
        ])->save();

        translateInto($this->story->id(), plan: ['mode' => Mode::Update]);

        expect($before)->toHaveCount(4)
            ->and($this->story->fresh()->get('blocks')[0])->not->toHaveKey('id')
            ->and($this->story->fresh()->in('el')->get('blocks')[1]['heading'])->toBe('[T] Dining room')
            ->and(($this->storyIds)())->toBe($before)
            ->and(StateFixtures::statuses($this->story))->each->toBe(FieldStatus::Current);
    });

    it('keeps them when everything is translated again', function () {
        translateInto($this->story->id());
        $before = ($this->storyIds)();

        translateInto($this->story->id(), plan: ['mode' => Mode::All]);

        expect(($this->storyIds)())->toBe($before);
    });

    it('gives a new id to a row whose set type changed at its position, and to a row the origin added', function () {
        translateInto($this->story->id());
        $before = ($this->storyIds)();
        $this->story->set('blocks', [
            ['type' => 'text', 'heading' => 'Rooms'],
            ['type' => 'quote', 'text' => 'Lovely.'],
            ['type' => 'text', 'heading' => 'Spa'],
        ])->save();

        translateInto($this->story->id(), plan: ['mode' => Mode::Update]);
        $after = ($this->storyIds)();

        expect($after['/blocks/0'])->toBe($before['/blocks/0'])
            ->and($after['/blocks/1'])->toBeString()->not->toBe($before['/blocks/1'])
            ->and($after['/blocks/2'])->toBeString()->not->toBeIn($before);
    });

    it('takes the origin\'s ids once the origin has them', function () {
        translateInto($this->story->id());
        $this->story->set('blocks', [['id' => 'rooms01', 'type' => 'text', 'heading' => 'Rooms'], ['id' => 'dining01', 'type' => 'text', 'heading' => 'Dining']])->save();

        translateInto($this->story->id(), plan: ['mode' => Mode::Update]);

        expect(array_column($this->story->fresh()->in('el')->get('blocks'), 'id'))->toBe(['rooms01', 'dining01']);
    });

    it('keeps the ids of rows nested in groups, sets and grids', function () {
        AssetContainer::make('assets')->disk('local')->save();
        ContentFixtures::field('replicator-nested');
        $fixtures = ['content' => 'bard-rich', 'builder' => 'replicator-nested', 'rooms' => 'grid'];
        $this->makeCollection('pages', ['en', 'el'], [
            'title' => ['type' => 'text', 'localizable' => true],
            ...array_map(fn (string $name) => [...ContentFixtures::raw($name)['field'], 'localizable' => true], $fixtures),
        ]);
        $origin = $this->makeEntry('pages', 'en', ['title' => 'Home', ...array_map(ContentFixtures::value(...), $fixtures)], 'home');
        $ids = fn () => rowIdsOf(Arr::only($origin->fresh()->in('el')->data()->all(), array_keys($fixtures)));

        translateInto($origin->id());
        $before = $ids();

        translateInto($origin->id(), plan: ['mode' => Mode::All]);

        expect(count($before))->toBeGreaterThan(count(rowIdsOf(array_map(ContentFixtures::value(...), $fixtures))))
            ->and($ids())->toBe($before);
    });
});

describe('existing localizations', function () {
    it('skips a field edited between plan and apply and keeps the human value', function () {
        $localization = $this->localize($this->origin, 'el');
        $fields = applyAssembled($this->origin);

        $localization->fresh()->set('title', 'Χειροποίητο')->save();

        $result = applyInWriter($this->origin, $fields);

        expect($result->skipped)->toBe(['title' => 'target_changed'])
            ->and($result->applied)->toBe(['intro', 'body'])
            ->and($localization->fresh()->get('title'))->toBe('Χειροποίητο')
            ->and($localization->fresh()->get('intro'))->toBe('[T] A short intro.')
            ->and(StateFixtures::statuses($this->origin)['title'])->toBe(FieldStatus::Manual)
            ->and(app(StateRepository::class)->get(new TargetRef(ContentType::Entry, $localization->id()))->field('title')->pending)->toBe([]);
    });

    it('overwrites a field edited between plan and apply with overwriteManual', function () {
        $localization = $this->localize($this->origin, 'el');
        $fields = applyAssembled($this->origin);

        $localization->fresh()->set('title', 'Χειροποίητο')->save();

        $result = applyInWriter($this->origin, $fields, new ApplyOptions(overwriteManual: true));

        expect($result->skipped)->toBe([])
            ->and($localization->fresh()->get('title'))->toBe('[T] Hello')
            ->and(StateFixtures::statuses($this->origin)['title'])->toBe(FieldStatus::Current);
    });

    it('removes the override of a field whose source was emptied, and forgets its state', function () {
        translateInto($this->origin);
        $this->origin->fresh()->remove('intro')->save();

        expect(StateFixtures::statuses($this->origin)['intro'])->toBe(FieldStatus::Outdated);

        $fields = applyAssembled($this->origin, 'el', ['mode' => Mode::Update]);

        expect(array_map(fn (AssembledField $field) => [$field->handle, $field->remove], $fields))->toBe([['intro', true]]);

        $result = applyInWriter($this->origin, $fields);
        $localization = $this->origin->fresh()->in('el');

        expect($result->applied)->toBe(['intro'])
            ->and($localization->has('intro'))->toBeFalse()
            ->and(app(StateRepository::class)->get($result->ref)->fields)->not->toHaveKey('intro')
            ->and(StateFixtures::statuses($this->origin))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::NotApplicable, 'body' => FieldStatus::Current]);

        $this->origin->fresh()->set('intro', 'Back again.')->save();

        expect(StateFixtures::statuses($this->origin)['intro'])->toBe(FieldStatus::Untranslated);
    });

    it('leaves a pending entry that reads as machine and current when it fails after save()', function () {
        $localization = $this->localize($this->origin, 'el');
        $fields = applyAssembled($this->origin);

        Event::listen(EntrySaved::class, function (EntrySaved $event) {
            if ($event->entry->locale() === 'el') {
                throw new RuntimeException('After save');
            }
        });

        expect(fn () => applyInWriter($this->origin, $fields))->toThrow(RuntimeException::class, 'After save');

        $state = app(StateRepository::class)->get(new TargetRef(ContentType::Entry, $localization->id()));

        expect($localization->fresh()->get('title'))->toBe('[T] Hello')
            ->and($state->field('title')->pending)->toHaveCount(1)
            ->and($state->field('title')->targetHash)->toBeNull()
            ->and(StateFixtures::statuses($this->origin))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current, 'body' => FieldStatus::Current])
            ->and(ContentWriter::active())->toBeFalse();
    });
});

it('stores a normalised value that keeps its status after a CP round trip', function () {
    $this->makeCollection('stories', ['en', 'el'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'body' => ['type' => 'replicator', 'localizable' => true, 'sets' => ['main' => ['sets' => [
            'text' => ['fields' => [
                ['handle' => 'heading', 'field' => ['type' => 'text']],
                ['handle' => 'featured', 'field' => ['type' => 'toggle', 'default' => true]],
                ['handle' => 'kicker', 'field' => ['type' => 'text', 'default' => 'Read this']],
            ]],
            'cta' => ['fields' => [
                ['handle' => 'label', 'field' => ['type' => 'text']],
            ]],
        ]]]],
    ]);
    $origin = $this->makeEntry('stories', 'en', ['title' => 'Story', 'body' => [
        ['type' => 'text', 'heading' => 'First'],
        ['type' => 'cta', 'label' => 'Book now'],
    ]]);

    translateInto($origin->id());

    $localization = $origin->fresh()->in('el');
    $stored = $localization->get('body');
    $roundTrip = fn (EntryContract $entry, mixed $value) => $entry->blueprint()->setParent($entry)->fields()
        ->addValues(['body' => $value])->preProcess()->process()->values()['body'];

    expect($stored[0])->toMatchArray(['type' => 'text', 'heading' => '[T] First', 'featured' => true, 'kicker' => 'Read this', 'enabled' => true])
        ->and($stored[0]['id'])->toMatch('/^[A-Za-z0-9]{8}$/')
        ->and($roundTrip($localization, $stored))->toBe($stored);

    $localization->set('body', $roundTrip($localization, $stored))->save();

    expect(StateFixtures::statuses($origin->id()))->toBe(['title' => FieldStatus::Current, 'body' => FieldStatus::Current]);
});

describe('the writer', function () {
    it('throws outside ContentWriter::write() and saves nothing', function () {
        $fields = applyAssembled($this->origin);

        expect(fn () => applyTarget($this->origin)->apply($fields, new ApplyOptions))->toThrow(LogicException::class)
            ->and($this->origin->fresh()->existsIn('el'))->toBeFalse();
    });

    it('saves without Statamic\'s git listener and turns it back on afterwards', function () {
        Queue::fake();
        config(['statamic.git.enabled' => true, 'statamic.git.automatic' => true]);
        $listeners = fn () => count(array_filter(Event::getRawListeners()[EntrySaved::class] ?? [], fn ($listener) => $listener === Subscriber::class.'@commit'));
        $fields = applyAssembled($this->origin);

        $during = [];
        Event::listen(EntrySaved::class, function () use (&$during, $listeners) {
            $during[] = $listeners();
        });

        applyInWriter($this->origin, $fields);

        Queue::assertNotPushed(CommitJob::class);
        expect($during)->toBe([0, 0])
            ->and($listeners())->toBe(1);
    });

    it('throws for a field that is no longer in scope, before anything is saved', function () {
        $fields = applyAssembled($this->origin);
        $blueprint = Collection::find('articles')->entryBlueprint();
        $blueprint->ensureFieldHasConfig('body', ['type' => 'textarea', 'localizable' => false])->save();

        expect(fn () => applyInWriter($this->origin, $fields))->toThrow(SourceChanged::class)
            ->and($this->origin->fresh()->existsIn('el'))->toBeFalse();
    });

    it('throws for a field whose source changed after it was assembled, before anything is written', function () {
        $localization = $this->localize($this->origin, 'el');
        $fields = applyAssembled($this->origin);
        $data = $localization->fresh()->data()->all();
        $state = StateFixtures::stored($localization)->toArray();
        $this->origin->fresh()->set('intro', 'An edited intro.')->save();

        expect(fn () => applyInWriter($this->origin, $fields))->toThrow(SourceChanged::class, 'The source of the field [intro] changed')
            ->and($localization->fresh()->data()->all())->toBe($data)
            ->and(StateFixtures::stored($localization)->toArray())->toBe($state);
    });

    it('throws ApplyFailed when a listener cancels the save', function () {
        $this->localize($this->origin, 'el');
        $fields = applyAssembled($this->origin);
        Event::listen(EntrySaving::class, fn () => false);

        expect(fn () => applyInWriter($this->origin, $fields))->toThrow(ApplyFailed::class);
    });
});

it('fails authorize() for a user without access to the target site', function () {
    $user = $this->signInWithPermissions(['access en site', 'access de site', 'view articles entries', 'edit articles entries']);

    expect(applyTarget($this->origin)->authorize($user))->toBeFalse()
        ->and(applyTarget($this->origin, 'de')->authorize($user))->toBeTrue();
});
