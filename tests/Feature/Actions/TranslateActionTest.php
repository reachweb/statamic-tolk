<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Jobs\WorkRun;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunItem;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Facades\Action;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Term;

const TRANSLATE_ACTION = 'tolk_translate';

beforeEach(function () {
    $this->useFileCache();
    config(['tolk.runs.execution' => 'browser']);

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true],
    ]);

    $this->hello = $this->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'A short intro.'], 'hello');
    $this->world = $this->makeEntry('articles', 'en', ['title' => 'World', 'intro' => 'Another intro.'], 'world');
    $this->again = $this->makeEntry('articles', 'en', ['title' => 'Again', 'intro' => 'A third intro.'], 'again');
});

/**
 * The actions the CP lists for the selected entries, keyed by handle, as a listing or the tree loads them.
 *
 * @param  list<EntryContract>  $entries
 */
function listedActions(array $entries, array $context = ['collection' => 'articles']): array
{
    $collection = $entries[0]->collectionHandle();

    return collect(test()->postJson(cp_route('collections.entries.actions.bulk', $collection), [
        'selections' => array_map(fn (EntryContract $entry) => $entry->id(), $entries),
        'context' => $context,
    ])->assertOk()->json())->keyBy('handle')->all();
}

/**
 * Runs the action on the selected entries through the CP's action route.
 *
 * @param  list<EntryContract>  $entries
 */
function runTranslateAction(array $entries, array $values = [], array $context = ['collection' => 'articles']): TestResponse
{
    return test()->postJson(cp_route('collections.entries.actions.run', $entries[0]->collectionHandle()), [
        'action' => TRANSLATE_ACTION,
        'selections' => array_map(fn (EntryContract $entry) => $entry->id(), $entries),
        'context' => $context,
        'values' => ['sites' => ['el', 'de'], 'mode' => 'missing', ...$values],
    ]);
}

function onlyRun(): Run
{
    $runs = app(RunRepository::class)->recent(10);

    expect($runs)->toHaveCount(1);

    return $runs[0];
}

/**
 * The actions a term listing loads for the selected terms, keyed by handle.
 *
 * @param  list<TermContract>  $terms
 */
function listedTermActions(array $terms): array
{
    return collect(test()->postJson(cp_route('taxonomies.terms.actions.bulk', $terms[0]->taxonomyHandle()), [
        'selections' => array_map(fn (TermContract $term) => $term->id(), $terms),
        'context' => ['taxonomy' => $terms[0]->taxonomyHandle()],
    ])->assertOk()->json())->keyBy('handle')->all();
}

/**
 * @param  list<TermContract>  $terms
 */
function runTermAction(array $terms, array $values = []): TestResponse
{
    return test()->postJson(cp_route('taxonomies.terms.actions.run', $terms[0]->taxonomyHandle()), [
        'action' => TRANSLATE_ACTION,
        'selections' => array_map(fn (TermContract $term) => $term->id(), $terms),
        'context' => ['taxonomy' => $terms[0]->taxonomyHandle()],
        'values' => ['sites' => ['el', 'de'], 'mode' => 'missing', ...$values],
    ]);
}

describe('visibility', function () {
    it('is offered for multi-site entries in list and tree view, with the sites and defaults in its fields', function () {
        $this->signInAdmin();

        $action = listedActions([$this->hello, $this->world])[TRANSLATE_ACTION];

        expect($action['title'])->toBe('Translate…')
            ->and($action['buttonText'])->toBe('Translate|Translate :count items')
            ->and(collect($action['fields'])->pluck('handle')->all())->toBe(['sites', 'mode', 'overwrite_manual', 'review', 'new_status'])
            ->and(collect($action['fields'])->firstWhere('handle', 'sites')['options'])->toBe(['en' => 'English', 'el' => 'Greek', 'de' => 'German'])
            ->and($action['values'])->toMatchArray([
                'sites' => ['el', 'de'],
                'mode' => 'missing',
                'overwrite_manual' => false,
                'review' => false,
                'new_status' => 'draft',
            ])
            ->and(listedActions([$this->hello], ['view' => 'tree']))->toHaveKey(TRANSLATE_ACTION);
    });

    it('takes the review and status defaults from the settings', function () {
        $this->withSettings(['review_enabled' => true, 'new_localization_status' => 'mirror']);
        $this->signInAdmin();

        expect(listedActions([$this->hello])[TRANSLATE_ACTION]['values'])->toMatchArray(['review' => true, 'new_status' => 'mirror']);
    });

    it('offers only the sites of the selected collections that the user may access', function () {
        $this->makeCollection('news', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $news = $this->makeEntry('news', 'en', ['title' => 'News']);
        $this->signInWithPermissions(['access cp', 'run bulk translations', 'access en site', 'access el site', 'access de site', 'edit news entries']);

        $sites = collect(listedActions([$news], ['collection' => 'news'])[TRANSLATE_ACTION]['fields'])->firstWhere('handle', 'sites');

        expect($sites['options'])->toBe(['en' => 'English', 'el' => 'Greek']);

        $this->signInWithPermissions(['access cp', 'run bulk translations', 'access en site', 'access el site', 'edit articles entries']);
        $action = listedActions([$this->hello])[TRANSLATE_ACTION];

        expect(collect($action['fields'])->firstWhere('handle', 'sites')['options'])->toBe(['en' => 'English', 'el' => 'Greek'])
            ->and($action['values']['sites'])->toBe(['el']);
    });

    it('is not offered in the publish form', function () {
        $this->signInAdmin();

        expect(listedActions([$this->hello], ['collection' => 'articles', 'view' => 'form']))->not->toHaveKey(TRANSLATE_ACTION)
            ->and(collect(Action::for($this->hello, ['collection' => 'articles', 'view' => 'form']))->map->handle()->all())->not->toContain(TRANSLATE_ACTION);
    });

    it('is not offered for a single-site collection or one the settings exclude', function () {
        $this->makeCollection('notes', ['en'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $note = $this->makeEntry('notes', 'en', ['title' => 'Note']);
        $this->signInAdmin();

        expect(listedActions([$note], ['collection' => 'notes']))->not->toHaveKey(TRANSLATE_ACTION);

        $this->withSettings(['excluded_collections' => ['articles']]);

        expect(listedActions([$this->hello]))->not->toHaveKey(TRANSLATE_ACTION);
    });

    it('is not offered for an asset', function () {
        $asset = Asset::make()->container(AssetContainer::make('assets'))->path('photo.jpg');

        expect(Action::get(TRANSLATE_ACTION)->context([])->visibleTo($asset))->toBeFalse()
            ->and(Action::get(TRANSLATE_ACTION)->context([])->visibleTo($this->hello))->toBeTrue();
    });

    it('is offered only to a user who may run bulk translations and edit the entries', function () {
        $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'edit articles entries']);

        expect(listedActions([$this->hello]))->not->toHaveKey(TRANSLATE_ACTION);

        $this->signInWithPermissions(['access cp', 'run bulk translations', 'access en site', 'access el site', 'view articles entries']);

        expect(listedActions([$this->hello]))->not->toHaveKey(TRANSLATE_ACTION);

        $this->signInWithPermissions(['access cp', 'run bulk translations', 'access en site', 'access el site', 'edit articles entries']);

        expect(listedActions([$this->hello]))->toHaveKey(TRANSLATE_ACTION);
    });
});

describe('running', function () {
    it('creates a pending run of the selected entries and redirects to its page', function () {
        $this->fakeTranslations();
        $admin = $this->signInAdmin();

        $response = runTranslateAction([$this->hello, $this->again], [
            'mode' => 'all',
            'overwrite_manual' => true,
            'review' => false,
            'new_status' => 'mirror',
        ])->assertOk();
        $run = onlyRun();

        expect($response->json('redirect'))->toBe(cp_route('tolk.runs.show', $run->id))
            ->and($run->status)->toBe(RunStatus::Pending)
            ->and($run->driver)->toBe(RunDriver::Browser)
            ->and($run->createdBy)->toBe(['id' => (string) $admin->id(), 'name' => 'admin@example.com'])
            ->and($run->options)->toMatchArray(['mode' => 'all', 'overwrite_manual' => true, 'review' => false, 'new_status' => 'mirror'])
            ->and($run->scope)->toMatchArray(['sites' => ['el', 'de'], 'collections' => [], 'entry_ids' => [$this->hello->id(), $this->again->id()]])
            ->and(array_map(fn (RunItem $item) => [$item->sourceId, $item->targetSite], RunFixtures::items($run)))->toBe([
                [$this->hello->id(), 'el'],
                [$this->hello->id(), 'de'],
                [$this->again->id(), 'el'],
                [$this->again->id(), 'de'],
            ])
            ->and(RunFixtures::statuses($run))->toBe(['pending', 'pending', 'pending', 'pending'])
            ->and($this->hello->fresh()->in('el'))->toBeNull();
        $this->assertNoModelCalls();
    });

    it('resolves a selected localization to its family', function () {
        $this->signInAdmin();
        $greek = $this->localize($this->world, 'el');

        runTranslateAction([$greek], ['sites' => ['de']], ['collection' => 'articles'])->assertOk()->assertJsonPath('redirect', cp_route('tolk.runs.show', onlyRun()->id));

        expect(array_map(fn (RunItem $item) => [$item->sourceId, $item->targetSite], RunFixtures::items(onlyRun())))->toBe([
            [$this->world->id(), 'de'],
        ]);
    });

    it('runs from the tree view', function () {
        $this->signInAdmin();
        $pages = $this->makePages(['home', 'about']);

        runTranslateAction([$pages['home']], ['sites' => ['el']], ['view' => 'tree'])->assertOk()->assertJsonPath('redirect', cp_route('tolk.runs.show', onlyRun()->id));

        expect(array_map(fn (RunItem $item) => [$item->sourceId, $item->targetSite, $item->status->value], RunFixtures::items(onlyRun())))->toBe([
            [$pages['home']->id(), 'el', 'pending'],
        ]);
    });

    it('takes the driver from the execution setting and starts nothing', function () {
        config(['tolk.runs.execution' => 'queue']);
        Queue::fake();
        $this->signInAdmin();

        runTranslateAction([$this->hello])->assertOk();

        expect(onlyRun()->driver)->toBe(RunDriver::Queue)
            ->and(onlyRun()->status)->toBe(RunStatus::Pending);
        Queue::assertNotPushed(WorkRun::class);
    });

    it('refuses a site the user may not access instead of leaving its targets out of the run', function () {
        $this->signInWithPermissions(['access cp', 'run bulk translations', 'access en site', 'access el site', 'edit articles entries']);

        runTranslateAction([$this->hello])->assertUnprocessable()->assertJsonValidationErrors('sites');
        runTranslateAction([$this->hello], ['sites' => ['fr']])->assertUnprocessable()->assertJsonValidationErrors('sites');

        expect(app(RunRepository::class)->recent(10))->toBe([]);

        runTranslateAction([$this->hello], ['sites' => ['el']])->assertOk();

        expect(array_map(fn (RunItem $item) => $item->targetSite, RunFixtures::items(onlyRun())))->toBe(['el'])
            ->and(onlyRun()->scope['skipped'])->toBe([]);
    });

    it('reports the error and does not redirect when no run could be created', function () {
        $this->signInAdmin();
        $this->withProviderKeys();

        $response = runTranslateAction([$this->hello])->assertOk();

        expect($response->json())->toMatchArray(['success' => false])
            ->and($response->json('message'))->toContain('No AI provider is configured')
            ->and($response->json())->not->toHaveKey('redirect')
            ->and(app(RunRepository::class)->recent(10))->toBe([]);
    });

    it('needs at least one site', function () {
        $this->signInAdmin();

        runTranslateAction([$this->hello], ['sites' => []])->assertUnprocessable()->assertJsonValidationErrors('sites');

        expect(app(RunRepository::class)->recent(10))->toBe([]);
    });

    it('refuses a user without the permission to run bulk translations', function () {
        $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'access de site', 'edit articles entries']);

        runTranslateAction([$this->hello])->assertForbidden();

        expect(app(RunRepository::class)->recent(10))->toBe([]);
    });

    it('refuses entries it is not offered for, since the action route checks no visibility', function () {
        $this->withSettings(['excluded_collections' => ['articles']]);
        $this->signInAdmin();

        runTranslateAction([$this->hello])->assertForbidden();

        expect(app(RunRepository::class)->recent(10))->toBe([]);
    });

    it('refuses a user who may not edit a selected entry', function () {
        $this->signInWithPermissions(['access cp', 'run bulk translations', 'access en site', 'access el site', 'access de site', 'view articles entries']);

        runTranslateAction([$this->hello])->assertForbidden();

        expect(app(RunRepository::class)->recent(10))->toBe([]);
    });
});

describe('terms', function () {
    beforeEach(function () {
        $this->makeTaxonomy('topics', ['en', 'el', 'de'], [
            'title' => ['type' => 'text', 'localizable' => true],
            'description' => ['type' => 'textarea', 'localizable' => true],
        ]);
        $this->food = Term::find($this->makeTerm('topics', 'food', ['en' => ['title' => 'Food']])->id());
        $this->wellness = Term::find($this->makeTerm('topics', 'wellness', ['en' => ['title' => 'Wellness']])->id());
    });

    it('is offered for the terms of a multi-site taxonomy, without the status of new localizations', function () {
        $this->signInAdmin();

        $action = listedTermActions([$this->food, $this->wellness])[TRANSLATE_ACTION];

        expect(collect($action['fields'])->pluck('handle')->all())->toBe(['sites', 'mode', 'overwrite_manual', 'review'])
            ->and(collect($action['fields'])->firstWhere('handle', 'sites')['options'])->toBe(['en' => 'English', 'el' => 'Greek', 'de' => 'German'])
            ->and($action['values']['sites'])->toBe(['el', 'de'])
            ->and($action['confirmationText'])->toBe('The next page shows an estimate before this term is translated.|The next page shows an estimate before these :count terms are translated.');
    });

    it('leaves the taxonomy\'s own first site out of the default sites', function () {
        $this->makeTaxonomy('tags', ['el', 'en'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $sea = Term::find($this->makeTerm('tags', 'sea', ['el' => ['title' => 'Θάλασσα']])->id());
        $this->signInAdmin();

        $action = listedTermActions([$sea])[TRANSLATE_ACTION];

        expect(collect($action['fields'])->firstWhere('handle', 'sites')['options'])->toBe(['en' => 'English', 'el' => 'Greek'])
            ->and($action['values']['sites'])->toBe(['en']);
    });

    it('is not offered for a single-site taxonomy, one the settings exclude, or a user who may not edit its terms', function () {
        $this->makeTaxonomy('labels', ['en'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $label = Term::find($this->makeTerm('labels', 'new', ['en' => ['title' => 'New']])->id());
        $this->signInAdmin();

        expect(listedTermActions([$label]))->not->toHaveKey(TRANSLATE_ACTION);

        $this->withSettings(['excluded_taxonomies' => ['topics']]);

        expect(listedTermActions([$this->food]))->not->toHaveKey(TRANSLATE_ACTION);

        $this->withSettings();
        $this->signInWithPermissions(['access cp', 'run bulk translations', 'access en site', 'access el site', 'view topics terms']);

        expect(listedTermActions([$this->food]))->not->toHaveKey(TRANSLATE_ACTION);

        $this->signInWithPermissions(['access cp', 'run bulk translations', 'access en site', 'access el site', 'view topics terms', 'edit topics terms']);

        expect(listedTermActions([$this->food]))->toHaveKey(TRANSLATE_ACTION);
    });

    it('creates a pending run of the selected terms and redirects to its page', function () {
        $this->signInAdmin();

        $response = runTermAction([$this->food, $this->wellness], ['mode' => 'update'])->assertOk();
        $run = onlyRun();

        expect($response->json('redirect'))->toBe(cp_route('tolk.runs.show', $run->id))
            ->and($run->status)->toBe(RunStatus::Pending)
            ->and($run->options['mode'])->toBe('update')
            ->and($run->scope)->toMatchArray(['sites' => ['el', 'de'], 'entry_ids' => [], 'term_ids' => ['topics::food', 'topics::wellness']])
            ->and(array_map(fn (RunItem $item) => [$item->type->value, $item->sourceId, $item->targetSite], RunFixtures::items($run)))->toBe([
                ['term', 'topics::food', 'el'],
                ['term', 'topics::food', 'de'],
                ['term', 'topics::wellness', 'el'],
                ['term', 'topics::wellness', 'de'],
            ]);
        $this->assertNoModelCalls();
    });

    it('refuses a user who may not edit the selected terms', function () {
        $this->signInWithPermissions(['access cp', 'run bulk translations', 'access en site', 'access el site', 'view topics terms']);

        runTermAction([$this->food], ['sites' => ['el']])->assertForbidden();

        expect(app(RunRepository::class)->recent(10))->toBe([]);
    });
});
