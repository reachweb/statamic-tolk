<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Providers;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Ai\UsageMap;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\UsageSource;
use Reach\StatamicTolk\Events\TargetTranslated;
use Reach\StatamicTolk\Runs\UsageLog;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\State\OverviewScanner;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Statamic\Events\StacheCleared;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Term;

beforeEach(function () {
    $this->useFileCache();
    $this->freezeSecond();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true],
    ]);

    $this->missing = $this->makeEntry('articles', 'en', ['title' => 'Missing'], 'missing');
    $this->localize($this->makeEntry('articles', 'en', ['title' => 'Untranslated'], 'untranslated'), 'el');

    $outdated = $this->localize($this->makeEntry('articles', 'en', ['title' => 'Outdated'], 'outdated'), 'el', ['title' => 'Παλιό']);
    StateFixtures::store($outdated, ['title' => StateFixtures::machine('Old', 'Παλιό')]);

    $attention = $this->localize($this->makeEntry('articles', 'en', ['title' => 'Attention'], 'attention'), 'el', ['title' => 'Χειρόγραφο']);
    StateFixtures::store($attention, ['title' => new FieldState(sourceHash: Fingerprint::of('Before'))]);

    $this->current = $this->localize($this->makeEntry('articles', 'en', ['title' => 'Current', 'intro' => 'An intro.'], 'current'), 'el', ['title' => 'Τρέχον', 'intro' => 'Μια εισαγωγή.']);
    StateFixtures::store($this->current, ['title' => StateFixtures::machine('Current', 'Τρέχον')]);

    $this->makeTaxonomy('topics', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
    $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food']]);
    $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en'], ['tagline' => ['type' => 'text', 'localizable' => true]], ['en' => ['tagline' => 'By the sea']]);
    $this->makeNav('main', ['en' => [['id' => 'book', 'title' => 'Book your stay', 'url' => 'https://booking.example']]]);
});

function overviewScan(array $body): TestResponse
{
    return test()->postJson(cp_route('tolk.overview.scan'), $body);
}

/**
 * Scans a cell through the endpoint until it is finished, as the page does. Returns the last answer and the calls made.
 *
 * @return array{0: array, 1: int}
 */
function scanCell(string $type, string $container, string $site, array $body = []): array
{
    $cursor = null;
    $calls = 0;

    do {
        $answer = overviewScan(['type' => $type, 'container' => $container, 'site' => $site, 'cursor' => $cursor, ...$body])->assertOk()->json();
        $cursor = $answer['cursor'];
        $calls++;
    } while ($cursor !== null && $calls < 50);

    return [$answer, $calls];
}

/**
 * Whether the finished cell is answered from the cache now.
 */
function cellCached(string $type, string $container, string $site): bool
{
    return overviewScan(compact('type', 'container', 'site'))->assertOk()->json('cached');
}

/**
 * Scans the four cells into Greek, so each is cached.
 */
function cacheGreekCells(): void
{
    foreach (GREEK_CELLS as [$type, $container]) {
        scanCell($type, $container, 'el');
    }
}

/**
 * @return array<string, bool> whether each Greek cell is answered from the cache
 */
function greekCellsCached(): array
{
    return collect(GREEK_CELLS)->mapWithKeys(fn (array $cell) => [$cell[1] => cellCached($cell[0], $cell[1], 'el')])->all();
}

const GREEK_CELLS = [['entry', 'articles'], ['term', 'topics'], ['globals', 'site_settings'], ['nav', 'main']];

describe('usage reset', function () {
    beforeEach(function () {
        $this->log = app(UsageLog::class);
        $this->log->add(ModelRef::fromString('openai:gpt-6.1-sol'), new Usage(input: 1000, output: 500, calls: 2), UsageSource::Run);
    });

    it('deletes the month\'s usage and answers it afresh', function () {
        $this->signInAdmin();
        $month = now()->format('Y-m');

        $this->postJson(cp_route('tolk.usage.reset'), ['month' => $month])
            ->assertOk()
            ->assertExactJson(['usage' => ['month' => $month, 'models' => [], 'labels' => []]]);

        expect($this->log->month())->toBe([]);
    });

    it('validates the month', function (mixed $month) {
        $this->signInAdmin();

        $this->postJson(cp_route('tolk.usage.reset'), ['month' => $month])->assertJsonValidationErrors('month');

        expect($this->log->month())->not->toBe([]);
    })->with([null, '', '2026-13', '2026-1', '../2026-10', [['2026-10']]]);

    it('is offered and allowed only to a user who may edit the settings', function () {
        $this->signInWithPermissions(['access cp', 'run bulk translations', 'access en site', 'access el site']);

        $this->get(cp_route('tolk.overview'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canResetUsage', false));
        $this->postJson(cp_route('tolk.usage.reset'), ['month' => now()->format('Y-m')])->assertForbidden();

        expect($this->log->month())->not->toBe([]);
    });
});

describe('page', function () {
    it('renders the containers by type with their sites, the providers, the month\'s usage and the five latest runs', function () {
        $this->signInAdmin();
        $log = app(UsageLog::class);
        $log->add(ModelRef::fromString('openai:gpt-6.1-sol'), new Usage(input: 1000, output: 500, calls: 2), UsageSource::Run);
        $log->add(ModelRef::fromString('openai:my-own-model'), new Usage(input: 10, output: 5, calls: 1), UsageSource::Editor);
        $runs = collect(range(1, 6))->map(fn () => RunFixtures::pending());
        $month = $log->month();

        expect($month['openai:gpt-6.1-sol'])->toMatchArray(['input' => 1000, 'output' => 500, 'calls' => 2])
            ->and($month['openai:gpt-6.1-sol']['cost'])->toBeGreaterThan(0)
            ->and($month['openai:my-own-model']['cost'])->toBeNull();

        $this->get(cp_route('tolk.overview'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('tolk::Overview')
                ->where('multisite', true)
                ->where('sites', [
                    ['handle' => 'en', 'name' => 'English'],
                    ['handle' => 'el', 'name' => 'Greek'],
                    ['handle' => 'de', 'name' => 'German'],
                ])
                ->where('rows', [
                    ['type' => 'entry', 'handle' => 'articles', 'title' => 'Articles', 'sites' => ['el', 'de']],
                    ['type' => 'term', 'handle' => 'topics', 'title' => 'Topics', 'sites' => ['el']],
                    ['type' => 'globals', 'handle' => 'site_settings', 'title' => 'Site Settings', 'sites' => ['el']],
                    ['type' => 'nav', 'handle' => 'main', 'title' => 'Main', 'sites' => ['el', 'de']],
                ])
                ->where('providers', array_map(app(Providers::class)->status(...), ModelRef::PROVIDERS))
                ->where('providers.0.configured', true)
                ->where('fakeEngine', false)
                ->where('usage.month', now()->format('Y-m'))
                ->where('usage.models', fn ($models) => collect($models)->toArray() === $month)
                ->where('usage.labels', ['openai:gpt-6.1-sol' => 'GPT-6.1 Sol', 'openai:my-own-model' => 'my-own-model'])
                ->where('canResetUsage', true)
                ->has('runs', 5)
                ->where('runs.0.id', $runs->last()->id)
                ->where('runs.0.url', cp_route('tolk.runs.show', $runs->last()->id))
                ->where('urls', [
                    'scan' => cp_route('tolk.overview.scan'),
                    'store' => cp_route('tolk.runs.store'),
                    'runs' => cp_route('tolk.runs.index'),
                    'audit' => cp_route('tolk.audit'),
                    'resetUsage' => cp_route('tolk.usage.reset'),
                ]));
    });

    it('shows only the sites the user may access', function () {
        $this->signInWithPermissions(['access cp', 'run bulk translations', 'access en site', 'access el site']);

        $this->get(cp_route('tolk.overview'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sites', [['handle' => 'en', 'name' => 'English'], ['handle' => 'el', 'name' => 'Greek']])
                ->where('rows.0.sites', ['el'])
                ->where('rows.3.sites', ['el']));

        overviewScan(['type' => 'entry', 'container' => 'articles', 'site' => 'de'])->assertForbidden();
        overviewScan(['type' => 'entry', 'container' => 'articles', 'site' => 'el'])->assertOk();
    });

    it('leaves out excluded taxonomies and single-site collections, and lists a multi-site collection without entries with no sites', function () {
        $this->signInAdmin();
        $this->withSettings(['excluded_taxonomies' => ['topics']]);
        $this->makeCollection('events', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeCollection('notes', ['en'], ['title' => ['type' => 'text', 'localizable' => true]]);

        $this->get(cp_route('tolk.overview'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows', [
                    ['type' => 'entry', 'handle' => 'articles', 'title' => 'Articles', 'sites' => ['el', 'de']],
                    ['type' => 'entry', 'handle' => 'events', 'title' => 'Events', 'sites' => []],
                    ['type' => 'globals', 'handle' => 'site_settings', 'title' => 'Site Settings', 'sites' => ['el']],
                    ['type' => 'nav', 'handle' => 'main', 'title' => 'Main', 'sites' => ['el', 'de']],
                ]));
    });

    it('tells the page when the site has one language or no provider has a key', function () {
        $this->signInAdmin();
        $this->withProviderKeys();
        Site::setSites(['en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US']]);

        $this->get(cp_route('tolk.overview'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('multisite', false)
                ->where('providers.0.configured', false)
                ->where('providers.1.configured', false)
                ->where('providers.2.configured', false));
    });
});

describe('scan', function () {
    it('adds up, slice by slice, to the counts of tolk:status --json', function () {
        $this->app->when(OverviewScanner::class)->needs('$budgetMs')->give(0);
        $this->signInAdmin();
        Artisan::call('tolk:status', ['--json' => true]);
        $rows = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($rows)->toHaveCount(6);

        foreach ($rows as $row) {
            [$answer, $calls] = scanCell($row['type'], $row['container'], $row['site']);

            expect(Arr::only($answer, ['counts', 'untracked', 'scanned']))->toBe(Arr::only($row, ['counts', 'untracked', 'scanned']))
                ->and($answer['cached'])->toBeFalse()
                ->and($calls)->toBe(max(1, $row['scanned']));
        }

        expect(Arr::first($rows, fn (array $row) => $row['container'] === 'articles' && $row['site'] === 'el')['counts'])
            ->toBe(['missing' => 1, 'untranslated' => 1, 'outdated' => 1, 'attention' => 1, 'current' => 1]);
    });

    it('answers a finished cell from the cache for ten minutes, and counts again when asked for fresh counts', function () {
        $this->signInAdmin();

        [$first] = scanCell('entry', 'articles', 'el');
        [$second, $calls] = scanCell('entry', 'articles', 'el');

        // Without events, as content changed outside Statamic would.
        $this->missing->makeLocalization('el')->saveQuietly();

        [$stale] = scanCell('entry', 'articles', 'el');
        [$fresh] = scanCell('entry', 'articles', 'el', ['fresh' => true]);
        [$cachedAgain] = scanCell('entry', 'articles', 'el');

        expect($first['cached'])->toBeFalse()
            ->and($second)->toBe([...$first, 'cached' => true])
            ->and($calls)->toBe(1)
            ->and($stale['counts']['missing'])->toBe(1)
            ->and($fresh['cached'])->toBeFalse()
            ->and($fresh['counts']['missing'])->toBe(0)
            ->and($fresh['counts']['untranslated'])->toBe(2)
            ->and($cachedAgain)->toBe([...$fresh, 'cached' => true]);

        $this->travel(599)->seconds();
        expect(cellCached('entry', 'articles', 'el'))->toBeTrue();

        $this->travel(2)->seconds();
        expect(cellCached('entry', 'articles', 'el'))->toBeFalse();
    });

    it('no longer answers the counts a fresh count replaces once it started, also when it stops midway', function () {
        $this->app->when(OverviewScanner::class)->needs('$budgetMs')->give(0);
        $this->signInAdmin();
        $cell = ['type' => 'entry', 'container' => 'articles', 'site' => 'el'];

        [$before] = scanCell(...array_values($cell));

        // Without events, as content changed outside Statamic would.
        $this->missing->makeLocalization('el')->saveQuietly();

        // Count again: its first slice is answered, its next request never arrives.
        $slice = overviewScan([...$cell, 'fresh' => true])->assertOk()->json();
        [$after] = scanCell(...array_values($cell));

        expect($before['counts']['missing'])->toBe(1)
            ->and($slice['cursor'])->not->toBeNull()
            ->and($after['cached'])->toBeFalse()
            ->and($after['counts'])->toBe(['missing' => 0, 'untranslated' => 2, 'outdated' => 1, 'attention' => 1, 'current' => 1])
            ->and(cellCached(...array_values($cell)))->toBeTrue();
    });

    it('counts a collection and every navigation again after an entry is saved or deleted', function () {
        $this->signInAdmin();
        cacheGreekCells();

        $this->current->set('title', 'Νέο')->save();
        $saved = greekCellsCached();

        Entry::find($this->missing->id())->delete();
        $deleted = greekCellsCached();

        [$after] = scanCell('entry', 'articles', 'el');

        expect($saved)->toBe(['articles' => false, 'topics' => true, 'site_settings' => true, 'main' => false])
            ->and($deleted)->toBe(['articles' => false, 'topics' => true, 'site_settings' => true, 'main' => false])
            ->and($after['counts'])->toBe(['missing' => 0, 'untranslated' => 1, 'outdated' => 1, 'attention' => 1, 'current' => 1])
            ->and($after['scanned'])->toBe(4);
    });

    it('counts only the container again after its content, its blueprint or its state changed', function (Closure $save, string $bumped) {
        $this->signInAdmin();
        cacheGreekCells();

        $save();

        expect(greekCellsCached())->toBe([...['articles' => true, 'topics' => true, 'site_settings' => true, 'main' => true], $bumped => false]);
    })->with([
        'term' => [fn () => Term::find('topics::food')->in('el')->set('title', 'Φαγητό')->save(), 'topics'],
        'global variables' => [fn () => GlobalSet::findByHandle('site_settings')->in('el')->set('tagline', 'Δίπλα στη θάλασσα')->save(), 'site_settings'],
        'navigation tree' => [fn () => Nav::findByHandle('main')->makeTree('el', [['id' => 'book', 'title' => 'Κράτηση', 'url' => 'https://booking.example']])->save(), 'main'],
        'blueprint' => [fn () => Blueprint::find('taxonomies.topics.topic')->save(), 'topics'],
        'translated target' => [fn () => TargetTranslated::dispatch(new TargetRef(ContentType::GlobalSet, 'site_settings', 'el'), ['tagline'], [], new UsageMap), 'site_settings'],
        'translated term' => [fn () => TargetTranslated::dispatch(new TargetRef(ContentType::Term, 'topics::food', 'el'), ['title'], [], new UsageMap), 'topics'],
    ]);

    it('counts an entry\'s collection again when it was translated', function () {
        $this->signInAdmin();
        cacheGreekCells();

        TargetTranslated::dispatch(StateFixtures::ref($this->current), ['title'], [], new UsageMap);

        expect(greekCellsCached())->toBe(['articles' => false, 'topics' => true, 'site_settings' => true, 'main' => false]);
    });

    it('counts everything again after the settings are saved or the Stache is cleared', function (Closure $change) {
        $this->signInAdmin();
        cacheGreekCells();

        $change();

        expect(greekCellsCached())->toBe(['articles' => false, 'topics' => false, 'site_settings' => false, 'main' => false]);
    })->with([
        'settings' => [fn () => test()->withSettings(['excluded_fields' => ['intro']])],
        'stache' => [fn () => StacheCleared::dispatch()],
    ]);

    it('counts the container again when confirming or a baseline changed state, but not in a dry run', function () {
        $this->signInAdmin();
        $target = fn () => app(TargetFactory::class)->forEntry($this->missing->fresh(), 'el');
        $writer = app(StateWriter::class);
        $localization = $this->localize($this->missing, 'el', ['title' => 'Λείπει']);
        StateFixtures::store($localization, ['title' => (new FieldState)->withPending(StateFixtures::pending('Missing', 'Λείπει'))]);

        cacheGreekCells();
        $writer->confirm($target(), dryRun: true);
        $writer->baseline($target(), includeMachine: true, dryRun: true);
        $dryRun = cellCached('entry', 'articles', 'el');

        $writer->confirm($target());
        $confirmed = cellCached('entry', 'articles', 'el');

        $nothing = $writer->confirm($target());
        $unchanged = cellCached('entry', 'articles', 'el');

        $writer->baseline($target(), includeMachine: true);
        $baselined = cellCached('entry', 'articles', 'el');

        expect($dryRun)->toBeTrue()
            ->and($confirmed)->toBeFalse()
            ->and($nothing->changed())->toBeFalse()
            ->and($unchanged)->toBeTrue()
            ->and($baselined)->toBeFalse()
            ->and(cellCached('term', 'topics', 'el'))->toBeTrue();
    });

    it('starts over when the totals before the cursor are gone', function () {
        $this->app->when(OverviewScanner::class)->needs('$budgetMs')->give(0);
        $this->signInAdmin();
        $cell = ['type' => 'entry', 'container' => 'articles', 'site' => 'el'];

        $first = overviewScan($cell)->assertOk()->json();
        $second = overviewScan([...$cell, 'cursor' => $first['cursor']])->assertOk()->json();

        // A save while the cell is being scanned.
        $this->current->set('title', 'Νέο')->save();
        $restarted = overviewScan([...$cell, 'cursor' => $second['cursor']])->assertOk()->json();
        $unknown = overviewScan([...$cell, 'cursor' => 'not-a-cursor'])->assertOk()->json();
        [$finished] = scanCell(...array_values($cell), body: ['fresh' => true]);

        expect([$first['scanned'], $first['cursor']])->toBe([1, '1'])
            ->and([$second['scanned'], $second['cursor']])->toBe([2, '2'])
            ->and([$restarted['scanned'], $restarted['cursor']])->toBe([1, '1'])
            ->and([$unknown['scanned'], $unknown['cursor']])->toBe([1, '1'])
            ->and($finished['scanned'])->toBe(5)
            ->and($finished['counts'])->toBe(['missing' => 1, 'untranslated' => 1, 'outdated' => 1, 'attention' => 1, 'current' => 1]);
    });

    it('refuses an unknown type, container or site', function (array $body, int $status) {
        $this->signInAdmin();

        overviewScan([...['type' => 'entry', 'container' => 'articles', 'site' => 'el'], ...$body])->assertStatus($status);
    })->with([
        'type' => [['type' => 'lang'], 422],
        'no container' => [['container' => null], 422],
        'collection' => [['container' => 'nope'], 422],
        'taxonomy' => [['type' => 'term', 'container' => 'nope'], 422],
        'global set' => [['type' => 'globals', 'container' => 'nope'], 422],
        'navigation' => [['type' => 'nav', 'container' => 'nope'], 422],
        'site' => [['site' => 'fr'], 422],
    ]);
});
