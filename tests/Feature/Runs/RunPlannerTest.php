<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\Runs\Estimate;
use Reach\StatamicTolk\Runs\PlannedRun;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunItem;
use Reach\StatamicTolk\Runs\RunPlanner;
use Reach\StatamicTolk\Runs\RunProcessor;
use Reach\StatamicTolk\Runs\RunRequest;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\EntryFamilies;
use Reach\StatamicTolk\Targets\NavTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Reach\StatamicTolk\Translation\Plan;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Events\EntrySaved;
use Statamic\Events\NavTreeSaved;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true],
    ]);

    $this->hello = $this->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'A short intro.'], 'hello');
    $this->world = $this->makeEntry('articles', 'en', ['title' => 'World', 'intro' => 'Another intro.'], 'world');
});

function planRun(array $request): PlannedRun
{
    return app(RunPlanner::class)->build(new RunRequest(...['driver' => RunDriver::Browser, ...$request]));
}

function itemLabel(RunItem $item): string
{
    $source = Entry::find($item->sourceId);

    return $source->slug().'→'.$item->targetSite.($source->hasOrigin() ? " (from {$source->locale()})" : '');
}

/**
 * Every item as its label, with the labels of the items it depends on.
 *
 * @return array<string, list<string>>
 */
function itemDependencies(PlannedRun $planned): array
{
    $labels = array_map(itemLabel(...), $planned->items);

    return collect($planned->items)
        ->mapWithKeys(fn (RunItem $item) => [itemLabel($item) => array_map(fn (int $index) => $labels[$index], $item->dependsOn)])
        ->all();
}

function storedRun(PlannedRun $planned): Run
{
    return app(RunRepository::class)->create($planned->run, $planned->items);
}

function translateArticle($entry, string $site): void
{
    $options = PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['profile' => 'background']);

    app(TargetTranslator::class)->translate(app(TargetFactory::class)->forEntry($entry, $site), $options, new ApplyOptions);
}

describe('build', function () {
    it('makes one item per family and site of a collection, family by family', function () {
        $planned = planRun(['sites' => ['el', 'de'], 'collections' => ['articles']]);

        expect(array_map(itemLabel(...), $planned->items))->toBe(['hello→el', 'hello→de', 'world→el', 'world→de'])
            ->and(array_map(fn (RunItem $item) => $item->index, $planned->items))->toBe([0, 1, 2, 3])
            ->and($planned->items[0])->toMatchObject([
                'type' => ContentType::Entry,
                'sourceId' => $this->hello->id(),
                'targetSite' => 'el',
                'title' => 'Hello',
                'stage' => 0,
                'dependsOn' => [],
                'status' => RunItemStatus::Pending,
            ])
            ->and($planned->skipped)->toBe([]);
    });

    it('records the text fields of its targets that are out of scope, once per collection, field and reason', function () {
        $this->makeCollection('notes', ['en', 'el', 'de'], [
            'title' => ['type' => 'text', 'localizable' => true],
            'internal_note' => ['type' => 'textarea', 'display' => 'Internal note'],
            'summary' => ['type' => 'text', 'localizable' => true, 'tolk_translate' => false],
            'published_on' => ['type' => 'date'],
        ]);
        $this->makeEntry('notes', 'en', ['title' => 'One'], 'one');
        $this->makeEntry('notes', 'en', ['title' => 'Two'], 'two');

        $planned = planRun(['sites' => ['el', 'de'], 'collections' => ['articles', 'notes']]);

        expect($planned->run->scope['out_of_scope'])->toBe([
            ['container' => 'notes', 'handle' => 'internal_note', 'label' => 'Internal note', 'type' => 'textarea', 'reason' => 'not_localizable'],
            ['container' => 'notes', 'handle' => 'summary', 'label' => 'Summary', 'type' => 'text', 'reason' => 'disabled'],
        ]);
    });

    it('returns an unsaved pending run with the request as its scope and the options resolved from the settings', function () {
        $this->withSettings(['translator_model' => 'openai:gpt-6.1-sol', 'new_localization_status' => 'mirror']);

        $planned = planRun(['sites' => ['el', 'el'], 'collections' => ['articles'], 'driver' => RunDriver::Cli, 'options' => ['mode' => 'update', 'review' => true, 'reviewer' => 'gemini:gemini-3.8-flash']]);

        expect($planned->run->status)->toBe(RunStatus::Pending)
            ->and($planned->run->driver)->toBe(RunDriver::Cli)
            ->and($planned->run->createdBy)->toBeNull()
            ->and(app(RunRepository::class)->find($planned->run->id))->toBeNull()
            ->and($planned->run->scope)->toBe([
                'sites' => ['el'],
                'source_site' => null,
                'collections' => ['articles'],
                'taxonomies' => [],
                'globals' => [],
                'navs' => [],
                'entry_ids' => [],
                'term_ids' => [],
                'reference' => null,
                'assumed_sites' => [],
                'automatic' => false,
                'skipped' => [],
                'out_of_scope' => [],
            ])
            ->and($planned->run->options)->toBe([
                'mode' => 'update',
                'overwrite_manual' => false,
                'review' => true,
                'translator' => 'openai:gpt-6.1-sol',
                'reviewer' => 'gemini:gemini-3.8-flash',
                'new_status' => 'mirror',
                'publish_changes' => false,
            ]);

        $options = app(RunPlanner::class)->planOptions($planned->run);

        expect((string) $options->translator)->toBe('openai:gpt-6.1-sol')
            ->and((string) $options->reviewer)->toBe('gemini:gemini-3.8-flash')
            ->and($options->mode)->toBe(Mode::Update)
            ->and($options->profile)->toBe('background');
    });

    it('keeps the options the request names over the settings, and plans browser and queue runs with the web profile', function () {
        $this->withSettings(['review_enabled' => true, 'new_localization_status' => 'mirror']);
        $user = $this->signInAdmin();

        $planned = planRun(['sites' => ['el'], 'collections' => ['articles'], 'user' => $user, 'options' => ['review' => false, 'new_status' => 'draft', 'overwrite_manual' => true, 'publish_changes' => true]]);

        expect($planned->run->options)->toMatchArray(['review' => false, 'reviewer' => null, 'new_status' => 'draft', 'overwrite_manual' => true, 'publish_changes' => true])
            ->and($planned->run->createdBy)->toBe(['id' => (string) $user->id(), 'name' => 'admin@example.com']);

        $options = app(RunPlanner::class)->planOptions($planned->run);

        expect($options->profile)->toBe('web')
            ->and($options->review)->toBeFalse()
            ->and($options->overwriteManual)->toBeTrue()
            ->and($options->userId)->toBe((string) $user->id());
    });

    it('resolves an entry selected in a listing to its family', function () {
        $greek = $this->localize($this->hello, 'el', ['title' => 'Γεια']);

        $planned = planRun(['sites' => ['el', 'de'], 'entryIds' => [$greek->id(), $this->hello->id()]]);

        expect(array_map(itemLabel(...), $planned->items))->toBe(['hello→el', 'hello→de'])
            ->and($planned->items[0]->sourceId)->toBe($this->hello->id())
            ->and($planned->items[1]->sourceId)->toBe($this->hello->id());
    });

    it('takes a missing localization from the source site when the family has an entry there', function () {
        $greek = $this->localize($this->world, 'el', ['title' => 'Κόσμος']);

        $planned = planRun(['sites' => ['de'], 'collections' => ['articles'], 'sourceSite' => 'el']);

        expect(array_map(itemLabel(...), $planned->items))->toBe(['hello→de', 'world→de (from el)'])
            ->and($planned->items[1]->sourceId)->toBe($greek->id())
            ->and($planned->items[1]->title)->toBe('Κόσμος')
            ->and($planned->items[1]->dependsOn)->toBe([]);
    });

    it('makes a chained target depend on the item of its source, which comes first', function () {
        $greek = $this->localize($this->hello, 'el', ['title' => 'Γεια']);
        $this->localize($greek, 'de', ['title' => 'Hallo']);
        $this->localize($this->world, 'el', ['title' => 'Κόσμος']);

        expect(itemDependencies(planRun(['sites' => ['de', 'el'], 'collections' => ['articles'], 'options' => ['mode' => 'update']])))->toBe([
            'hello→el' => [],
            'hello→de (from el)' => ['hello→el'],
            'world→de' => [],
            'world→el' => [],
        ]);

        expect(itemDependencies(planRun(['sites' => ['de', 'el'], 'collections' => ['articles'], 'sourceSite' => 'el'])))->toBe([
            'hello→el' => [],
            'hello→de (from el)' => ['hello→el'],
            'world→el' => [],
            'world→de (from el)' => ['world→el'],
        ]);
    });

    it('reports excluded collections and collections without a requested site, and leaves single-site collections out of all', function () {
        $this->withSettings(['excluded_collections' => ['news']]);
        $this->makeCollection('news', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeCollection('events', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeCollection('notes', ['en'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $headline = $this->makeEntry('news', 'en', ['title' => 'Headline'], 'headline');
        $this->makeEntry('events', 'en', ['title' => 'Gala'], 'gala');
        $this->makeEntry('notes', 'en', ['title' => 'Memo'], 'memo');

        $named = planRun(['sites' => ['el', 'de'], 'collections' => ['articles', 'news', 'events']]);
        $all = planRun(['sites' => ['el', 'de'], 'collections' => ['*']]);
        $entry = planRun(['sites' => ['el', 'de'], 'entryIds' => [$headline->id()]]);
        $single = planRun(['sites' => ['el'], 'collections' => ['notes']]);

        expect(array_map(itemLabel(...), $named->items))->toBe(['hello→el', 'hello→de', 'world→el', 'world→de', 'gala→el'])
            ->and($named->skipped)->toBe(['excluded_collection' => 2, 'site_not_in_collection' => 1])
            ->and($named->run->scope['skipped'])->toBe($named->skipped)
            ->and(array_map(itemLabel(...), $all->items))->toEqualCanonicalizing(['hello→el', 'hello→de', 'world→el', 'world→de', 'gala→el'])
            ->and(collect($all->skipped)->sortKeys()->all())->toBe(['excluded_collection' => 2, 'site_not_in_collection' => 1])
            ->and($entry->items)->toBe([])
            ->and($entry->skipped)->toBe(['excluded_collection' => 2])
            ->and($single->items)->toBe([])
            ->and($single->skipped)->toBe(['site_not_in_collection' => 1]);
    });

    it('plans and estimates the containers a request assumes to have its sites, single-site ones included', function () {
        $this->makeCollection('notes', ['en'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeEntry('notes', 'en', ['title' => 'Memo'], 'memo');
        $this->makeTaxonomy('topics', ['en'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food']]);
        $this->makeGlobalSet('brand', ['en' => null], ['tagline' => ['type' => 'text', 'localizable' => true]], ['en' => ['tagline' => 'Kyma Bay']]);
        $assumed = ['collection:notes' => null, 'taxonomy:topics' => null, 'globals:brand' => 'en'];

        $planned = planRun(['sites' => ['de'], 'collections' => ['*'], 'taxonomies' => ['*'], 'globals' => ['*'], 'assumedSites' => $assumed]);
        $progress = app(RunPlanner::class)->estimate(storedRun($planned), 0, 60_000);

        expect(array_map(fn (RunItem $item) => $item->title.'→'.$item->targetSite, $planned->items))->toEqualCanonicalizing(['Hello→de', 'World→de', 'Memo→de', 'Food→de', 'Brand→de'])
            ->and($planned->skipped)->toBe([])
            ->and($planned->run->scope['assumed_sites'])->toBe($assumed)
            ->and($progress->estimate->segments)->toBe(7);
    });

    it('drops the targets a user may not edit and counts them', function () {
        $user = $this->signInWithPermissions(['access en site', 'access el site', 'edit articles entries']);

        $planned = planRun(['sites' => ['el', 'de'], 'collections' => ['articles'], 'user' => $user]);
        $unrestricted = planRun(['sites' => ['el', 'de'], 'collections' => ['articles']]);

        expect(array_map(itemLabel(...), $planned->items))->toBe(['hello→el', 'world→el'])
            ->and($planned->skipped)->toBe(['unauthorized' => 2])
            ->and($planned->run->createdBy['id'])->toBe((string) $user->id())
            ->and($unrestricted->items)->toHaveCount(4);
    });

    it('refuses unknown sites, collections and entries', function (array $request, string $exception, string $message) {
        expect(fn () => planRun($request))->toThrow($exception, $message);
    })->with([
        'site' => [['sites' => ['fr'], 'collections' => ['articles']], UnknownSite::class, 'Unknown site [fr].'],
        'source site' => [['sites' => ['el'], 'collections' => ['articles'], 'sourceSite' => 'fr'], UnknownSite::class, 'Unknown site [fr].'],
        'collection' => [['sites' => ['el'], 'collections' => ['blog']], InvalidArgumentException::class, 'Collection [blog] not found.'],
        'entry' => [['sites' => ['el'], 'entryIds' => ['missing-id']], TargetNotFound::class, 'Entry [missing-id] not found.'],
    ]);
});

describe('terms', function () {
    beforeEach(function () {
        $this->makeTaxonomy('topics', ['en', 'el', 'de'], [
            'title' => ['type' => 'text', 'localizable' => true],
            'description' => ['type' => 'textarea', 'localizable' => true],
            'internal' => ['type' => 'textarea', 'display' => 'Internal'],
        ]);
        $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food', 'description' => 'Eat well.']]);
        $this->makeTerm('topics', 'wellness', ['en' => ['title' => 'Wellness']]);
    });

    it('adds one item per term and site after the entry items, with the next indexes', function () {
        $planned = planRun(['sites' => ['el', 'de'], 'collections' => ['articles'], 'taxonomies' => ['topics']]);
        $terms = array_slice($planned->items, 4);

        expect(array_map(fn (RunItem $item) => [$item->index, $item->type, $item->sourceId, $item->targetSite, $item->title], $terms))->toBe([
            [4, ContentType::Term, 'topics::food', 'el', 'Food'],
            [5, ContentType::Term, 'topics::food', 'de', 'Food'],
            [6, ContentType::Term, 'topics::wellness', 'el', 'Wellness'],
            [7, ContentType::Term, 'topics::wellness', 'de', 'Wellness'],
        ])
            ->and($terms[0])->toMatchObject(['stage' => 0, 'dependsOn' => [], 'status' => RunItemStatus::Pending])
            ->and($planned->skipped)->toBe([])
            ->and($planned->run->scope['taxonomies'])->toBe(['topics'])
            ->and($planned->run->scope['out_of_scope'])->toBe([
                ['container' => 'topics', 'handle' => 'internal', 'label' => 'Internal', 'type' => 'textarea', 'reason' => 'not_localizable'],
            ]);
    });

    it('resolves selected terms in any site, each once, and never targets the taxonomy\'s default site', function () {
        $this->makeTaxonomy('tags', ['el', 'en'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeTerm('tags', 'sea', ['el' => ['title' => 'Θάλασσα']]);

        $planned = planRun(['sites' => ['en', 'el'], 'termIds' => ['tags::sea', 'topics::food', 'tags::sea']]);

        expect(array_map(fn (RunItem $item) => $item->sourceId.'→'.$item->targetSite, $planned->items))->toBe(['tags::sea→en', 'topics::food→el'])
            ->and($planned->run->scope['term_ids'])->toBe(['tags::sea', 'topics::food', 'tags::sea']);
    });

    it('reports excluded taxonomies and taxonomies without a requested site, and leaves single-site taxonomies out of all', function () {
        $this->withSettings(['excluded_taxonomies' => ['tags']]);
        $this->makeTaxonomy('tags', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeTaxonomy('places', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeTaxonomy('labels', ['en'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeTerm('tags', 'sea', ['en' => ['title' => 'Sea']]);
        $this->makeTerm('places', 'chania', ['en' => ['title' => 'Chania']]);
        $this->makeTerm('labels', 'new', ['en' => ['title' => 'New']]);

        $named = planRun(['sites' => ['el', 'de'], 'taxonomies' => ['tags', 'places']]);
        $all = planRun(['sites' => ['el', 'de'], 'taxonomies' => ['*']]);
        $single = planRun(['sites' => ['el'], 'taxonomies' => ['labels']]);
        $label = fn (RunItem $item) => $item->sourceId.'→'.$item->targetSite;

        expect(array_map($label, $named->items))->toBe(['places::chania→el'])
            ->and($named->skipped)->toBe(['excluded_taxonomy' => 2, 'site_not_in_taxonomy' => 1])
            ->and(array_map($label, $all->items))->toEqualCanonicalizing(['topics::food→el', 'topics::food→de', 'topics::wellness→el', 'topics::wellness→de', 'places::chania→el'])
            ->and(collect($all->skipped)->sortKeys()->all())->toBe(['excluded_taxonomy' => 2, 'site_not_in_taxonomy' => 1])
            ->and($single->items)->toBe([])
            ->and($single->skipped)->toBe(['site_not_in_taxonomy' => 1]);
    });

    it('drops the terms a user may not edit, or in a site the user may not access, and counts them', function () {
        $user = $this->signInWithPermissions(['access en site', 'access el site', 'view topics terms', 'edit topics terms']);

        $planned = planRun(['sites' => ['el', 'de'], 'taxonomies' => ['topics'], 'user' => $user]);

        expect(array_map(fn (RunItem $item) => $item->sourceId.'→'.$item->targetSite, $planned->items))->toBe(['topics::food→el', 'topics::wellness→el'])
            ->and($planned->skipped)->toBe(['unauthorized' => 2]);
    });

    it('refuses unknown taxonomies and terms', function (array $request, string $exception, string $message) {
        expect(fn () => planRun($request))->toThrow($exception, $message);
    })->with([
        'taxonomy' => [['sites' => ['el'], 'taxonomies' => ['colours']], InvalidArgumentException::class, 'Taxonomy [colours] not found.'],
        'term' => [['sites' => ['el'], 'termIds' => ['topics::drink']], TargetNotFound::class, 'Term [topics::drink] not found.'],
    ]);

    it('estimates the terms with the entries and skips a translated term', function () {
        $this->fakeTranslations();
        $options = PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['profile' => 'background']);
        app(TargetTranslator::class)->translate(app(TargetFactory::class)->forTerm('topics::food', 'el'), $options, new ApplyOptions);
        $calls = $this->modelCalls();
        $run = storedRun(planRun(['sites' => ['el'], 'entryIds' => [$this->hello->id()], 'taxonomies' => ['topics']]));

        $progress = app(RunPlanner::class)->estimate($run, 0, 60_000);

        expect($progress->estimate->segments)->toBe(3)
            ->and($progress->estimate->chars)->toBe(mb_strlen('HelloA short intro.Wellness'))
            ->and(app(RunRepository::class)->item($run, 1))->toMatchObject(['type' => ContentType::Term, 'status' => RunItemStatus::Skipped, 'reason' => 'nothing_to_do'])
            ->and(app(RunRepository::class)->item($run, 2)->status)->toBe(RunItemStatus::Pending)
            ->and($this->modelCalls())->toBe($calls);
    });
});

describe('global sets', function () {
    beforeEach(function () {
        $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en', 'de' => 'en'], [
            'tagline' => ['type' => 'text', 'localizable' => true],
            'phone' => ['type' => 'text', 'display' => 'Phone'],
        ], ['en' => ['tagline' => 'By the sea', 'phone' => '+30 1234']]);
    });

    it('adds one item per set and site after the term items, with the next indexes', function () {
        $this->makeTaxonomy('topics', ['en', 'el', 'de'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food']]);

        $planned = planRun(['sites' => ['el', 'de'], 'collections' => ['articles'], 'taxonomies' => ['topics'], 'globals' => ['site_settings']]);
        $globals = array_slice($planned->items, 6);

        expect(array_map(fn (RunItem $item) => $item->type, array_slice($planned->items, 4, 2)))->toBe([ContentType::Term, ContentType::Term])
            ->and(array_map(fn (RunItem $item) => [$item->index, $item->type, $item->sourceId, $item->targetSite, $item->title], $globals))->toBe([
                [6, ContentType::GlobalSet, 'site_settings', 'el', 'Site Settings'],
                [7, ContentType::GlobalSet, 'site_settings', 'de', 'Site Settings'],
            ])
            ->and($globals[0])->toMatchObject(['stage' => 0, 'dependsOn' => [], 'status' => RunItemStatus::Pending])
            ->and($globals[1]->dependsOn)->toBe([])
            ->and($planned->skipped)->toBe([])
            ->and($planned->run->scope['globals'])->toBe(['site_settings'])
            ->and($planned->run->scope['out_of_scope'])->toBe([
                ['container' => 'site_settings', 'handle' => 'phone', 'label' => 'Phone', 'type' => 'text', 'reason' => 'not_localizable'],
            ]);
    });

    it('leaves the source site out and reports a site the set lacks, a site without an origin and a set without a blueprint', function () {
        $fields = ['tagline' => ['type' => 'text', 'localizable' => true]];
        $this->makeGlobalSet('footer', ['en' => null, 'el' => 'en'], $fields, ['en' => ['tagline' => 'Open all year.']]);
        $this->makeGlobalSet('legal', ['en' => null, 'el' => 'en', 'de' => null], $fields, ['en' => ['tagline' => 'Terms apply.']]);
        $this->makeGlobalSet('contact', ['en' => null, 'el' => 'en', 'de' => 'en'], null, ['en' => ['address' => 'Chania harbour']]);
        $this->makeGlobalSet('brand', ['en' => null], $fields, ['en' => ['tagline' => 'Kyma Bay']]);
        $label = fn (RunItem $item) => $item->sourceId.'→'.$item->targetSite;

        $named = planRun(['sites' => ['en', 'el', 'de'], 'globals' => ['site_settings', 'footer', 'legal', 'contact']]);
        $all = planRun(['sites' => ['el', 'de'], 'globals' => ['*']]);
        $single = planRun(['sites' => ['el'], 'globals' => ['brand']]);

        expect(array_map($label, $named->items))->toBe(['site_settings→el', 'site_settings→de', 'footer→el', 'legal→el'])
            ->and($named->skipped)->toBe(['site_not_in_global_set' => 1, 'no_origin' => 1, 'no_blueprint' => 2])
            ->and(array_map($label, $all->items))->toEqualCanonicalizing(['site_settings→el', 'site_settings→de', 'footer→el', 'legal→el'])
            ->and(collect($all->skipped)->sortKeys()->all())->toBe(['no_blueprint' => 2, 'no_origin' => 1, 'site_not_in_global_set' => 1])
            ->and($single->items)->toBe([])
            ->and($single->skipped)->toBe(['site_not_in_global_set' => 1]);
    });

    it('puts a site after the site it is translated from and makes it wait for that item', function () {
        GlobalSet::findByHandle('site_settings')->sites(['en' => null, 'de' => 'el', 'el' => 'en'])->save();

        $planned = planRun(['sites' => ['de', 'el'], 'globals' => ['site_settings']]);

        expect(array_map(fn (RunItem $item) => [$item->targetSite, $item->dependsOn], $planned->items))->toBe([['el', []], ['de', [0]]]);
    });

    it('drops the variables a user may not edit, or in a site the user may not access, and counts them', function () {
        $user = $this->signInWithPermissions(['access en site', 'access el site', 'edit site_settings globals']);

        $planned = planRun(['sites' => ['el', 'de'], 'globals' => ['site_settings'], 'user' => $user]);

        expect(array_map(fn (RunItem $item) => $item->sourceId.'→'.$item->targetSite, $planned->items))->toBe(['site_settings→el'])
            ->and($planned->skipped)->toBe(['unauthorized' => 1]);
    });

    it('refuses an unknown global set', function () {
        planRun(['sites' => ['el'], 'globals' => ['colours']]);
    })->throws(InvalidArgumentException::class, 'Global set [colours] not found.');

    it('estimates the global sets with the entries and skips translated variables', function () {
        $this->fakeTranslations();
        $options = PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['profile' => 'background']);
        app(TargetTranslator::class)->translate(app(TargetFactory::class)->forGlobalSet('site_settings', 'el'), $options, new ApplyOptions);
        $calls = $this->modelCalls();
        $run = storedRun(planRun(['sites' => ['el', 'de'], 'entryIds' => [$this->hello->id()], 'globals' => ['site_settings']]));

        $progress = app(RunPlanner::class)->estimate($run, 0, 60_000);

        expect($progress->estimate->segments)->toBe(5)
            ->and(app(RunRepository::class)->item($run, 2))->toMatchObject(['type' => ContentType::GlobalSet, 'targetSite' => 'el', 'status' => RunItemStatus::Skipped, 'reason' => 'nothing_to_do'])
            ->and(app(RunRepository::class)->item($run, 3)->status)->toBe(RunItemStatus::Pending)
            ->and($this->modelCalls())->toBe($calls);
    });
});

describe('navigation', function () {
    beforeEach(function () {
        $this->makeNav('main', ['en' => [
            ['id' => 'hello', 'entry' => $this->hello->id()],
            ['id' => 'book', 'title' => 'Book your stay', 'url' => 'https://booking.example'],
        ]]);
    });

    it('adds one item per nav and site after the other items, of stage 1, translated from the default site\'s tree', function () {
        $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en'], ['tagline' => ['type' => 'text', 'localizable' => true]], ['en' => ['tagline' => 'By the sea']]);

        $planned = planRun(['sites' => ['el', 'de'], 'entryIds' => [$this->hello->id()], 'globals' => ['site_settings'], 'navs' => ['main']]);

        expect(array_map(fn (RunItem $item) => [$item->index, $item->type, $item->sourceId, $item->targetSite, $item->title, $item->stage], $planned->items))->toBe([
            [0, ContentType::Entry, $this->hello->id(), 'el', 'Hello', 0],
            [1, ContentType::Entry, $this->hello->id(), 'de', 'Hello', 0],
            [2, ContentType::GlobalSet, 'site_settings', 'el', 'Site Settings', 0],
            [3, ContentType::Nav, 'main::en', 'el', 'Main', 1],
            [4, ContentType::Nav, 'main::en', 'de', 'Main', 1],
        ])
            ->and($planned->items[3]->dependsOn)->toBe([])
            ->and($planned->skipped)->toBe(['site_not_in_global_set' => 1])
            ->and($planned->run->scope['navs'])->toBe(['main']);
    });

    it('translates from the source site\'s tree where the nav has one, leaves the source site out and reports a nav without a source tree', function () {
        $this->makeNav('footer', ['de' => [['id' => 'legal', 'title' => 'Impressum']]]);
        $label = fn (RunItem $item) => $item->sourceId.'→'.$item->targetSite;

        $fromDefault = planRun(['sites' => ['en', 'el', 'de'], 'navs' => ['main', 'footer']]);
        $fromGerman = planRun(['sites' => ['en', 'el'], 'sourceSite' => 'de', 'navs' => ['main', 'footer']]);
        $intoGerman = planRun(['sites' => ['el', 'de'], 'sourceSite' => 'de', 'navs' => ['main', 'footer']]);
        $all = planRun(['sites' => ['el'], 'navs' => ['*']]);

        expect(array_map($label, $fromDefault->items))->toBe(['main::en→el', 'main::en→de'])
            ->and($fromDefault->skipped)->toBe(['no_source_tree' => 2])
            ->and(array_map($label, $fromGerman->items))->toBe(['main::en→el', 'footer::de→en', 'footer::de→el'])
            ->and($fromGerman->skipped)->toBe([])
            ->and(array_map($label, $intoGerman->items))->toBe(['main::en→el', 'main::en→de', 'footer::de→el'])
            ->and($intoGerman->skipped)->toBe(['from_site' => 1])
            ->and(array_map($label, $all->items))->toEqualCanonicalizing(['main::en→el'])
            ->and($all->skipped)->toBe(['no_source_tree' => 1]);
    });

    it('translates a tree without a source site named from the site its state records, after that site\'s item, and from the default site\'s once that site has no tree', function () {
        Nav::findByHandle('main')->makeTree('de', [['id' => 'book', 'title' => 'Aufenthalt buchen', 'url' => 'https://booking.example']])->save();
        app(StateRepository::class)->update(new TargetRef(ContentType::Nav, 'main', 'el'), fn (ItemState $state) => $state->withField(NavTarget::TREE, new FieldState(sourceHash: 'a1b2c3d4e5f60718'))->withSourceSite('de'));
        $label = fn (RunItem $item) => $item->sourceId.'→'.$item->targetSite;

        $recorded = planRun(['sites' => ['el', 'de'], 'navs' => ['main']]);
        $named = planRun(['sites' => ['el'], 'sourceSite' => 'en', 'navs' => ['main']]);
        Nav::findByHandle('main')->in('de')->deleteQuietly();
        $withoutTree = planRun(['sites' => ['el'], 'navs' => ['main']]);

        expect(array_map($label, $recorded->items))->toBe(['main::en→de', 'main::de→el'])
            ->and(array_map(fn (RunItem $item) => $item->dependsOn, $recorded->items))->toBe([[], [0]])
            ->and(array_map($label, $named->items))->toBe(['main::en→el'])
            ->and(array_map($label, $withoutTree->items))->toBe(['main::en→el']);
    });

    it('drops the trees a user may not edit, or in a site the user may not access, and counts them', function () {
        $user = $this->signInWithPermissions(['access en site', 'access el site', 'edit main nav']);
        $this->makeNav('footer', ['en' => [['id' => 'legal', 'title' => 'Legal']]]);

        $planned = planRun(['sites' => ['el', 'de'], 'navs' => ['main', 'footer'], 'user' => $user]);

        expect(array_map(fn (RunItem $item) => $item->sourceId.'→'.$item->targetSite, $planned->items))->toBe(['main::en→el'])
            ->and($planned->skipped)->toBe(['unauthorized' => 3]);
    });

    it('refuses an unknown navigation', function () {
        planRun(['sites' => ['el'], 'navs' => ['footer']]);
    })->throws(InvalidArgumentException::class, 'Navigation [footer] not found.');

    it('applies the nav of a run with entries last, with the localizations the run created', function () {
        $this->fakeTranslations();
        $saved = [];
        Event::listen(EntrySaved::class, function (EntrySaved $event) use (&$saved) {
            $saved[] = 'entry';
        });
        Event::listen(NavTreeSaved::class, function (NavTreeSaved $event) use (&$saved) {
            $saved[] = 'nav:'.$event->tree->locale();
        });
        $run = storedRun(planRun(['sites' => ['el'], 'collections' => ['articles'], 'navs' => ['main']]));

        app(RunProcessor::class)->start($run);
        RunFixtures::finish($run);

        expect(array_map(fn (RunItem $item) => [$item->type, $item->status], RunFixtures::items($run)))->toBe([
            [ContentType::Entry, RunItemStatus::Done],
            [ContentType::Entry, RunItemStatus::Done],
            [ContentType::Nav, RunItemStatus::Done],
        ])
            ->and(array_values(array_unique($saved)))->toBe(['entry', 'nav:el'])
            ->and(array_slice($saved, -1))->toBe(['nav:el'])
            ->and(Nav::findByHandle('main')->in('el')->tree())->toBe([
                ['id' => 'hello', 'entry' => $this->hello->in('el')->id()],
                ['id' => 'book', 'title' => '[T] Book your stay', 'url' => 'https://booking.example'],
            ]);
    });
});

describe('pages', function () {
    it('orders pages by their tree and makes a new child depend on its parent item in the same site', function () {
        $this->makePages(['home', 'rooms' => ['suites' => ['honeymoon']], 'dining'], root: false, creationOrder: ['honeymoon', 'dining', 'suites', 'rooms', 'home']);

        expect(itemDependencies(planRun(['sites' => ['el', 'de'], 'collections' => ['pages']])))->toBe([
            'home→el' => [],
            'home→de' => [],
            'rooms→el' => [],
            'rooms→de' => [],
            'suites→el' => ['rooms→el'],
            'suites→de' => ['rooms→de'],
            'honeymoon→el' => ['suites→el'],
            'honeymoon→de' => ['suites→de'],
            'dining→el' => [],
            'dining→de' => [],
        ]);
    });

    it('makes a page wait only to be placed under a parent the run creates, and warns about a parent that will not exist', function () {
        $pages = $this->makePages(['home', 'rooms' => ['suites' => ['honeymoon']]], root: false);
        $this->localize($pages['rooms'], 'el', ['title' => 'Δωμάτια']);
        $this->localize($pages['suites'], 'de', ['title' => 'Suiten']);

        $planned = planRun(['sites' => ['el', 'de'], 'entryIds' => [$pages['suites']->id(), $pages['rooms']->id()]]);
        $alone = planRun(['sites' => ['el', 'de'], 'entryIds' => [$pages['honeymoon']->id()]]);

        // A localization that exists is not placed again, so it waits for nothing.
        expect(itemDependencies($planned))->toBe([
            'rooms→el' => [],
            'rooms→de' => [],
            'suites→el' => [],
            'suites→de' => [],
        ])
            ->and(array_merge(...array_map(fn (RunItem $item) => $item->warnings, $planned->items)))->toBe([])
            ->and(itemDependencies($alone))->toBe(['honeymoon→el' => [], 'honeymoon→de' => []])
            ->and($alone->items[0]->warnings)->toBe(['Its parent page, Suites, has no localization in Greek, so this page will be placed at the top level.'])
            ->and($alone->items[1]->warnings)->toBe([]);
    });

    it('makes every new page of a collection with a root depend on the root item of its site', function () {
        $this->makePages(['home', 'rooms' => ['suites'], 'dining']);

        expect(itemDependencies(planRun(['sites' => ['el', 'de'], 'collections' => ['pages']])))->toBe([
            'home→el' => [],
            'home→de' => [],
            'rooms→el' => ['home→el'],
            'rooms→de' => ['home→de'],
            'suites→el' => ['home→el', 'rooms→el'],
            'suites→de' => ['home→de', 'rooms→de'],
            'dining→el' => ['home→el'],
            'dining→de' => ['home→de'],
        ]);
    });

    it('skips the pages with root_missing when the root is neither in the run nor in the target site', function () {
        $pages = $this->makePages(['home', 'rooms' => ['suites']]);
        $this->localize($pages['home'], 'de', ['title' => 'Startseite']);

        $planned = planRun(['sites' => ['el', 'de'], 'entryIds' => [$pages['suites']->id(), $pages['rooms']->id()]]);

        expect(itemDependencies($planned))->toBe([
            'rooms→el' => [],
            'rooms→de' => [],
            'suites→el' => [],
            'suites→de' => ['rooms→de'],
        ])
            ->and($planned->items[0])->toMatchObject([
                'status' => RunItemStatus::Skipped,
                'reason' => 'root_missing',
                'error' => 'Translate Home into Greek first. The Pages collection has no Greek entries yet, and the first one saved becomes its Greek home page, so translating the home page first keeps the structure of the collection\'s tree.',
                'rootPage' => ['title' => 'Home', 'edit_url' => $pages['home']->editUrl()],
            ])
            ->and($planned->items[2])->toMatchObject(['status' => RunItemStatus::Skipped, 'reason' => 'root_missing', 'rootPage' => $planned->items[0]->rootPage])
            ->and($planned->items[1]->status)->toBe(RunItemStatus::Pending)
            ->and($planned->items[3]->status)->toBe(RunItemStatus::Pending);
    });

    it('places a page under the source site\'s tree, waiting for the parent item built from it', function () {
        $pages = $this->makePages(['home', 'rooms' => ['suites']], root: false);

        foreach (['home', 'rooms', 'suites'] as $slug) {
            $this->localize($pages[$slug], 'el', ['title' => "{$slug} (el)"]);
        }

        expect(itemDependencies(planRun(['sites' => ['de'], 'collections' => ['pages'], 'sourceSite' => 'el'])))->toBe([
            'home→de (from el)' => [],
            'rooms→de (from el)' => [],
            'suites→de (from el)' => ['rooms→de (from el)'],
        ]);
    });

    it('puts a child of the root page at the top level of a tree that is not empty, without waiting or warning', function () {
        $pages = $this->makePages(['home', 'rooms', 'dining']);
        $this->localize($pages['dining'], 'de', ['title' => 'Speisen']);

        $planned = planRun(['sites' => ['de'], 'entryIds' => [$pages['rooms']->id()]]);

        expect($planned->items[0])->toMatchObject(['status' => RunItemStatus::Pending, 'dependsOn' => [], 'warnings' => []]);
    });

    it('neither skips nor orders pages for a review, which creates no localization', function () {
        $pages = $this->makePages(['home', 'rooms' => ['suites']]);

        $planned = planRun(['sites' => ['el'], 'collections' => ['pages'], 'options' => ['mode' => 'review']]);
        $alone = planRun(['sites' => ['el'], 'entryIds' => [$pages['suites']->id()], 'options' => ['mode' => 'review']]);

        expect(itemDependencies($planned))->toBe(['home→el' => [], 'rooms→el' => [], 'suites→el' => []])
            ->and($alone->items[0])->toMatchObject(['status' => RunItemStatus::Pending, 'warnings' => []]);
    });
});

describe('large collections', function () {
    it('authorizes every target of a family on the root it loaded, without finding the root again per target', function () {
        $first = [];
        $same = [];

        Gate::before(function ($user, string $ability, array $arguments) use (&$first, &$same) {
            $entry = $arguments[0] ?? null;

            if ($ability !== 'edit' || ! $entry instanceof EntryContract) {
                return null;
            }

            $id = (string) $entry->id();

            if (isset($first[$id])) {
                $same[$id] = $first[$id]->get() === $entry;
            } else {
                $first[$id] = WeakReference::create($entry);
            }

            return null;
        });

        planRun(['sites' => ['el', 'de'], 'collections' => ['articles'], 'user' => $this->signInAdmin()]);

        expect($same)->toBe([$this->hello->id() => true, $this->world->id() => true]);
    });

    it('holds no entry of the chunks of families it has listed while it lists the next, but the one core\'s shared blueprint keeps', function () {
        $rootOf = [];

        foreach (range(1, EntryFamilies::CHUNK) as $number) {
            $root = $this->makeEntry('articles', 'en', ['title' => "More {$number}", 'intro' => 'More.'], "more-{$number}");
            $rootOf[(string) $this->localize($root, 'el', ['title' => "Κι άλλο {$number}"])->id()] = (string) $root->id();
        }

        $ids = array_map(fn (EntryContract $root) => (string) $root->id(), [...app(EntryFamilies::class)->roots(['articles'], [])]);
        $firstChunk = array_flip(array_slice($ids, 0, EntryFamilies::CHUNK));
        $held = [];
        $alive = null;

        // Called with each target's localization, or its source when the localization is missing.
        Gate::before(function ($user, string $ability, array $arguments) use ($rootOf, $ids, $firstChunk, &$held, &$alive) {
            $entry = $arguments[0] ?? null;

            if ($ability !== 'edit' || ! $entry instanceof EntryContract) {
                return null;
            }

            $root = $rootOf[(string) $entry->id()] ?? (string) $entry->id();

            if ($alive === null && $root === $ids[EntryFamilies::CHUNK]) {
                gc_collect_cycles();
                $alive = collect($held)->map(fn (WeakReference $reference) => $reference->get())->filter()->unique(fn (EntryContract $entry) => spl_object_id($entry))->count();
            }

            if (isset($firstChunk[$root])) {
                $held[] = WeakReference::create($entry);
            }

            return null;
        });

        $planned = planRun(['sites' => ['el', 'de'], 'collections' => ['articles'], 'user' => $this->signInAdmin()]);

        // A collection's entries share one blueprint, whose fields keep the entry they were first read for.
        expect($planned->items)->toHaveCount(2 * (EntryFamilies::CHUNK + 2))
            ->and($held)->toHaveCount(2 * EntryFamilies::CHUNK)
            ->and($alive)->toBeLessThanOrEqual(1);
    });

    it('makes a page of a later chunk of families wait for the root and parent items of an earlier one', function () {
        $rooms = array_map(fn (int $number) => "room-{$number}", range(1, EntryFamilies::CHUNK));
        $this->makePages(['home', 'rooms' => $rooms]);

        $dependencies = itemDependencies(planRun(['sites' => ['el'], 'collections' => ['pages']]));

        expect($dependencies)->toHaveCount(EntryFamilies::CHUNK + 2)
            ->and($dependencies['room-'.EntryFamilies::CHUNK.'→el'])->toBe(['home→el', 'rooms→el']);
    });
});

describe('estimate', function () {
    beforeEach(function () {
        $this->fakeTranslations();
        translateArticle($this->hello, 'el');
        $this->callsBefore = $this->modelCalls();
    });

    it('reaches the same total in small steps as in one call, and marks fully translated targets skipped', function () {
        $planner = app(RunPlanner::class);
        $stepped = storedRun(planRun(['sites' => ['el', 'de'], 'collections' => ['articles']]));
        $single = storedRun(planRun(['sites' => ['el', 'de'], 'collections' => ['articles']]));
        $steps = 0;
        $next = 0;

        do {
            $progress = $planner->estimate($stepped, $next, 0);
            $next = $progress->next;
            $steps++;
        } while ($next !== null && $steps < 10);

        $once = $planner->estimate($single, 0, 60_000);

        expect($steps)->toBe(4)
            ->and($progress->isFinished())->toBeTrue()
            ->and($progress->estimate->toArray())->toBe($once->estimate->toArray())
            ->and($once->next)->toBeNull()
            ->and($once->estimate->segments)->toBe(6)
            ->and($once->estimate->chars)->toBe(mb_strlen('HelloA short intro.') + 2 * mb_strlen('WorldAnother intro.'))
            ->and($once->estimate->cost)->toBeGreaterThan(0.0)
            ->and(app(RunRepository::class)->find($stepped->id)->estimate)->toBe([...$progress->estimate->toArray(), 'next' => null]);

        foreach ([$stepped, $single] as $run) {
            expect(app(RunRepository::class)->item($run, 0))->toMatchObject(['status' => RunItemStatus::Skipped, 'reason' => 'nothing_to_do'])
                ->and(app(RunRepository::class)->counts($run))->toMatchArray(['pending' => 3, 'skipped' => 1]);
        }

        expect($this->modelCalls())->toBe($this->callsBefore);
    });

    it('passes each item it plans with its plan, and keeps the reasons of the fields on an item it skips', function () {
        $run = storedRun(planRun(['sites' => ['el', 'de'], 'collections' => ['articles']]));
        $planned = [];

        app(RunPlanner::class)->estimate($run, 0, 60_000, function (RunItem $item, Plan $plan) use (&$planned) {
            $planned[$item->index] = [$item->sourceId, $item->targetSite, $plan->fieldHandles()];
        });

        expect($planned)->toBe([
            0 => [$this->hello->id(), 'el', []],
            1 => [$this->hello->id(), 'de', ['title', 'intro']],
            2 => [$this->world->id(), 'el', ['title', 'intro']],
            3 => [$this->world->id(), 'de', ['title', 'intro']],
        ])
            ->and(app(RunRepository::class)->item($run, 0)->fields)->toBe(['title' => 'current', 'intro' => 'current'])
            ->and(app(RunRepository::class)->item($run, 1)->fields)->toBe([]);
    });

    it('starts over from index 0 and adds nothing for a call that does not continue where the last one stopped', function () {
        $planner = app(RunPlanner::class);
        $run = storedRun(planRun(['sites' => ['el', 'de'], 'collections' => ['articles']]));

        $first = $planner->estimate($run, 0, 0);
        $first = $planner->estimate($run, $first->next, 0);
        $stale = $planner->estimate($run, 3, 60_000);
        $retried = $planner->estimate($run, 1, 60_000);

        expect($first->next)->toBe(2)
            ->and($stale->toArray())->toBe($first->toArray())
            ->and($retried->toArray())->toBe($first->toArray());

        $total = $planner->estimate($run, 2, 60_000);
        $again = $planner->estimate($run, 0, 60_000);
        $late = $planner->estimate($run, 2, 60_000);

        expect($total->next)->toBeNull()
            ->and($again->toArray())->toBe($total->toArray())
            ->and($late->toArray())->toBe($total->toArray())
            ->and(app(RunRepository::class)->find($run->id)->estimate)->toBe($total->toArray());
    });

    it('estimates nothing for a run whose items are all skipped, and a stale copy of the run keeps its status', function () {
        $run = storedRun(planRun(['sites' => ['el'], 'entryIds' => [$this->hello->id()]]));
        $stale = app(RunRepository::class)->find($run->id);
        $run->status = RunStatus::Running;
        app(RunRepository::class)->save($run);

        $progress = app(RunPlanner::class)->estimate($stale, 0, 60_000);

        expect($progress->toArray())->toBe([...(new Estimate)->toArray(), 'next' => null])
            ->and(app(RunRepository::class)->find($run->id)->status)->toBe(RunStatus::Running)
            ->and(app(RunRepository::class)->item($run, 0)->status)->toBe(RunItemStatus::Skipped);
    });

    it('estimates a review run from review plans and skips the targets with nothing to review', function () {
        $planner = app(RunPlanner::class);
        $run = storedRun(planRun(['sites' => ['el'], 'collections' => ['articles'], 'options' => ['mode' => 'review']]));

        $progress = $planner->estimate($run, 0, 60_000);

        expect($progress->estimate->segments)->toBe(2)
            ->and($progress->estimate->outputTokens)->toBeGreaterThan(0)
            ->and(app(RunRepository::class)->item($run, 0)->status)->toBe(RunItemStatus::Pending)
            ->and(app(RunRepository::class)->item($run, 1))->toMatchObject(['status' => RunItemStatus::Skipped, 'reason' => 'nothing_to_do']);
    });

    it('keeps an item pending while its source waits to be translated, and skips it after a source that was skipped', function () {
        $greek = $this->hello->in('el');
        $this->localize($greek, 'de');
        translateArticle($greek, 'de');
        $planner = app(RunPlanner::class);
        $current = storedRun(planRun(['sites' => ['el', 'de'], 'entryIds' => [$this->hello->id()], 'options' => ['mode' => 'update']]));

        $planner->estimate($current, 0, 60_000);

        expect(RunFixtures::statuses($current))->toBe(['skipped', 'skipped']);

        $this->hello->set('title', 'Hello there')->save();
        $run = storedRun(planRun(['sites' => ['el', 'de'], 'entryIds' => [$this->hello->id()], 'options' => ['mode' => 'update']]));

        $planner->estimate($run, 0, 60_000);

        expect(RunFixtures::statuses($run))->toBe(['pending', 'pending']);

        app(RunProcessor::class)->start($run);
        RunFixtures::finish($run);

        expect(RunFixtures::statuses($run))->toBe(['done', 'done'])
            ->and(Entry::find($this->hello->id())->in('de')->value('title'))->toBe('[T] [T] Hello there');
    });

    it('keeps a navigation pending until the entries of its site are translated, and links the localizations they create', function () {
        $this->makeNav('main', ['en' => [['id' => 'world', 'entry' => $this->world->id(), 'title' => 'Our world']]]);
        $run = storedRun(planRun(['sites' => ['el'], 'entryIds' => [$this->world->id()], 'navs' => ['main']]));

        app(RunPlanner::class)->estimate($run, 0, 60_000);

        expect(RunFixtures::statuses($run))->toBe(['pending', 'pending']);

        app(RunProcessor::class)->start($run);
        RunFixtures::finish($run);

        expect(RunFixtures::statuses($run))->toBe(['done', 'done'])
            ->and(Nav::findByHandle('main')->in('el')->tree())->toBe([
                ['id' => 'world', 'entry' => $this->world->in('el')->id(), 'title' => '[T] Our world'],
            ]);
    });

    it('does not plan or skip an item a worker has taken', function () {
        $run = storedRun(planRun(['sites' => ['el'], 'entryIds' => [$this->hello->id(), $this->world->id()]]));
        $run->status = RunStatus::Running;
        app(RunRepository::class)->save($run);
        app(RunRepository::class)->claimNext($run, 'worker-1', 60);
        app(RunRepository::class)->claimNext($run, 'worker-2', 60);

        $progress = app(RunPlanner::class)->estimate($run, 0, 60_000);

        expect($progress->estimate->segments)->toBe(0)
            ->and(app(RunRepository::class)->counts($run))->toMatchArray(['translating' => 2, 'skipped' => 0]);
    });

    it('holds no entry of the items it has planned while it plans the next, and keeps what was memoized before', function () {
        foreach (range(1, 10) as $number) {
            $this->localize($this->makeEntry('articles', 'en', ['title' => "More {$number}", 'intro' => 'More.'], "more-{$number}"), 'el', ['title' => "Κι άλλο {$number}"]);
        }

        $run = storedRun(planRun(['sites' => ['el', 'de'], 'collections' => ['articles']]));
        Blink::flush();
        Blink::put('origin-Entry-earlier', 'kept');
        $held = [];
        $alive = [];

        // Called with each item while its entries are loaded: what core memoized for them meanwhile holds them.
        app(RunPlanner::class)->estimate($run, 0, 60_000, function () use (&$held, &$alive) {
            gc_collect_cycles();
            $alive[] = collect($held)->map(fn (WeakReference $reference) => $reference->get())->filter()->unique(fn (EntryContract $entry) => spl_object_id($entry))->count();
            $values = [...Blink::store()->all(), ...Blink::store('structure-entries')->all()];

            foreach ($values as $value) {
                foreach (is_iterable($value) ? $value : [$value] as $entry) {
                    if ($entry instanceof EntryContract) {
                        $held[] = WeakReference::create($entry);
                    }
                }
            }
        });

        // A collection's entries share one blueprint, whose fields keep the entry they were first read for.
        expect($alive)->toHaveCount(24)
            ->and(count($held))->toBeGreaterThan(24)
            ->and(max($alive))->toBeLessThanOrEqual(1)
            ->and(Blink::get('origin-Entry-earlier'))->toBe('kept');
    });
});
