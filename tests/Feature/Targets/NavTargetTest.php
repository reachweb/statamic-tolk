<?php

use Illuminate\Support\Facades\Event;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\ItemStatus;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Exceptions\ApplyFailed;
use Reach\StatamicTolk\Exceptions\InvalidTarget;
use Reach\StatamicTolk\Exceptions\NotALocalization;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\ApplyResult;
use Reach\StatamicTolk\Targets\NavTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Translation\AssembledField;
use Reach\StatamicTolk\Translation\Pipeline;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\PlanStore;
use Statamic\Events\NavTreeSaving;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Tags\Loader;

beforeEach(function () {
    $this->useFileCache();
    $this->sent = [];
    $this->fakeTranslations(function (string $text) {
        $this->sent[] = $text;

        return '[T] '.$text;
    });

    $this->makeCollection('pages', ['en', 'el', 'de'], ['title' => ['type' => 'text', 'localizable' => true]], structured: true);
    $this->about = $this->makeEntry('pages', 'en', ['title' => 'About'], 'about');
    $this->aboutEl = $this->localize($this->about, 'el', ['title' => 'Σχετικά']);
    $this->dining = $this->makeEntry('pages', 'en', ['title' => 'Dining'], 'dining');
    $this->rooms = $this->makeEntry('pages', 'en', ['title' => 'Rooms'], 'rooms');
    $this->suites = $this->makeEntry('pages', 'en', ['title' => 'Suites'], 'suites');
    $this->suitesEl = $this->localize($this->suites, 'el', ['title' => 'Σουίτες']);

    $this->makeBlueprint('navigation', 'main', [
        'badge' => ['type' => 'text', 'display' => 'Badge'],
        'icon' => ['type' => 'text', 'display' => 'Icon', 'tolk_translate' => false],
    ]);
    $this->makeNav('main', ['en' => [
        ['id' => 'about', 'entry' => $this->about->id()],
        ['id' => 'dining', 'entry' => $this->dining->id(), 'title' => 'Eat with us'],
        ['id' => 'rooms', 'entry' => $this->rooms->id(), 'children' => [
            ['id' => 'suites', 'entry' => $this->suites->id()],
        ]],
        ['id' => 'book', 'title' => 'Book your stay', 'url' => 'https://booking.example', 'data' => ['badge' => 'New', 'icon' => 'Calendar icon']],
    ]]);
});

function navTarget(string $site = 'el', ?string $sourceSite = null): NavTarget
{
    return app(TargetFactory::class)->forNav('main', $site, $sourceSite);
}

function navStatus(string $site = 'el'): FieldStatus
{
    return app(StatusResolver::class)->resolve(navTarget($site))->fields[NavTarget::TREE]->status;
}

function navTree(string $site = 'el'): ?array
{
    return Nav::findByHandle('main')->in($site)?->tree();
}

/**
 * Plans the tree, runs every unit and assembles every planned field from a fresh target.
 *
 * @return list<AssembledField>
 */
function navAssembled(string $site = 'el', array $options = []): array
{
    $target = navTarget($site);
    $plan = app(Planner::class)->plan($target, PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), $options));
    app(PlanStore::class)->put($plan);
    $pipeline = app(Pipeline::class);

    while (($runnable = $pipeline->runnable($plan)) !== []) {
        $pipeline->runUnit($plan, array_key_first($runnable));
    }

    $fresh = $target->fresh();

    return array_map(fn (string $handle) => $pipeline->assemble($plan, $fresh, $handle), $plan->fieldHandles());
}

// What `{{ nav:main include_home="true" }}` loops over in the site.
function navTag(string $site): array
{
    $params = ['site' => $site, 'include_home' => true];

    return app(Loader::class)->load('nav', ['parser' => null, 'params' => $params, 'content' => '', 'context' => [], 'tag' => 'nav:main', 'tag_method' => 'main'])->wildcard('main');
}

function applyNav(array $fields, ApplyOptions $options = new ApplyOptions, string $site = 'el'): ApplyResult
{
    return ContentWriter::write(fn () => navTarget($site)->apply($fields, $options));
}

function translateNav(string $site = 'el', ApplyOptions $options = new ApplyOptions, array $plan = []): ApplyResult
{
    return applyNav(navAssembled($site, $plan), $options, $site);
}

describe('reading', function () {
    it('translates the tree of a site from the default site\'s tree, with each entry mapped to its localization there', function () {
        $target = navTarget();

        expect($target->ref())->toBeNull()
            ->and($target->exists())->toBeFalse()
            ->and($target->type())->toBe(ContentType::Nav)
            ->and($target->sourceSite())->toBe('en')
            ->and($target->targetSite())->toBe('el')
            ->and($target->container())->toBe('main')
            ->and($target->title())->toBe('Main')
            ->and($target->editUrl())->toBeNull()
            ->and($target->describe())->toBe(['type' => 'nav', 'source_id' => 'main::en', 'site' => 'el'])
            ->and($target->fields()->keys()->all())->toBe(['tree'])
            ->and($target->fields()->get('tree')->display())->toBe('Main')
            ->and($target->explainFields())->toBe([
                ['handle' => 'tree', 'display' => 'Main', 'type' => 'yaml', 'in_scope' => true, 'reason' => 'ok'],
                ['handle' => 'icon', 'display' => 'Icon', 'type' => 'text', 'in_scope' => false, 'reason' => 'disabled'],
            ])
            ->and($target->sourceIsLocalization())->toBeFalse()
            ->and($target->sourceHasValue('tree'))->toBeTrue()
            ->and($target->hasOverride('tree'))->toBeFalse()
            ->and($target->targetValue('tree'))->toBeNull()
            ->and($target->inheritedValue('tree'))->toBeNull()
            ->and($target->sourceValue('tree'))->toBe([
                ['id' => 'about', 'entry' => $this->aboutEl->id()],
                ['id' => 'rooms', 'title' => 'Rooms', 'children' => [['id' => 'suites', 'entry' => $this->suitesEl->id()]]],
                ['id' => 'book', 'title' => 'Book your stay', 'url' => 'https://booking.example', 'data' => ['badge' => 'New', 'icon' => 'Calendar icon']],
            ])
            ->and(array_column(app(ContentWalker::class)->leaves($target->fields()->get('tree'), $target->sourceValue('tree')), 'text'))->toBe(['Rooms', 'Book your stay', 'New'])
            ->and($target->document())->toBe(['type' => 'nav', 'title' => 'Main', 'container' => 'Main', 'url' => null, 'summary' => 'Rooms Book your stay New'])
            ->and(fn () => $target->formFields([]))->toThrow(LogicException::class);
    });

    it('reads the target\'s own tree, and an empty tree as holding nothing', function () {
        Nav::findByHandle('main')->makeTree('el', [['id' => 'book', 'title' => 'Κράτηση']])->save();
        Nav::findByHandle('main')->makeTree('de', [])->save();

        $greek = navTarget();
        $german = navTarget('de');

        expect($greek->ref())->toEqual(new TargetRef(ContentType::Nav, 'main', 'el'))
            ->and((string) $greek->ref())->toBe('nav::main::el')
            ->and($greek->editUrl())->toBe(Nav::findByHandle('main')->in('el')->showUrl())
            ->and($greek->hasOverride('tree'))->toBeTrue()
            ->and($greek->targetValue('tree'))->toBe([['id' => 'book', 'title' => 'Κράτηση']])
            ->and($german->exists())->toBeTrue()
            ->and($german->hasOverride('tree'))->toBeFalse()
            ->and($german->targetValue('tree'))->toBeNull()
            ->and(navStatus('de'))->toBe(FieldStatus::Untranslated);
    });

    it('translates from the tree of the source site it is given, into any other site', function () {
        Nav::findByHandle('main')->makeTree('de', [['id' => 'book', 'title' => 'Buchen', 'url' => 'https://booking.example']])->save();

        $target = navTarget('el', 'de');

        expect($target->sourceSite())->toBe('de')
            ->and($target->describe())->toBe(['type' => 'nav', 'source_id' => 'main::de', 'site' => 'el'])
            ->and($target->sourceValue('tree'))->toBe([['id' => 'book', 'title' => 'Buchen', 'url' => 'https://booking.example']])
            ->and(navTarget('en', 'de')->targetSite())->toBe('en');
    });

    it('has nothing to translate from a source site without a tree', function () {
        $target = navTarget('el', 'de');

        expect($target->sourceHasValue('tree'))->toBeFalse()
            ->and($target->sourceValue('tree'))->toBeNull()
            ->and(app(StatusResolver::class)->resolve($target)->fields['tree']->status)->toBe(FieldStatus::NotApplicable)
            ->and(app(Planner::class)->plan($target, PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class)))->isEmpty())->toBeTrue();
    });
});

describe('TargetFactory', function () {
    it('accepts the navigation or its handle', function () {
        expect(app(TargetFactory::class)->forNav(Nav::findByHandle('main'), 'el')->describe())->toBe(navTarget()->describe());
    });

    it('rejects an unknown navigation or site, and the source site as a target', function (Closure $build, string $exception) {
        expect($build)->toThrow($exception);
    })->with([
        'unknown navigation' => [fn () => app(TargetFactory::class)->forNav('footer', 'el'), TargetNotFound::class],
        'unknown site' => [fn () => navTarget('fr'), UnknownSite::class],
        'unknown source site' => [fn () => navTarget('el', 'fr'), UnknownSite::class],
        'the default site' => [fn () => navTarget('en'), InvalidTarget::class],
        'the source site' => [fn () => navTarget('de', 'de'), InvalidTarget::class],
    ]);

    it('round-trips a reference of a site with a tree, translated from the default site', function () {
        Nav::findByHandle('main')->makeTree('el', [])->save();

        $target = app(TargetFactory::class)->fromReference('nav::main::el');

        expect($target)->toBeInstanceOf(NavTarget::class)
            ->and((string) $target->ref())->toBe('nav::main::el')
            ->and($target->sourceSite())->toBe('en');
    });

    it('rejects a reference of a site without a tree, of an unknown navigation or site, and of the default site', function (string $reference, string $exception) {
        expect(fn () => app(TargetFactory::class)->fromReference($reference))->toThrow($exception);
    })->with([
        'no tree' => ['nav::main::el', TargetNotFound::class],
        'unknown navigation' => ['nav::footer::el', TargetNotFound::class],
        'unknown site' => ['nav::main::fr', TargetNotFound::class],
        'default site' => ['nav::main::en', NotALocalization::class],
    ]);

    it('rejects a reference of a tree in a site the configuration leaves out now as an unknown site, not a missing tree', function () {
        Nav::findByHandle('main')->makeTree('de', [])->save();
        Site::setSites([
            'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en'],
            'el' => ['name' => 'Greek', 'url' => 'http://localhost/el/', 'locale' => 'el_GR', 'lang' => 'el'],
        ]);

        expect(fn () => app(TargetFactory::class)->fromReference('nav::main::de'))->toThrow(UnknownSite::class);
    });

    it('rebuilds a target from its description, or from a handle alone with the default site\'s tree', function () {
        Nav::findByHandle('main')->makeTree('de', [])->save();

        $fromDe = app(TargetFactory::class)->fromDescription(navTarget('el', 'de')->describe());
        $fromHandle = app(TargetFactory::class)->fromDescription(['type' => 'nav', 'source_id' => 'main', 'site' => 'de']);

        expect($fromDe->sourceSite())->toBe('de')
            ->and($fromDe->targetSite())->toBe('el')
            ->and($fromHandle->sourceSite())->toBe('en')
            ->and($fromHandle->targetSite())->toBe('de');
    });
});

describe('applying', function () {
    it('creates a missing tree with translated titles and mapped entry ids, keeping branch ids, and warns about entries without a localization', function () {
        expect(app(StatusResolver::class)->resolve(navTarget())->itemStatus)->toBe(ItemStatus::Missing)
            ->and(navStatus())->toBe(FieldStatus::Untranslated);

        $result = translateNav();

        expect($result->ref)->toEqual(new TargetRef(ContentType::Nav, 'main', 'el'))
            ->and($result->created)->toBeTrue()
            ->and($result->applied)->toBe(['tree'])
            ->and($result->skipped)->toBe([])
            ->and($result->warnings)->toBe([
                'The navigation item "Eat with us" was left out: its entry has no localization in Greek.',
                'The navigation item "Rooms" has no link in Greek: its entry has no localization there, so only its title and the items below it were kept.',
            ])
            ->and(navTree())->toBe([
                ['id' => 'about', 'entry' => $this->aboutEl->id()],
                ['id' => 'rooms', 'title' => '[T] Rooms', 'children' => [['id' => 'suites', 'entry' => $this->suitesEl->id()]]],
                ['id' => 'book', 'title' => '[T] Book your stay', 'url' => 'https://booking.example', 'data' => ['badge' => '[T] New', 'icon' => 'Calendar icon']],
            ])
            ->and(navTree('en')[1])->toBe(['id' => 'dining', 'entry' => $this->dining->id(), 'title' => 'Eat with us'])
            ->and(navTree('de'))->toBeNull()
            ->and($this->sent)->toBe(['Rooms', 'Book your stay', 'New'])
            ->and(navStatus())->toBe(FieldStatus::Current)
            ->and(app(StatusResolver::class)->resolve(navTarget())->itemStatus)->toBe(ItemStatus::Current);

        $state = app(StateRepository::class)->get($result->ref);

        expect($state->hasPending())->toBeFalse()
            ->and($state->sourceSite)->toBe('en')
            ->and(app(StateRepository::class)->path($result->ref))->toEndWith('navs/main/el.yaml')
            ->and($state->field('tree')->targetHash)->toBe(Fingerprint::ofLeaves(['[T] Rooms', '[T] Book your stay', '[T] New']))
            ->and($state->field('tree')->sourceHash)->toBe(Fingerprint::of(navTarget()->sourceValue('tree')));

        $pages = Nav::findByHandle('main')->in('el')->flattenedPages()->keyBy->id();

        expect($pages['about']->title())->toBe('Σχετικά')
            ->and($pages['about']->entry()->locale())->toBe('el')
            ->and($pages['rooms']->title())->toBe('[T] Rooms')
            ->and($pages['suites']->entry()->locale())->toBe('el');
    });

    it('names a branch whose entry no longer exists by its entry id, and keeps the items below it', function () {
        $tree = navTree('en');
        $tree[2]['children'][] = ['id' => 'spa', 'title' => 'Spa', 'url' => '/spa'];
        Nav::findByHandle('main')->in('en')->tree($tree)->save();
        $roomsId = $this->rooms->id();
        $this->rooms->delete();

        $result = translateNav();

        expect($result->warnings)->toContain("The navigation item for the entry [{$roomsId}] has no link in Greek: its entry has no localization there, so only its title and the items below it were kept.")
            ->and(navTree()[1])->toBe(['id' => 'rooms', 'children' => [
                ['id' => 'suites', 'entry' => $this->suitesEl->id()],
                ['id' => 'spa', 'title' => '[T] Spa', 'url' => '/spa'],
            ]]);
    });

    it('keeps the root page of a navigation that expects one as a text-only root when its entry has no localization, so the tree stays readable', function () {
        $home = $this->makeEntry('pages', 'en', ['title' => 'Home'], 'home');
        $nav = Nav::findByHandle('main');
        $nav->expectsRoot(true)->save();
        $nav->in('en')->tree([
            ['id' => 'home', 'entry' => $home->id()],
            ['id' => 'about', 'entry' => $this->about->id(), 'children' => [['id' => 'suites', 'entry' => $this->suites->id()]]],
            ['id' => 'book', 'title' => 'Book your stay', 'url' => 'https://booking.example'],
        ])->save();

        $result = translateNav();
        Stache::clear();

        expect($result->warnings)->toBe(['The root page "Home" of the navigation has no link in Greek: its entry has no localization there, so only its title was kept.'])
            ->and(navTree())->toBe([
                ['id' => 'home', 'title' => '[T] Home'],
                ['id' => 'about', 'entry' => $this->aboutEl->id(), 'children' => [['id' => 'suites', 'entry' => $this->suitesEl->id()]]],
                ['id' => 'book', 'title' => '[T] Book your stay', 'url' => 'https://booking.example'],
            ])
            ->and(Nav::findByHandle('main')->in('el')->root())->toBe(['id' => 'home', 'title' => '[T] Home'])
            ->and(collect(navTag('el'))->map(fn (array $page) => (string) $page['title'])->all())->toBe(['[T] Home', 'Σχετικά', '[T] Book your stay'])
            ->and(navStatus())->toBe(FieldStatus::Current);

        $this->signInAdmin();

        expect($this->getJson(cp_route('navigation.tree.index', ['navigation' => 'main', 'site' => 'el']))->assertOk()->json('pages.0'))
            ->toMatchArray(['id' => 'home', 'entry' => null, 'title' => '[T] Home', 'url' => null, 'children' => []]);

        $homeEl = $this->localize($home, 'el', ['title' => 'Αρχική']);

        expect(navStatus())->toBe(FieldStatus::Outdated);

        $update = translateNav(plan: ['mode' => Mode::Update]);

        expect($update->warnings)->toBe([])
            ->and(navTree()[0])->toBe(['id' => 'home', 'entry' => $homeEl->id()])
            ->and(navStatus())->toBe(FieldStatus::Current);
    });

    it('validates the tree as the navigation expects it before saving, and saves nothing Statamic would not read', function () {
        $fields = navAssembled();
        $field = $fields[0];
        $invalid = [['id' => 'about', 'entry' => $this->aboutEl->id(), 'children' => [['id' => 'suites', 'entry' => $this->suitesEl->id()]]]];
        Nav::findByHandle('main')->expectsRoot(true)->save();

        expect(fn () => applyNav([new AssembledField($field->handle, $field->plan, $invalid, false, $field->sourceHash, $field->targetHash, $field->targetBefore, [], false, false, null)]))
            ->toThrow(ApplyFailed::class, 'The tree of [nav::main::el] was not saved: Root page cannot have children')
            ->and(navTree())->toBeNull()
            ->and(app(StateRepository::class)->get(new TargetRef(ContentType::Nav, 'main', 'el'))->hasPending())->toBeFalse();
    });

    it('reads outdated once a missing localization appears, and Update brings its branch back and sends only its title', function () {
        translateNav();
        $diningEl = $this->localize($this->dining, 'el', ['title' => 'Εστιατόριο']);
        $roomsEl = $this->localize($this->rooms, 'el', ['title' => 'Δωμάτια']);
        $this->sent = [];

        expect(navStatus())->toBe(FieldStatus::Outdated);

        $result = translateNav(plan: ['mode' => Mode::Update]);

        expect($result->applied)->toBe(['tree'])
            ->and($result->warnings)->toBe([])
            ->and($this->sent)->toBe(['Eat with us'])
            ->and(navTree())->toBe([
                ['id' => 'about', 'entry' => $this->aboutEl->id()],
                ['id' => 'dining', 'entry' => $diningEl->id(), 'title' => '[T] Eat with us'],
                ['id' => 'rooms', 'entry' => $roomsEl->id(), 'children' => [['id' => 'suites', 'entry' => $this->suitesEl->id()]]],
                ['id' => 'book', 'title' => '[T] Book your stay', 'url' => 'https://booking.example', 'data' => ['badge' => '[T] New', 'icon' => 'Calendar icon']],
            ])
            ->and(navStatus())->toBe(FieldStatus::Current);
    });

    it('reads an edited target tree as manual and overwrites it only with overwriteManual', function () {
        translateNav();
        $edited = navTree();
        $edited[2]['title'] = 'Κάντε κράτηση';
        Nav::findByHandle('main')->in('el')->tree($edited)->save();
        $this->sent = [];

        expect(navStatus())->toBe(FieldStatus::Manual)
            ->and(navAssembled(options: ['mode' => Mode::All]))->toBe([])
            ->and(applyNav([])->applied)->toBe([])
            ->and(navTree())->toBe($edited)
            ->and($this->sent)->toBe([]);

        $result = translateNav(options: new ApplyOptions(overwriteManual: true), plan: ['mode' => Mode::All, 'overwriteManual' => true]);

        expect($result->applied)->toBe(['tree'])
            ->and(navTree()[2]['title'])->toBe('[T] Book your stay')
            ->and(navStatus())->toBe(FieldStatus::Current);
    });

    it('reads a tree whose pages were removed or moved by hand without a text change as current, so Update rebuilds it from the source', function () {
        $this->localize($this->dining, 'el', ['title' => 'Εστιατόριο']);
        $this->localize($this->rooms, 'el', ['title' => 'Δωμάτια']);
        translateNav();
        [$about, $dining, $rooms, $book] = navTree();
        Nav::findByHandle('main')->in('el')->tree([$rooms, $dining, $book])->save();

        expect(navStatus())->toBe(FieldStatus::Current);

        $source = Nav::findByHandle('main')->in('en');
        $source->tree([...array_slice($source->tree(), 0, 3), [...$source->tree()[3], 'title' => 'Book now']])->save();
        translateNav(plan: ['mode' => Mode::Update]);

        expect(array_column(navTree(), 'id'))->toBe(['about', 'dining', 'rooms', 'book'])
            ->and(navTree()[3]['title'])->toBe('[T] Book now');
    });

    it('keeps the pages an editor removed or moved once tolk:baseline --protect made the tree manual, and reads a source change as needing attention', function () {
        $this->localize($this->dining, 'el', ['title' => 'Εστιατόριο']);
        $this->localize($this->rooms, 'el', ['title' => 'Δωμάτια']);
        translateNav();
        [$about, $dining, $rooms, $book] = navTree();
        $edited = [$rooms, $dining, $book];
        Nav::findByHandle('main')->in('el')->tree($edited)->save();

        $this->artisan('tolk:baseline', ['--nav' => ['main'], '--site' => ['el'], '--protect' => true])
            ->expectsTable(['Type', 'Handle', 'Site', 'Fields recorded'], [['Navigation', 'main', 'el', '1']])
            ->assertExitCode(0);

        $protected = app(StatusResolver::class)->resolve(navTarget());
        $source = Nav::findByHandle('main')->in('en');
        $source->tree([...array_slice($source->tree(), 0, 3), [...$source->tree()[3], 'title' => 'Book now']])->save();
        $changed = app(StatusResolver::class)->resolve(navTarget());
        $this->sent = [];

        expect($protected->fields[NavTarget::TREE]->status)->toBe(FieldStatus::Manual)
            ->and($protected->fields[NavTarget::TREE]->sourceChanged)->toBeFalse()
            ->and($changed->itemStatus)->toBe(ItemStatus::Attention)
            ->and(navAssembled(options: ['mode' => Mode::Update]))->toBe([])
            ->and($this->sent)->toBe([])
            ->and(navTree())->toBe($edited);
    });

    it('skips a tree edited between plan and apply', function () {
        $fields = navAssembled();
        Nav::findByHandle('main')->makeTree('el', [['id' => 'book', 'title' => 'Κράτηση']])->save();

        $result = applyNav($fields);

        expect($result->skipped)->toBe(['tree' => 'target_changed'])
            ->and($result->created)->toBeFalse()
            ->and($result->warnings)->toBe([])
            ->and(navTree())->toBe([['id' => 'book', 'title' => 'Κράτηση']]);
    });

    it('empties the target tree when the source tree was emptied, and keeps only the history of its state', function () {
        translateNav();
        Nav::findByHandle('main')->in('en')->tree([])->save();

        expect(navStatus())->toBe(FieldStatus::Outdated);

        $fields = navAssembled(options: ['mode' => Mode::Update]);

        expect(array_map(fn (AssembledField $field) => [$field->handle, $field->remove], $fields))->toBe([['tree', true]]);

        $translated = app(StateRepository::class)->get(navTarget()->ref())->field('tree');
        $result = applyNav($fields);

        expect($result->applied)->toBe(['tree'])
            ->and(navTree())->toBe([])
            ->and(app(StateRepository::class)->get($result->ref)->field('tree')->toArray())->toBe(['history' => [
                ['source_hash' => $translated->sourceHash, 'target_hash' => $translated->targetHash],
            ]])
            ->and(navStatus())->toBe(FieldStatus::NotApplicable);
    });

    it('throws outside ContentWriter::write() and saves nothing', function () {
        $fields = navAssembled();

        expect(fn () => navTarget()->apply($fields, new ApplyOptions))->toThrow(LogicException::class)
            ->and(navTree())->toBeNull();
    });

    it('throws ApplyFailed when a listener cancels the save', function () {
        $fields = navAssembled();
        Event::listen(NavTreeSaving::class, fn () => false);

        expect(fn () => applyNav($fields))->toThrow(ApplyFailed::class, 'nav::main::el');
    });
});

describe('authorize()', function () {
    it('passes a user who may edit the navigation in the target site, whether or not its tree exists', function () {
        $user = $this->signInWithPermissions(['access en site', 'access el site', 'edit main nav']);

        expect(navTarget()->authorize($user))->toBeTrue();

        Nav::findByHandle('main')->makeTree('el', [])->save();

        expect(navTarget()->authorize($user))->toBeTrue();
    });

    it('fails a user without access to the target site', function () {
        $user = $this->signInWithPermissions(['access en site', 'access de site', 'edit main nav']);

        expect(navTarget()->authorize($user))->toBeFalse()
            ->and(navTarget('de')->authorize($user))->toBeTrue();
    });

    it('fails a user who may only view the navigation', function () {
        $user = $this->signInWithPermissions(['access en site', 'access el site', 'view main nav']);
        Nav::findByHandle('main')->makeTree('de', [])->save();

        expect(navTarget()->authorize($user))->toBeFalse()
            ->and(navTarget('de')->authorize($user))->toBeFalse();
    });
});
