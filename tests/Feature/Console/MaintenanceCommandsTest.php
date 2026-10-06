<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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
use Reach\StatamicTolk\State\TargetStatus;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
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
            ->expectsOutputToContain('Nothing was changed (--dry-run).')
            ->assertExitCode(0);

        expect(storedReferences())->toEqualCanonicalizing($everything)
            ->and($everything)->toHaveCount(4)
            ->and($commits->getArrayCopy())->toBe([]);

        $this->artisan('tolk:prune')
            ->doesntExpectOutputToContain('entry::a-deleted-entry')
            ->assertExitCode(0);

        expect(storedReferences())->toBe(['entry::'.$kept->id()])
            ->and($commits->getArrayCopy())->toBe([StateWriter::COMMIT_MESSAGE]);

        $this->artisan('tolk:prune')->expectsOutputToContain('Nothing to clean up.')->assertExitCode(0);
        expect($commits->getArrayCopy())->toBe([StateWriter::COMMIT_MESSAGE]);
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
