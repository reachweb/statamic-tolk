<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\ItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Events\RunFinished;
use Reach\StatamicTolk\Runs\RunProcessor;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\State\StatusScanner;
use Reach\StatamicTolk\State\TargetStatus;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

const BASELINE_HEADERS = ['Type', 'Handle', 'Site', 'Fields recorded'];

beforeEach(function () {
    $this->useFileCache();
    $this->freezeSecond();
    Queue::fake();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true],
    ]);

    $this->hello = $this->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'A short intro.'], 'hello');
    $this->world = $this->makeEntry('articles', 'en', ['title' => 'World', 'intro' => 'Another intro.'], 'world');
});

function maintenanceStatus(EntryContract $origin, string $site = 'el'): TargetStatus
{
    return app(StatusResolver::class)->resolve(app(TargetFactory::class)->forEntry((string) $origin->id(), $site));
}

/**
 * @return array{0: int, 1: string}
 */
function maintenanceCommand(string $command, array $options = []): array
{
    $exitCode = Artisan::call($command, $options);

    return [$exitCode, Artisan::output()];
}

/**
 * @return list<string>
 */
function storedReferences(): array
{
    return array_map(strval(...), iterator_to_array(app(StateRepository::class)->references(), false));
}

function storeState(TargetRef $ref, string $handle, FieldState $field): void
{
    app(StateRepository::class)->update($ref, fn (ItemState $state) => $state->withField($handle, $field));
}

describe('tolk:baseline', function () {
    it('records the origin of fields translated by hand, which read manual with an unchanged source and need attention once the origin changes', function () {
        $this->localize($this->hello, 'el', ['title' => 'Γεια σου', 'intro' => 'Μια σύντομη εισαγωγή.']);
        $this->localize($this->world, 'de', ['title' => 'Welt']);
        $before = maintenanceStatus($this->hello);
        $commits = RunFixtures::commits();

        $this->artisan('tolk:baseline')
            ->expectsTable(BASELINE_HEADERS, [
                ['Collection', 'articles', 'el', '2'],
                ['Collection', 'articles', 'de', '1'],
            ])
            ->expectsOutputToContain('Recorded the origin of 3 fields. A change to the origin now shows as needing attention.')
            ->assertExitCode(0);

        $committed = $commits->getArrayCopy();
        $after = maintenanceStatus($this->hello);
        $this->hello->set('title', 'Hello there')->save();
        $edited = maintenanceStatus($this->hello);

        expect($before->counts['untracked'])->toBe(2)
            ->and(array_map(fn ($field) => [$field->status, $field->sourceChanged], $after->fields))->toBe([
                'title' => [FieldStatus::Manual, false],
                'intro' => [FieldStatus::Manual, false],
            ])
            ->and($after->counts['untracked'])->toBe(0)
            ->and($after->itemStatus)->toBe(ItemStatus::Current)
            ->and($edited->fields['title']->sourceChanged)->toBeTrue()
            ->and($edited->fields['intro']->sourceChanged)->toBeFalse()
            ->and($edited->itemStatus)->toBe(ItemStatus::Attention)
            ->and(maintenanceStatus($this->world, 'de')->fields['title']->sourceChanged)->toBeFalse()
            ->and($committed)->toBe([StateWriter::COMMIT_MESSAGE]);
    });

    it('leaves machine output alone, and a copy of the origin\'s text unless --include-copies is given', function () {
        $localization = $this->localize($this->hello, 'el', ['title' => 'Γεια', 'intro' => 'A short intro.']);
        StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Γεια')]);

        [$exitCode, $output] = maintenanceCommand('tolk:baseline');
        $without = maintenanceStatus($this->hello);

        $this->artisan('tolk:baseline', ['--include-copies' => true])
            ->expectsTable(BASELINE_HEADERS, [['Collection', 'articles', 'el', '1']])
            ->expectsOutputToContain('Recorded the origin of 1 field.')
            ->assertExitCode(0);

        $with = maintenanceStatus($this->hello);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Nothing to record: every field translated by hand already has a record of its origin.')
            ->and($without->fields['title']->status)->toBe(FieldStatus::Current)
            ->and($without->fields['intro']->status)->toBe(FieldStatus::Untranslated)
            ->and($with->fields['title']->status)->toBe(FieldStatus::Current)
            ->and($with->fields['intro']->status)->toBe(FieldStatus::Manual)
            ->and($with->fields['intro']->sourceChanged)->toBeFalse();
    });

    it('also turns machine output, up to date or outdated, into fields edited by hand with --protect, in the containers named, an outdated one needing attention', function () {
        $hello = $this->localize($this->hello, 'el', ['title' => 'Γεια', 'intro' => 'Μια εισαγωγή.']);
        StateFixtures::store($hello, [
            'title' => StateFixtures::machine('Hello', 'Γεια'),
            'intro' => StateFixtures::machine('An older intro.', 'Μια εισαγωγή.'),
        ]);
        $this->makeCollection('pages', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $page = $this->makeEntry('pages', 'en', ['title' => 'About'], 'about');
        StateFixtures::store($this->localize($page, 'el', ['title' => 'Σχετικά']), ['title' => StateFixtures::machine('About', 'Σχετικά')]);
        $before = maintenanceStatus($this->hello);
        $commits = RunFixtures::commits();

        $this->artisan('tolk:baseline', ['--collection' => ['articles'], '--protect' => true])
            ->expectsTable(BASELINE_HEADERS, [['Collection', 'articles', 'el', '2']])
            ->expectsOutputToContain('Recorded the origin of 2 fields.')
            ->assertExitCode(0);

        $after = maintenanceStatus($this->hello);

        expect(array_map(fn ($field) => $field->status, $before->fields))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Outdated])
            ->and(array_map(fn ($field) => [$field->status, $field->sourceChanged], $after->fields))->toBe([
                'title' => [FieldStatus::Manual, false],
                'intro' => [FieldStatus::Manual, true],
            ])
            ->and(maintenanceStatus($page)->fields['title']->status)->toBe(FieldStatus::Current)
            ->and($commits->getArrayCopy())->toBe([StateWriter::COMMIT_MESSAGE]);
    });

    it('refuses --protect without a container option, so it never protects every translation at once', function () {
        $hello = $this->localize($this->hello, 'el', ['title' => 'Γεια']);
        StateFixtures::store($hello, ['title' => StateFixtures::machine('Hello', 'Γεια')]);

        [$exitCode, $output] = maintenanceCommand('tolk:baseline', ['--protect' => true, '--site' => ['el']]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('--protect needs --collection, --taxonomy, --globals or --nav')
            ->and(maintenanceStatus($this->hello)->fields['title']->status)->toBe(FieldStatus::Current);
    });

    it('counts the fields and writes nothing with --dry-run', function () {
        $this->localize($this->hello, 'el', ['title' => 'Γεια σου']);
        $commits = RunFixtures::commits();

        $this->artisan('tolk:baseline', ['--dry-run' => true])
            ->expectsTable(['Type', 'Handle', 'Site', 'Fields to record'], [['Collection', 'articles', 'el', '1']])
            ->expectsOutputToContain('Would record the origin of 1 field. Nothing was written (--dry-run).')
            ->assertExitCode(0);

        expect(storedReferences())->toBe([])
            ->and(maintenanceStatus($this->hello)->counts['untracked'])->toBe(1)
            ->and($commits->getArrayCopy())->toBe([]);
    });

    it('records terms, global sets and navigations, and only in the sites and containers named', function () {
        $this->makeTaxonomy('topics', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food'], 'el' => ['title' => 'Φαγητό']]);
        $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en'], ['tagline' => ['type' => 'text', 'localizable' => true]], [
            'en' => ['tagline' => 'By the sea'],
            'el' => ['tagline' => 'Δίπλα στη θάλασσα'],
        ]);
        $this->makeNav('main', [
            'en' => [['id' => 'book', 'title' => 'Book your stay', 'url' => 'https://booking.example']],
            'el' => [['id' => 'book', 'title' => 'Κλείστε τη διαμονή σας', 'url' => 'https://booking.example']],
        ]);
        $this->localize($this->hello, 'el', ['title' => 'Γεια σου']);
        $this->localize($this->world, 'de', ['title' => 'Welt']);

        $this->artisan('tolk:baseline', ['--site' => ['el'], '--taxonomy' => ['topics'], '--globals' => ['site_settings'], '--nav' => ['main']])
            ->expectsTable(BASELINE_HEADERS, [
                ['Taxonomy', 'topics', 'el', '1'],
                ['Global set', 'site_settings', 'el', '1'],
                ['Navigation', 'main', 'el', '1'],
            ])
            ->assertExitCode(0);

        $this->artisan('tolk:baseline', ['--collection' => ['articles'], '--site' => ['de']])
            ->expectsTable(BASELINE_HEADERS, [['Collection', 'articles', 'de', '1']])
            ->assertExitCode(0);

        expect(storedReferences())->toEqualCanonicalizing([
            'term::topics::food::el',
            'globals::site_settings::el',
            'nav::main::el',
            'entry::'.$this->world->in('de')->id(),
        ])->and(maintenanceStatus($this->hello)->counts['untracked'])->toBe(1);
    });

    it('records a navigation translated from another site against the tree of that site, which stays its source', function () {
        $this->fakeTranslations();
        $book = fn (string $title) => [['id' => 'book', 'title' => $title, 'url' => 'https://booking.example']];
        $nav = $this->makeNav('main', ['en' => $book('Book your stay'), 'de' => $book('Aufenthalt buchen')]);
        app(TargetTranslator::class)->translate(app(TargetFactory::class)->forNav('main', 'el', 'de'), PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class)), new ApplyOptions);
        $nav->in('el')->tree($book('Κλείστε τη διαμονή σας'))->save();

        $this->artisan('tolk:baseline', ['--nav' => ['main'], '--site' => ['el']])
            ->expectsTable(BASELINE_HEADERS, [['Navigation', 'main', 'el', '1']])
            ->assertExitCode(0);

        $recorded = app(StateRepository::class)->get(new TargetRef(ContentType::Nav, 'main', 'el'))->sourceSite;
        $nav->in('de')->tree($book('Aufenthalt jetzt buchen'))->save();

        expect($recorded)->toBe('de')
            ->and(app(StatusScanner::class)->scan(ContentType::Nav, 'main', 'el', null, 10_000)->counts['attention'])->toBe(1);
    });

    it('skips an item whose state file cannot be read with its message, records the others and exits 1', function () {
        $greek = $this->localize($this->hello, 'el', ['title' => 'Γεια σου']);
        StateFixtures::store($greek, ['intro' => StateFixtures::machine('A short intro.', 'Μια σύντομη εισαγωγή.')]);
        $path = StateFixtures::conflict($greek);
        $this->localize($this->world, 'el', ['title' => 'Κόσμε']);
        $commits = RunFixtures::commits();

        [$exitCode, $output] = maintenanceCommand('tolk:baseline');

        expect($exitCode)->toBe(1)
            ->and($output)->toContain("The translation state file {$path} cannot be read")
            ->and($output)->toContain('Recorded the origin of 1 field.')
            ->and(maintenanceStatus($this->world)->counts['untracked'])->toBe(0)
            ->and($commits->getArrayCopy())->toBe([StateWriter::COMMIT_MESSAGE]);
    });

    it('skips a navigation whose state file cannot be read with its message, records the others and exits 1', function () {
        $book = fn (string $title) => [['id' => 'book', 'title' => $title, 'url' => 'https://booking.example']];
        $this->makeNav('main', ['en' => $book('Book your stay'), 'el' => $book('Κλείστε τη διαμονή σας')]);
        $ref = new TargetRef(ContentType::Nav, 'main', 'el');
        app(StateRepository::class)->update($ref, fn (ItemState $state) => $state->withSourceSite('en')->withField('book.title', StateFixtures::machine('Book your stay', 'Κλείστε')));
        $path = StateFixtures::conflict($ref);
        $this->localize($this->world, 'el', ['title' => 'Κόσμε']);

        [$exitCode, $output] = maintenanceCommand('tolk:baseline');

        expect($exitCode)->toBe(1)
            ->and($output)->toContain("The translation state file {$path} cannot be read")
            ->and($output)->toContain('Recorded the origin of 1 field.')
            ->and(maintenanceStatus($this->world)->counts['untracked'])->toBe(0);
    });

    it('refuses a site or container that does not exist', function (array $options, string $error) {
        [$exitCode, $output] = maintenanceCommand('tolk:baseline', $options);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain($error)
            ->and(storedReferences())->toBe([]);
    })->with([
        'site' => [['--site' => ['fr']], 'fr'],
        'collection' => [['--collection' => ['news']], 'Collection [news] not found.'],
        'taxonomy' => [['--taxonomy' => ['tags']], 'Taxonomy [tags] not found.'],
    ]);
});

describe('tolk:prune', function () {
    it('deletes nothing with --dry-run, and plain prune deletes the state of content that is gone or no longer a localization', function () {
        $kept = $this->localize($this->hello, 'el', ['title' => 'Γεια']);
        StateFixtures::store($kept, ['title' => StateFixtures::machine('Hello', 'Γεια')]);

        $detached = $this->localize($this->world, 'el', ['title' => 'Κόσμος']);
        StateFixtures::store($detached, ['title' => StateFixtures::machine('World', 'Κόσμος')]);
        $this->world->detachLocalizations();
        Entry::find($this->world->id())->delete();

        $this->makeTaxonomy('topics', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeTerm('topics', 'meals', ['en' => ['title' => 'Meals']]);
        storeState(new TargetRef(ContentType::Term, 'topics::food', 'el'), 'title', StateFixtures::machine('Food', 'Φαγητό'));
        storeState(new TargetRef(ContentType::Entry, 'a-deleted-entry'), 'title', StateFixtures::machine('Gone', 'Χάθηκε'));

        $everything = storedReferences();
        $commits = RunFixtures::commits();

        $this->artisan('tolk:prune', ['--dry-run' => true])
            ->expectsOutputToContain('entry::a-deleted-entry')
            ->expectsOutputToContain('term::topics::food::el')
            ->expectsOutputToContain('entry::'.$detached->id())
            ->expectsOutputToContain('Entry state given its collection and site')
            ->expectsOutputToContain('Nothing was changed (--dry-run).')
            ->assertExitCode(0);

        expect(storedReferences())->toEqualCanonicalizing($everything)
            ->and($everything)->toHaveCount(4)
            ->and(StateFixtures::stored($kept)->collection)->toBeNull()
            ->and($commits->getArrayCopy())->toBe([]);

        [$exitCode, $output] = maintenanceCommand('tolk:prune');

        expect($exitCode)->toBe(0)
            ->and($output)->not->toContain('entry::a-deleted-entry')
            // $kept's state was stored as 1.0.0-beta.1 wrote an entry's state, without its collection and site.
            ->and($output)->toMatch('/Entry state given its collection and site\W+1 record/')
            ->and(storedReferences())->toBe(['entry::'.$kept->id()])
            ->and(StateFixtures::stored($kept)->collection)->toBe('articles')
            ->and(StateFixtures::stored($kept)->site)->toBe('el')
            ->and($commits->getArrayCopy())->toBe([StateWriter::COMMIT_MESSAGE]);

        $this->artisan('tolk:prune')->expectsOutputToContain('Nothing to clean up.')->assertExitCode(0);
        expect($commits->getArrayCopy())->toBe([StateWriter::COMMIT_MESSAGE]);
    });

    it('lists the state files that cannot be read on every run, and keeps them', function () {
        $localization = $this->localize($this->hello, 'el', ['title' => 'Γεια']);
        StateFixtures::store($localization, ['title' => StateFixtures::machine('Hello', 'Γεια')]);
        $path = StateFixtures::conflict($localization);

        [$exitCode, $output] = maintenanceCommand('tolk:prune');

        expect($exitCode)->toBe(0)
            ->and($output)->toMatch('/State files that cannot be read\W+1 file/')
            ->and($output)->toContain($path)
            ->and($output)->toContain('Fix each of these files, for example by resolving a git merge conflict in it, or delete it.')
            ->and($output)->not->toContain('Nothing to clean up.')
            ->and($path)->toBeFile();
    });

    it('lists what it deleted with -v', function () {
        storeState(new TargetRef(ContentType::Entry, 'a-deleted-entry'), 'title', StateFixtures::machine('Gone', 'Χάθηκε'));

        $this->artisan('tolk:prune', ['-v' => true])
            ->expectsOutputToContain('entry::a-deleted-entry')
            ->assertExitCode(0);
    });

    it('confirms saved pending translations and drops unsaved ones older than 30 days', function () {
        $saved = $this->localize($this->hello, 'el', ['title' => 'Γεια']);
        StateFixtures::store($saved, ['title' => new FieldState(pending: [StateFixtures::pending('Hello', 'Γεια')])]);
        $unsaved = $this->localize($this->world, 'el');
        StateFixtures::store($unsaved, [
            'title' => new FieldState(pending: [StateFixtures::pending('World', 'Κόσμος', ['createdAt' => CarbonImmutable::now()->subDays(31)])]),
            'intro' => new FieldState(pending: [StateFixtures::pending('Another intro.', 'Άλλη μια εισαγωγή.')]),
        ]);

        [, $dryRun] = maintenanceCommand('tolk:prune', ['--dry-run' => true]);
        $untouched = [StateFixtures::stored($saved)->toArray(), StateFixtures::stored($unsaved)->toArray()];
        [$exitCode, $output] = maintenanceCommand('tolk:prune');

        expect($dryRun)->toMatch('/Pending translations confirmed\W+1 field/')
            ->and($dryRun)->toMatch('/Pending translations older than 30 days dropped\W+1 field/')
            ->and($untouched[1]['fields']['title']['pending'])->toHaveCount(1)
            ->and($untouched[0]['fields']['title'])->not->toHaveKey('target_hash')
            ->and($exitCode)->toBe(0)
            ->and($output)->toMatch('/Pending translations confirmed\W+1 field/')
            ->and(StateFixtures::stored($saved)->field('title')->targetHash)->toBe(Fingerprint::ofLeaves(['Γεια']))
            ->and(StateFixtures::stored($saved)->field('title')->pending)->toBe([])
            ->and(StateFixtures::stored($unsaved)->fields)->toHaveKeys(['intro'])
            ->and(StateFixtures::stored($unsaved)->fields)->not->toHaveKey('title')
            ->and(StateFixtures::stored($unsaved)->field('intro')->pending)->toHaveCount(1);
    });

    it('stops a run without progress, which commits what it saved, and Resume finishes it', function () {
        Event::fake([RunFinished::class]);
        $this->fakeTranslations();
        $run = RunFixtures::started();
        RunFixtures::translate($run);
        $lock = Cache::lock("tolk:item:{$run->id}:1", 120);
        $lock->get();
        app(RunProcessor::class)->applyReady($run, 'apply-1', CarbonImmutable::now()->addSeconds(30));
        $lock->release();
        $commits = RunFixtures::commits();

        $this->travel(config('tolk.runs.claim_ttl') - 1)->seconds();
        [, $early] = maintenanceCommand('tolk:prune');

        $this->travel(2)->seconds();

        $this->artisan('tolk:prune')
            ->expectsOutputToContain(cp_route('tolk.runs.show', $run->id))
            ->expectsOutputToContain('A stopped run can be resumed on its page in the Control Panel.')
            ->assertExitCode(0);

        $stopped = RunFixtures::stored($run);
        $statuses = RunFixtures::statuses($run);

        expect($early)->toContain('Nothing to clean up.')
            ->and($commits->getArrayCopy())->toBe([ContentWriter::COMMIT_MESSAGE])
            ->and($stopped->status)->toBe(RunStatus::Aborted)
            ->and($stopped->error)->toBe('The run made no progress for more than 5 minutes and was stopped. Resume continues it.')
            ->and($stopped->finishedAt)->not->toBeNull()
            ->and($statuses[0])->toBe('done')
            ->and($statuses[1])->toBeIn(['translated', 'applying']);
        Event::assertDispatched(RunFinished::class, fn (RunFinished $event) => $event->runId === $run->id && $event->status === RunStatus::Aborted);

        expect(app(RunProcessor::class)->resume($stopped))->toBeTrue();
        RunFixtures::finish($stopped);

        expect(RunFixtures::stored($run)->status)->toBe(RunStatus::Completed)
            ->and(RunFixtures::statuses($run))->toBe(['done', 'done']);
    });

    it('deletes the runs without activity for runs.retention_days, and counts them with --dry-run', function () {
        $old = RunFixtures::pending();
        $this->travel(31)->days();
        $recent = RunFixtures::pending();

        [, $dryRun] = maintenanceCommand('tolk:prune', ['--dry-run' => true]);
        $kept = app(RunRepository::class)->find($old->id);
        [, $output] = maintenanceCommand('tolk:prune');

        expect($dryRun)->toMatch('/Runs deleted after 30 days without activity\W+1 run/')
            ->and($kept)->not->toBeNull()
            ->and($output)->toMatch('/Runs deleted after 30 days without activity\W+1 run/')
            ->and(app(RunRepository::class)->find($old->id))->toBeNull()
            ->and(app(RunRepository::class)->find($recent->id))->not->toBeNull();
    });
});
