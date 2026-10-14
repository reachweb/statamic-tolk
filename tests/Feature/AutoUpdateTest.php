<?php

use Illuminate\Bus\UniqueLock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Jobs\UpdateTranslations;
use Reach\StatamicTolk\Jobs\WorkRun;
use Reach\StatamicTolk\Runs\AutoUpdater;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunItem;
use Reach\StatamicTolk\Runs\RunPlanner;
use Reach\StatamicTolk\Runs\RunProcessor;
use Reach\StatamicTolk\Runs\UsageLog;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Support\WebServer;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Facades\YAML;

beforeEach(function () {
    $this->useFileCache();
    $this->freezeSecond();
    config(['queue.default' => 'sync', 'tolk.runs.execution' => 'auto']);

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true, 'display' => 'Title'],
        'intro' => ['type' => 'textarea', 'localizable' => true, 'display' => 'Introduction'],
    ]);
    $this->makeCollection('pages', ['en', 'el'], [
        'title' => ['type' => 'text', 'localizable' => true],
    ]);

    $this->hello = $this->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'A short intro.'], 'hello');
    $this->runs = app(RunRepository::class);
});

/**
 * Translates the entry into the sites as `[T] ` + text, before automatic updates are turned on.
 *
 * @param  list<string>  $sites
 */
function autoTranslated(EntryContract $entry, array $sites): void
{
    test()->fakeTranslations();

    foreach ($sites as $site) {
        app(TargetTranslator::class)->translate(
            app(TargetFactory::class)->forEntry($entry, $site),
            PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['profile' => 'background']),
            new ApplyOptions,
        );
    }
}

function autoOn(array $settings = []): void
{
    test()->withSettings(['auto_update' => true, ...$settings]);
}

/**
 * Ends the request, as the response of the save would: what was dispatched after the response runs now.
 */
function afterResponse(): void
{
    app()->terminate();
}

function autoEntry(EntryContract $entry, string $site): ?EntryContract
{
    return Entry::find($entry->id())->in($site);
}

/**
 * Lets a queue worker work the next job of the `imports` queue, as `queue:work --once` would. Its process goes on, so
 * nothing runs after a response.
 */
function workImport(): void
{
    app('queue.worker')->runNextJob('imports', 'imports', new WorkerOptions(sleep: 0));
}

/**
 * @return list<array{queue: string, job: string, timeout: ?int, attempts: int}> the jobs waiting on the `imports` connection
 */
function importJobs(): array
{
    return DB::connection('tolk-test-jobs')->table('jobs')->orderBy('id')->get()->map(function (object $row) {
        $payload = json_decode($row->payload, true);

        return ['queue' => $row->queue, 'job' => $payload['displayName'], 'timeout' => $payload['timeout'], 'attempts' => $row->attempts];
    })->all();
}

/**
 * @return list<Run> the automatic runs, the latest first
 */
function autoRuns(): array
{
    return array_values(array_filter(app(RunRepository::class)->recent(100), fn (Run $run) => ($run->scope['automatic'] ?? false) === true));
}

describe('the setting', function () {
    it('updates nothing while it is off, as by default', function () {
        Bus::fake([UpdateTranslations::class]);
        $this->signInAdmin();
        autoTranslated($this->hello, ['el', 'de']);

        $this->hello->set('intro', 'A longer intro.')->save();
        afterResponse();

        Bus::assertNotDispatchedAfterResponse(UpdateTranslations::class);
        Bus::assertNotDispatched(UpdateTranslations::class);
        expect(app(Settings::class)->autoUpdate())->toBeFalse()
            ->and(autoRuns())->toBe([])
            ->and(autoEntry($this->hello, 'el')->get('intro'))->toBe('[T] A short intro.');
    });

    it('dispatches one update after the response for a published origin, none for the localizations saved with it', function () {
        Bus::fake([UpdateTranslations::class]);
        $admin = $this->signInAdmin();
        autoTranslated($this->hello, ['el']);
        // A chain: German is translated from Greek, so the Greek localization is an origin too.
        $this->localize(autoEntry($this->hello, 'el'), 'de');
        autoEntry($this->hello, 'el')->published(true)->saveQuietly();
        autoOn();

        $this->hello->set('intro', 'A longer intro.')->save();

        Bus::assertDispatchedAfterResponseTimes(UpdateTranslations::class, 1);
        Bus::assertDispatchedAfterResponse(UpdateTranslations::class, fn (UpdateTranslations $job) => $job->entryId === $this->hello->id() && $job->userId === (string) $admin->id());
        expect(app(AutoUpdater::class)->sites($this->hello))->toBe(['el']);

        $greek = autoEntry($this->hello, 'el');
        $greek->set('intro', 'Μια εισαγωγή.')->save();

        Bus::assertDispatchedAfterResponse(UpdateTranslations::class, fn (UpdateTranslations $job) => $job->entryId === $greek->id());
        expect(app(AutoUpdater::class)->sites($greek))->toBe(['de']);
    });

    it('updates nothing for a draft origin, a collection outside the list, an excluded collection or an entry without localizations', function () {
        Bus::fake([UpdateTranslations::class]);
        $this->signInAdmin();
        autoTranslated($this->hello, ['el']);
        $page = $this->makeEntry('pages', 'en', ['title' => 'About'], 'about');
        autoTranslated($page, ['el']);
        $lonely = $this->makeEntry('articles', 'en', ['title' => 'Alone'], 'alone');

        autoOn(['auto_update_collections' => ['pages']]);
        $this->hello->set('intro', 'A longer intro.')->save();

        autoOn(['auto_update_collections' => [], 'excluded_collections' => ['articles']]);
        $this->hello->set('intro', 'An even longer intro.')->save();

        autoOn(['excluded_collections' => []]);
        $this->hello->published(false)->set('intro', 'A draft intro.')->save();
        $lonely->set('title', 'Still alone')->save();

        Bus::assertNotDispatchedAfterResponse(UpdateTranslations::class);

        autoOn(['auto_update_collections' => ['pages']]);
        $page->set('title', 'About us')->save();

        Bus::assertDispatchedAfterResponse(UpdateTranslations::class, fn (UpdateTranslations $job) => $job->entryId === $page->id());
    });

    it('dispatches an update for a new published entry of a collection that propagates, whose localizations core creates after EntrySaved', function () {
        Bus::fake([UpdateTranslations::class]);
        $admin = $this->signInAdmin();
        Collection::find('articles')->propagate(true)->save();
        autoOn();

        $fresh = $this->makeEntry('articles', 'en', ['title' => 'Fresh', 'intro' => 'Just written.'], 'fresh');

        Bus::assertDispatchedAfterResponseTimes(UpdateTranslations::class, 1);
        Bus::assertDispatchedAfterResponse(UpdateTranslations::class, fn (UpdateTranslations $job) => $job->entryId === $fresh->id() && $job->userId === (string) $admin->id());
        expect(app(AutoUpdater::class)->sites($fresh))->toBe(['el', 'de']);
    });

    it('updates nothing for a new entry of a collection that propagates when it is a draft, outside the list, or in no site the user may access', function () {
        Bus::fake([UpdateTranslations::class]);
        Collection::find('articles')->propagate(true)->save();
        $this->signInAdmin();
        autoOn();

        Entry::make()->collection('articles')->locale('en')->slug('draft')->published(false)->data(['title' => 'Draft'])->save();

        autoOn(['auto_update_collections' => ['pages']]);
        $this->makeEntry('articles', 'en', ['title' => 'Elsewhere'], 'elsewhere');

        autoOn(['auto_update_collections' => []]);
        $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'create articles entries', 'edit articles entries']);
        $this->makeEntry('articles', 'en', ['title' => 'English only'], 'english-only');

        Bus::assertNotDispatchedAfterResponse(UpdateTranslations::class);
        expect(Entry::query()->where('collection', 'articles')->where('site', 'el')->count())->toBe(3);
    });

    it('lets an entry without localizations create them only when the settings say so', function () {
        Bus::fake([UpdateTranslations::class]);
        autoOn(['auto_update_create' => true]);

        $this->hello->set('title', 'Hello there')->save();

        Bus::assertDispatchedAfterResponseTimes(UpdateTranslations::class, 1);
        expect(app(AutoUpdater::class)->sites($this->hello))->toBe(['el', 'de']);
    });
});

describe('without a queue worker', function () {
    it('updates the changed field in each localization after the response and nothing else, in one run', function () {
        $admin = $this->signInAdmin();
        autoTranslated($this->hello, ['el', 'de']);
        $commits = RunFixtures::commits();
        autoOn();
        $this->fakeTranslations(fn (string $text) => '[U] '.$text);
        $calls = $this->modelCalls();

        $this->hello->set('intro', 'A longer intro.')->save();

        expect(autoRuns())->toBe([]);

        afterResponse();

        $run = autoRuns()[0] ?? null;

        expect(autoRuns())->toHaveCount(1)
            ->and($run->status)->toBe(RunStatus::Completed)
            ->and($run->driver)->toBe(RunDriver::Cli)
            ->and($run->createdBy['id'])->toBe((string) $admin->id())
            ->and($run->scope)->toMatchArray(['sites' => ['el', 'de'], 'source_site' => 'en', 'entry_ids' => [$this->hello->id()], 'reference' => $this->hello->reference(), 'automatic' => true])
            ->and($run->options)->toMatchArray(['mode' => 'update', 'overwrite_manual' => false, 'publish_changes' => false])
            ->and(RunFixtures::statuses($run))->toBe(['done', 'done'])
            ->and(autoEntry($this->hello, 'el')->get('intro'))->toBe('[U] A longer intro.')
            ->and(autoEntry($this->hello, 'el')->get('title'))->toBe('[T] Hello')
            ->and(autoEntry($this->hello, 'de')->get('intro'))->toBe('[U] A longer intro.')
            ->and(autoEntry($this->hello, 'de')->get('title'))->toBe('[T] Hello')
            ->and($this->modelCalls() - $calls)->toBe(2)
            ->and(StateFixtures::statuses($this->hello))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current])
            ->and(array_filter($commits->getArrayCopy(), fn (?string $message) => $message === ContentWriter::COMMIT_MESSAGE))->toHaveCount(1)
            ->and(app(UsageLog::class)->month()[(string) $run->options['translator']]['sources']['auto']['calls'])->toBe(2);
    });

    it('keeps a field edited by hand', function () {
        $this->signInAdmin();
        autoTranslated($this->hello, ['el', 'de']);
        autoEntry($this->hello, 'el')->set('title', 'Γεια σου')->save();
        autoOn();

        $this->hello->set('title', 'Hello there')->set('intro', 'A longer intro.')->save();
        afterResponse();

        expect(autoEntry($this->hello, 'el')->get('title'))->toBe('Γεια σου')
            ->and(autoEntry($this->hello, 'el')->get('intro'))->toBe('[T] A longer intro.')
            ->and(autoEntry($this->hello, 'de')->get('title'))->toBe('[T] Hello there');
    });

    it('creates the missing localizations as the settings say, when it may create them', function () {
        $this->signInAdmin();
        autoTranslated($this->hello, ['el']);
        autoOn(['auto_update_create' => true]);
        $this->fakeTranslations();

        $this->hello->set('intro', 'A longer intro.')->save();
        afterResponse();

        $german = autoEntry($this->hello, 'de');

        expect($german->get('title'))->toBe('[T] Hello')
            ->and($german->get('intro'))->toBe('[T] A longer intro.')
            ->and($german->published())->toBeFalse()
            ->and(autoEntry($this->hello, 'el')->get('intro'))->toBe('[T] A longer intro.')
            ->and(autoRuns()[0]->scope['sites'])->toBe(['el', 'de']);
    });

    it('translates the localizations a collection that propagates creates for a new published entry', function () {
        $this->signInAdmin();
        Collection::find('articles')->propagate(true)->save();
        autoOn();
        $this->fakeTranslations();

        $fresh = $this->makeEntry('articles', 'en', ['title' => 'Fresh', 'intro' => 'Just written.'], 'fresh');

        expect(autoEntry($fresh, 'el')->get('intro'))->toBeNull();

        afterResponse();
        $run = autoRuns()[0] ?? null;

        expect(autoRuns())->toHaveCount(1)
            ->and($run->scope['sites'])->toBe(['el', 'de'])
            ->and(RunFixtures::statuses($run))->toBe(['done', 'done'])
            ->and(autoEntry($fresh, 'el')->get('title'))->toBe('[T] Fresh')
            ->and(autoEntry($fresh, 'el')->get('intro'))->toBe('[T] Just written.')
            ->and(autoEntry($fresh, 'de')->get('intro'))->toBe('[T] Just written.')
            ->and(autoEntry($fresh, 'de')->published())->toBeTrue();
    });

    it('translates into the sites the user who saved may edit', function () {
        autoTranslated($this->hello, ['el', 'de']);
        $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'edit articles entries']);
        autoOn();

        $this->hello->set('intro', 'A longer intro.')->save();
        afterResponse();

        expect(autoEntry($this->hello, 'el')->get('intro'))->toBe('[T] A longer intro.')
            ->and(autoEntry($this->hello, 'de')->get('intro'))->toBe('[T] A short intro.')
            ->and(autoRuns()[0]->scope['sites'])->toBe(['el'])
            ->and(array_map(fn ($item) => $item->targetSite, RunFixtures::items(autoRuns()[0])))->toBe(['el']);
    });

    it('keeps no run when the save changed nothing to translate', function () {
        $this->signInAdmin();
        autoTranslated($this->hello, ['el', 'de']);
        autoOn();
        $calls = $this->modelCalls();

        $this->hello->save();
        afterResponse();

        expect(autoRuns())->toBe([])
            ->and($this->runs->recent(10))->toBe([])
            ->and($this->modelCalls())->toBe($calls);
    });

    it('rebuilds a translated field whose origin changed without text, with no model call, and keeps no run for a field it does not translate', function () {
        $this->makeCollection('stories', ['en', 'el'], [
            'title' => ['type' => 'text', 'localizable' => true],
            'note' => ['type' => 'text'],
            'blocks' => ['type' => 'replicator', 'localizable' => true, 'sets' => ['main' => ['sets' => [
                'text' => ['display' => 'Text', 'fields' => [
                    ['handle' => 'heading', 'field' => ['type' => 'text']],
                    ['handle' => 'highlight', 'field' => ['type' => 'toggle']],
                ]],
            ]]]],
        ]);
        $story = $this->makeEntry('stories', 'en', ['title' => 'Hello', 'blocks' => [
            ['id' => 'r1', 'type' => 'text', 'enabled' => true, 'heading' => 'Rooms', 'highlight' => false],
            ['id' => 'r2', 'type' => 'text', 'enabled' => true, 'heading' => 'Dining', 'highlight' => false],
        ]], 'story');
        $this->signInAdmin();
        autoTranslated($story, ['el']);
        // A change without text made only in Greek leaves the field machine-translated (D27).
        $greek = autoEntry($story, 'el');
        $greek->set('blocks', [$greek->get('blocks')[0], [...$greek->get('blocks')[1], 'highlight' => true]])->save();
        autoOn();
        $calls = $this->modelCalls();

        $story->set('note', 'Shared by every site.')->save();
        afterResponse();

        expect($this->runs->recent(10))->toBe([])
            ->and(autoEntry($story, 'el')->get('blocks')[1]['highlight'])->toBeTrue()
            ->and(StateFixtures::statuses($story))->toBe(['title' => FieldStatus::Current, 'blocks' => FieldStatus::Current]);

        $story->set('blocks', [[...$story->get('blocks')[0], 'highlight' => true], $story->get('blocks')[1]])->save();
        afterResponse();

        expect(autoRuns())->toHaveCount(1)
            ->and(autoRuns()[0]->status)->toBe(RunStatus::Completed)
            ->and(RunFixtures::statuses(autoRuns()[0]))->toBe(['done'])
            ->and($this->modelCalls())->toBe($calls)
            ->and(array_map(fn (array $row) => [$row['heading'], $row['highlight']], autoEntry($story, 'el')->get('blocks')))->toBe([['[T] Rooms', true], ['[T] Dining', false]])
            ->and(StateFixtures::statuses($story))->toBe(['title' => FieldStatus::Current, 'blocks' => FieldStatus::Current]);
    });

    it('leaves the localizations outdated when the provider stays down, and the save succeeds', function () {
        Sleep::fake(syncWithCarbon: true);
        $this->signInAdmin();
        autoTranslated($this->hello, ['el']);
        autoOn();
        $this->fakeTranslations(fn () => throw new ProviderConnectionException('Could not connect to AI provider [anthropic].'));
        $commits = RunFixtures::commits();

        expect($this->hello->set('intro', 'A longer intro.')->save())->toBeTrue();

        afterResponse();
        $run = autoRuns()[0];

        expect($run->status)->toBe(RunStatus::CompletedWithErrors)
            ->and(RunFixtures::items($run)[0]->status)->toBe(RunItemStatus::Failed)
            ->and($run->saves)->toBe(0)
            ->and(autoEntry($this->hello, 'el')->get('intro'))->toBe('[T] A short intro.')
            ->and(StateFixtures::statuses($this->hello)['intro'])->toBe(FieldStatus::Outdated)
            ->and(array_filter($commits->getArrayCopy(), fn (?string $message) => $message === ContentWriter::COMMIT_MESSAGE))->toBe([]);
    });

    it('waits for an earlier run from the entry that is still moving, then updates', function () {
        Sleep::fake();
        $admin = $this->signInAdmin();
        autoTranslated($this->hello, ['el']);
        $earlier = RunFixtures::started(['entryIds' => [$this->hello->id()], 'reference' => $this->hello->reference()]);
        autoOn();
        $this->hello->set('intro', 'A longer intro.')->saveQuietly();
        Sleep::whenFakingSleep(fn () => app(RunProcessor::class)->cancel($earlier));

        app()->call([new UpdateTranslations($this->hello->id(), (string) $admin->id()), 'handle']);

        Sleep::assertSleptTimes(1);
        expect(autoRuns())->toHaveCount(1)
            ->and(autoEntry($this->hello, 'el')->get('intro'))->toBe('[T] A longer intro.');
    });

    it('lets one process of the user wait for an earlier run from the entry, and its update covers the saves made meanwhile', function () {
        Sleep::fake(syncWithCarbon: true);
        $admin = $this->signInAdmin();
        autoTranslated($this->hello, ['el']);
        $earlier = RunFixtures::started(['entryIds' => [$this->hello->id()], 'reference' => $this->hello->reference()]);
        autoOn();
        $this->hello->set('intro', 'A longer intro.')->saveQuietly();
        $calls = $this->modelCalls();
        $sleeps = 0;

        // While the first job waits, the entry is saved again, and that save's job runs in its own process; then the
        // earlier run ends.
        Sleep::whenFakingSleep(function () use (&$sleeps, $admin, $earlier) {
            if ($sleeps++ > 0) {
                return;
            }

            $this->hello->set('intro', 'The longest intro.')->saveQuietly();
            app()->call([new UpdateTranslations($this->hello->id(), (string) $admin->id()), 'handle']);

            expect($sleeps)->toBe(1);

            app(RunProcessor::class)->cancel($earlier);
        });

        app()->call([new UpdateTranslations($this->hello->id(), (string) $admin->id()), 'handle']);

        Sleep::assertSleptTimes(1);
        expect(autoRuns())->toHaveCount(1)
            ->and(RunFixtures::statuses(autoRuns()[0]))->toBe(['done'])
            ->and($this->modelCalls() - $calls)->toBe(1)
            ->and(autoEntry($this->hello, 'el')->get('intro'))->toBe('[T] The longest intro.');
    });

    it('lets the saves of the user meanwhile hand the update that waits the runs it waits for, while another user waits in a process of their own', function () {
        Sleep::fake(syncWithCarbon: true);
        $admin = $this->signInAdmin();
        autoTranslated($this->hello, ['el', 'de']);
        $editor = $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'edit articles entries']);
        $earlier = RunFixtures::started(['entryIds' => [$this->hello->id()], 'reference' => $this->hello->reference()]);
        // A run that saved the entry with its first item and is still to translate German from it.
        $bulk = $this->runs->create(Run::make(RunDriver::Browser), [
            new RunItem(0, ContentType::Entry, $this->hello->id(), 'el', 'Hello', status: RunItemStatus::Done),
            new RunItem(1, ContentType::Entry, $this->hello->id(), 'de', 'Hello', dependsOn: [0]),
        ]);
        autoOn();
        $this->hello->set('intro', 'A longer intro.')->saveQuietly();
        $sleeps = 0;

        // The admin's job waits for the earlier run. The admin's next save is made by the run still to translate German,
        // and an editor saves too, each job in a process of its own; the earlier run ends while the editor's job waits,
        // and the run still to translate German once the admin's job waits for it alone.
        Sleep::whenFakingSleep(function () use (&$sleeps, $admin, $editor, $earlier, $bulk) {
            $sleeps++;

            if ($sleeps === 1) {
                app()->call([new UpdateTranslations($this->hello->id(), (string) $admin->id(), [['run' => $bulk->id, 'item' => 0]]), 'handle']);
                app()->call([new UpdateTranslations($this->hello->id(), (string) $editor->id()), 'handle']);
            }

            if ($sleeps === 2) {
                app(RunProcessor::class)->cancel($earlier);
            }

            if ($sleeps === 3) {
                app(RunProcessor::class)->cancel($bulk);
            }
        });

        app()->call([new UpdateTranslations($this->hello->id(), (string) $admin->id()), 'handle']);

        $done = fn (Run $run) => array_values(array_map(fn ($item) => $item->targetSite, array_filter(RunFixtures::items($run), fn ($item) => $item->status === RunItemStatus::Done)));

        Sleep::assertSleptTimes(3);
        expect(array_map(fn (Run $run) => [$run->createdBy['id'], $done($run)], autoRuns()))->toBe([[(string) $admin->id(), ['de']], [(string) $editor->id(), ['el']]])
            ->and(autoEntry($this->hello, 'de')->get('intro'))->toBe('[T] A longer intro.');
    });

    it('lets a later save wait again a minute after the process that waited stopped', function () {
        Sleep::fake();
        $admin = $this->signInAdmin();
        autoTranslated($this->hello, ['el']);
        $earlier = RunFixtures::started(['entryIds' => [$this->hello->id()], 'reference' => $this->hello->reference()]);
        autoOn();
        $this->hello->set('intro', 'A longer intro.')->saveQuietly();
        $job = fn () => app()->call([new UpdateTranslations($this->hello->id(), (string) $admin->id()), 'handle']);
        $stop = true;

        // The web server stops the first process while it waits; the next one waits until the earlier run ends.
        Sleep::whenFakingSleep(function () use (&$stop, $earlier) {
            $stop ? throw new RuntimeException('Stopped by the web server.') : app(RunProcessor::class)->cancel($earlier);
        });
        $job();
        $stop = false;
        $job();

        Sleep::assertSleptTimes(1);
        expect(autoRuns())->toBe([]);

        $this->travel(UpdateTranslations::WAITER_SECONDS + 1)->seconds();
        $job();

        Sleep::assertSleptTimes(2);
        expect(autoRuns())->toHaveCount(1)
            ->and(autoEntry($this->hello, 'el')->get('intro'))->toBe('[T] A longer intro.');
    });

    it('plans from what other processes saved while it waited, so it keeps no run with nothing to do', function () {
        Sleep::fake();
        $admin = $this->signInAdmin();
        autoTranslated($this->hello, ['el']);
        $earlier = RunFixtures::started(['entryIds' => [$this->hello->id()], 'reference' => $this->hello->reference()]);
        autoOn(['auto_update_create' => true]);
        $calls = $this->modelCalls();

        // While it waits, an editor writes the German localization by hand in another request.
        Sleep::whenFakingSleep(function () use ($earlier) {
            $this->localizeEntryElsewhere($this->hello->id(), 'de', ['title' => 'Hallo', 'intro' => 'Eine Einleitung.']);
            app(RunProcessor::class)->cancel($earlier);
        });

        app()->call([new UpdateTranslations($this->hello->id(), (string) $admin->id()), 'handle']);
        ContentWriter::refreshStache();

        expect(autoRuns())->toBe([])
            ->and($this->modelCalls())->toBe($calls)
            ->and(autoEntry($this->hello, 'de')->get('intro'))->toBe('Eine Einleitung.');
    });

    it('waits for a run to translate the localizations of a localization it saved, so they are translated once, whatever its mode', function (string $mode) {
        Sleep::fake();
        $admin = $this->signInAdmin();
        autoTranslated($this->hello, ['el']);
        // A chain: German is translated from Greek, so the Greek localization is an origin too. Greek has no introduction
        // of its own, so a run in any mode saves it.
        $this->localize(autoEntry($this->hello, 'el'), 'de');
        autoEntry($this->hello, 'el')->remove('intro')->published(true)->saveQuietly();
        autoOn();
        $this->fakeTranslations(fn (string $text) => '[U] '.$text);
        $calls = $this->modelCalls();
        $run = RunFixtures::started(['sites' => ['el', 'de'], 'entryIds' => [$this->hello->id()], 'options' => ['mode' => $mode], 'user' => $admin]);

        // A tick translates and saves Greek, and the next tick translates German; while the process that saved Greek waits
        // after its response, the next apply saves German.
        Sleep::whenFakingSleep(fn () => app(RunProcessor::class)->applyReady($run, 'apply-2', now()->addSeconds(30)));
        RunFixtures::translate($run);
        app(RunProcessor::class)->applyReady($run, 'apply-1', now()->addSeconds(30));
        RunFixtures::translate($run);
        afterResponse();
        RunFixtures::finish($run);

        expect(RunFixtures::statuses($run))->toBe(['done', 'done'])
            ->and($this->modelCalls() - $calls)->toBe(2)
            ->and(autoRuns())->toBe([])
            ->and(autoEntry($this->hello, 'el')->get('intro'))->toBe('[U] A short intro.')
            ->and(autoEntry($this->hello, 'de')->get('intro'))->toBe('[U] [U] A short intro.');
        Sleep::assertSleptTimes(1);
    })->with(['missing', 'update', 'all']);

    it('updates the localizations of a localization a run saved once the run is done with them, so only the others are translated again', function () {
        Sleep::fake();
        Site::setSites([...Site::config(), 'fr' => ['name' => 'French', 'url' => 'http://localhost/fr/', 'locale' => 'fr_FR', 'lang' => 'fr']]);
        $this->makeCollection('notes', ['en', 'el', 'de', 'fr'], [
            'title' => ['type' => 'text', 'localizable' => true],
            'intro' => ['type' => 'textarea', 'localizable' => true],
        ]);
        $note = $this->makeEntry('notes', 'en', ['title' => 'Note', 'intro' => 'A short note.'], 'note');
        $admin = $this->signInAdmin();
        autoTranslated($note, ['el']);
        // German and French are translated from Greek; the run updates Greek and German only.
        $this->localize(autoEntry($note, 'el'), 'de');
        $this->localize(autoEntry($note, 'el'), 'fr');
        autoEntry($note, 'el')->published(true)->saveQuietly();
        autoOn();
        $note->set('intro', 'A longer note.')->saveQuietly();
        $this->fakeTranslations(fn (string $text) => '[U] '.$text);
        $calls = $this->modelCalls();
        $run = RunFixtures::started(['sites' => ['el', 'de'], 'entryIds' => [$note->id()], 'options' => ['mode' => 'all'], 'user' => $admin]);

        Sleep::whenFakingSleep(fn () => app(RunProcessor::class)->applyReady($run, 'apply-2', now()->addSeconds(30)));
        RunFixtures::translate($run);
        app(RunProcessor::class)->applyReady($run, 'apply-1', now()->addSeconds(30));
        RunFixtures::translate($run);
        afterResponse();
        RunFixtures::finish($run);

        $done = fn (Run $run) => array_values(array_map(fn ($item) => $item->targetSite, array_filter(RunFixtures::items($run), fn ($item) => $item->status === RunItemStatus::Done)));

        expect(autoRuns())->toHaveCount(1)
            ->and($done(autoRuns()[0]))->toBe(['fr'])
            ->and(RunFixtures::statuses($run))->toBe(['done', 'done'])
            ->and($this->modelCalls() - $calls)->toBe(3)
            ->and(autoEntry($note, 'de')->get('intro'))->toBe('[U] [U] A longer note.')
            ->and(autoEntry($note, 'fr')->get('intro'))->toBe('[U] [U] A longer note.');
        Sleep::assertSleptTimes(1);
    });

    it('updates the localizations of a localization a run saved that the run then did not translate', function () {
        Sleep::fake();
        $admin = $this->signInAdmin();
        autoTranslated($this->hello, ['el']);
        $this->localize(autoEntry($this->hello, 'el'), 'de');
        autoEntry($this->hello, 'el')->published(true)->saveQuietly();
        autoOn();
        $this->hello->set('intro', 'A longer intro.')->saveQuietly();
        $this->fakeTranslations(fn (string $text) => '[U] '.$text);
        $calls = $this->modelCalls();
        $run = RunFixtures::started(['sites' => ['el', 'de'], 'entryIds' => [$this->hello->id()], 'options' => ['mode' => 'update'], 'user' => $admin]);

        // The run saves Greek and is cancelled before it translates German.
        Sleep::whenFakingSleep(fn () => app(RunProcessor::class)->cancel($run));
        RunFixtures::translate($run);
        app(RunProcessor::class)->applyReady($run, 'apply-1', now()->addSeconds(30));
        afterResponse();

        $done = fn (Run $run) => array_values(array_map(fn ($item) => $item->targetSite, array_filter(RunFixtures::items($run), fn ($item) => $item->status === RunItemStatus::Done)));

        expect(autoRuns())->toHaveCount(1)
            ->and($done(autoRuns()[0]))->toBe(['de'])
            ->and($done($run))->toBe(['el'])
            ->and($this->modelCalls() - $calls)->toBe(2)
            ->and(autoEntry($this->hello, 'de')->get('intro'))->toBe('[U] [U] A longer intro.');
        Sleep::assertSleptTimes(1);
    });

    it('leaves to a run the localizations it is still to translate from a localization it saved, without waiting, where the response of the save waits for the update', function () {
        Sleep::fake();
        app()->instance(WebServer::class, new WebServer('apache2handler', false));
        $admin = $this->signInAdmin();
        autoTranslated($this->hello, ['el']);
        $this->localize(autoEntry($this->hello, 'el'), 'de');
        autoEntry($this->hello, 'el')->published(true)->saveQuietly();
        autoOn();
        $this->hello->set('intro', 'A longer intro.')->saveQuietly();
        $this->fakeTranslations(fn (string $text) => '[U] '.$text);
        $calls = $this->modelCalls();
        $run = RunFixtures::started(['sites' => ['el', 'de'], 'entryIds' => [$this->hello->id()], 'options' => ['mode' => 'update'], 'user' => $admin]);

        // The browser's next apply waits for the response of the one that saved Greek.
        Sleep::whenFakingSleep(fn () => throw new RuntimeException('It waited for the run that waits for its response.'));
        RunFixtures::translate($run);
        app(RunProcessor::class)->applyReady($run, 'apply-1', now()->addSeconds(30));
        afterResponse();
        RunFixtures::finish($run);

        Sleep::assertNeverSlept();
        expect(autoRuns())->toBe([])
            ->and(RunFixtures::statuses($run))->toBe(['done', 'done'])
            ->and($this->modelCalls() - $calls)->toBe(2)
            ->and(autoEntry($this->hello, 'de')->get('intro'))->toBe('[U] [U] A longer intro.');
    });

    it('does nothing for a user who no longer exists, or an entry that is gone', function () {
        autoTranslated($this->hello, ['el']);
        autoOn();
        $this->hello->set('intro', 'A longer intro.')->saveQuietly();

        app()->call([new UpdateTranslations($this->hello->id(), 'gone'), 'handle']);
        app()->call([new UpdateTranslations('missing'), 'handle']);

        expect($this->runs->recent(10))->toBe([]);
    });
});

describe('with a queue worker', function () {
    beforeEach(function () {
        config(['queue.default' => 'database']);
        Queue::fake();
    });

    it('waits on the queue, so quick saves make one job, and a save once it runs queues another', function () {
        $this->signInAdmin();
        autoTranslated($this->hello, ['el']);
        autoOn();

        $this->hello->set('intro', 'A longer intro.')->save();
        $this->hello->set('intro', 'An even longer intro.')->save();

        Queue::assertPushed(UpdateTranslations::class, 1);
        Queue::assertPushed(UpdateTranslations::class, fn (UpdateTranslations $job) => $job->delay === UpdateTranslations::DELAY_SECONDS && $job->connection === 'database');

        // A worker releases the unique lock as it starts the job.
        (new UniqueLock(Cache::store()))->release(new UpdateTranslations($this->hello->id()));
        $this->hello->set('intro', 'The longest intro.')->save();

        Queue::assertPushed(UpdateTranslations::class, 2);
    });

    it('starts a queue run of the entry and leaves the work to its lanes', function () {
        $admin = $this->signInAdmin();
        autoTranslated($this->hello, ['el', 'de']);
        autoOn(['auto_update_publish' => true]);
        $this->hello->set('intro', 'A longer intro.')->saveQuietly();
        $calls = $this->modelCalls();

        $job = (new UpdateTranslations($this->hello->id(), (string) $admin->id()))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $run = autoRuns()[0];

        $job->assertNotReleased();
        Queue::assertPushed(WorkRun::class, config('tolk.concurrency.queue'));
        expect($run->status)->toBe(RunStatus::Running)
            ->and($run->driver)->toBe(RunDriver::Queue)
            ->and($run->options['publish_changes'])->toBeTrue()
            ->and($this->modelCalls())->toBe($calls);

        RunFixtures::finish($run);

        expect(autoEntry($this->hello, 'de')->get('intro'))->toBe('[T] A longer intro.');
    });

    it('makes the run into the sites the user who saved may access, so they may follow and cancel it', function () {
        autoTranslated($this->hello, ['el', 'de']);
        $editor = $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'edit articles entries']);
        autoOn();
        $this->hello->set('intro', 'A longer intro.')->saveQuietly();

        $job = (new UpdateTranslations($this->hello->id(), (string) $editor->id()))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
        $run = autoRuns()[0];

        expect($run->scope['sites'])->toBe(['el'])
            ->and(RunFixtures::statuses($run))->toBe(['pending']);
        $this->getJson(cp_route('tolk.runs.state', $run->id))->assertOk();
        $this->postJson(cp_route('tolk.runs.cancel', $run->id))->assertOk();
    });

    it('updates the localizations of a localization the worker saved as the user who saved the origin', function () {
        autoTranslated($this->hello, ['el']);
        // A chain: German is translated from Greek, so the Greek localization is an origin too.
        $this->localize(autoEntry($this->hello, 'el'), 'de');
        autoEntry($this->hello, 'el')->published(true)->saveQuietly();
        $editor = $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'access de site', 'edit articles entries']);
        autoOn();
        $this->hello->set('intro', 'A longer intro.')->saveQuietly();

        app()->call([(new UpdateTranslations($this->hello->id(), (string) $editor->id()))->withFakeQueueInteractions(), 'handle']);
        // A queue worker has no signed-in user.
        Auth::forgetUser();
        RunFixtures::finish(autoRuns()[0]);
        $greek = autoEntry($this->hello, 'el');

        expect($greek->get('intro'))->toBe('[T] A longer intro.');
        Queue::assertPushed(UpdateTranslations::class, fn (UpdateTranslations $job) => $job->entryId === $greek->id() && $job->userId === (string) $editor->id());
    });

    it('does not go on along a chain into a site the user who saved the origin may not access', function () {
        autoTranslated($this->hello, ['el']);
        $this->localize(autoEntry($this->hello, 'el'), 'de');
        autoEntry($this->hello, 'el')->published(true)->saveQuietly();
        $german = autoEntry($this->hello, 'de')->get('intro');
        $editor = $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'edit articles entries']);
        autoOn();
        $this->hello->set('intro', 'A longer intro.')->saveQuietly();

        app()->call([(new UpdateTranslations($this->hello->id(), (string) $editor->id()))->withFakeQueueInteractions(), 'handle']);
        Auth::forgetUser();
        RunFixtures::finish(autoRuns()[0]);
        $greek = autoEntry($this->hello, 'el');

        expect($greek->get('intro'))->toBe('[T] A longer intro.');
        Queue::assertNotPushed(UpdateTranslations::class, fn (UpdateTranslations $job) => $job->entryId === $greek->id());

        app()->call([(new UpdateTranslations($greek->id(), (string) $editor->id()))->withFakeQueueInteractions(), 'handle']);

        expect(autoRuns())->toHaveCount(1)
            ->and(autoEntry($this->hello, 'de')->get('intro'))->toBe($german);
    });

    it('is released while an earlier run from the entry is still moving, however many runs were made since', function () {
        $admin = $this->signInAdmin();
        autoTranslated($this->hello, ['el']);
        RunFixtures::started(['entryIds' => [$this->hello->id()], 'reference' => $this->hello->reference()]);
        $other = $this->makeEntry('articles', 'en', ['title' => 'Other'], 'other');

        foreach (range(1, AutoUpdater::RECENT) as $ignored) {
            RunFixtures::pending(['entryIds' => [$other->id()]]);
        }

        autoOn();
        $this->hello->set('intro', 'A longer intro.')->saveQuietly();

        $job = (new UpdateTranslations($this->hello->id(), (string) $admin->id()))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(UpdateTranslations::RETRY_SECONDS);
        expect(autoRuns())->toBe([]);
    });

    it('is released while an earlier run from the entry is still moving', function () {
        $admin = $this->signInAdmin();
        autoTranslated($this->hello, ['el']);
        RunFixtures::started(['entryIds' => [$this->hello->id()], 'reference' => $this->hello->reference()]);
        autoOn();
        $this->hello->set('intro', 'A longer intro.')->saveQuietly();

        $job = (new UpdateTranslations($this->hello->id(), (string) $admin->id()))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(UpdateTranslations::RETRY_SECONDS);
        expect(autoRuns())->toBe([]);

        $this->travel(config('tolk.runs.claim_ttl') + 1)->seconds();
        $job = (new UpdateTranslations($this->hello->id(), (string) $admin->id()))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertNotReleased();
        expect(autoRuns())->toHaveCount(1);
    });

    it('is released while the run that saved the entry is still to translate localizations from it', function () {
        $admin = $this->signInAdmin();
        autoTranslated($this->hello, ['el']);
        $this->localize(autoEntry($this->hello, 'el'), 'de');
        autoEntry($this->hello, 'el')->published(true)->saveQuietly();
        autoOn();
        $this->hello->set('intro', 'A longer intro.')->saveQuietly();
        $run = RunFixtures::started(['sites' => ['el', 'de'], 'entryIds' => [$this->hello->id()], 'options' => ['mode' => 'update'], 'user' => $admin]);
        RunFixtures::translate($run);
        app(RunProcessor::class)->applyReady($run, 'apply-1', now()->addSeconds(30));
        $greek = autoEntry($this->hello, 'el');
        $job = fn () => (new UpdateTranslations($greek->id(), (string) $admin->id(), [['run' => $run->id, 'item' => 0]]))->withFakeQueueInteractions();

        Queue::assertPushed(UpdateTranslations::class, fn (UpdateTranslations $job) => $job->entryId === $greek->id() && $job->after === [['run' => $run->id, 'item' => 0]]);

        app()->call([$released = $job(), 'handle']);
        RunFixtures::finish($run);
        app()->call([$updated = $job(), 'handle']);

        $released->assertReleased(UpdateTranslations::RETRY_SECONDS);
        $updated->assertNotReleased();
        expect(autoRuns())->toBe([])
            ->and(RunFixtures::statuses($run))->toBe(['done', 'done']);
    });
});

describe('in a queue worker of the site', function () {
    beforeEach(function () {
        // Tolk's runs have no worker (`sync`), but the site's imports go on a connection of its own that has one.
        config([
            'database.connections.tolk-test-jobs' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'queue.connections.imports' => ['driver' => 'database', 'connection' => 'tolk-test-jobs', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90],
        ]);
        Schema::connection('tolk-test-jobs')->create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        autoTranslated($this->hello, ['el', 'de']);
        autoOn();
    });

    it('queues the update of an entry a job saved on that job\'s queue, whose worker drives the run, as its process never ends a response', function (array $runs) {
        config($runs);
        $this->fakeTranslations(fn (string $text) => '[U] '.$text);
        $id = $this->hello->id();

        dispatch(static fn () => Entry::find($id)->set('intro', 'A longer intro.')->save())->onConnection('imports')->onQueue('imports');
        workImport();

        expect(autoRuns())->toBe([])
            ->and(importJobs())->toBe([['queue' => 'imports', 'job' => UpdateTranslations::class, 'timeout' => 0, 'attempts' => 0]]);

        $this->travel(UpdateTranslations::DELAY_SECONDS)->seconds();
        workImport();
        $run = autoRuns()[0] ?? null;

        expect(autoRuns())->toHaveCount(1)
            ->and($run->status)->toBe(RunStatus::Completed)
            ->and($run->driver)->toBe(RunDriver::Cli)
            ->and(app(RunPlanner::class)->planOptions($run)->profile)->toBe('background')
            ->and($run->createdBy)->toBeNull()
            ->and(RunFixtures::statuses($run))->toBe(['done', 'done'])
            ->and(autoEntry($this->hello, 'el')->get('intro'))->toBe('[U] A longer intro.')
            ->and(autoEntry($this->hello, 'de')->get('intro'))->toBe('[U] A longer intro.')
            ->and(importJobs())->toBe([]);
    })->with([
        'runs on sync' => [[]],
        'runs on deferred' => [['tolk.runs.queue.connection' => 'deferred']],
        'runs in the browser' => [['tolk.runs.queue.connection' => 'imports', 'tolk.runs.execution' => 'browser']],
    ]);

    it('is released while an earlier run from the entry is still moving, so the worker goes on with other jobs', function () {
        $earlier = RunFixtures::started(['entryIds' => [$this->hello->id()], 'reference' => $this->hello->reference()]);
        $id = $this->hello->id();

        dispatch(static fn () => Entry::find($id)->set('intro', 'A longer intro.')->save())->onConnection('imports')->onQueue('imports');
        workImport();
        $this->travel(UpdateTranslations::DELAY_SECONDS)->seconds();
        workImport();

        expect(autoRuns())->toBe([])
            ->and(importJobs())->toBe([['queue' => 'imports', 'job' => UpdateTranslations::class, 'timeout' => 0, 'attempts' => 1]]);

        app(RunProcessor::class)->cancel($earlier);
        $this->travel(UpdateTranslations::RETRY_SECONDS)->seconds();
        workImport();

        expect(autoRuns())->toHaveCount(1)
            ->and(RunFixtures::statuses(autoRuns()[0]))->toBe(['done', 'done'])
            ->and(autoEntry($this->hello, 'el')->get('intro'))->toBe('[T] A longer intro.')
            ->and(importJobs())->toBe([]);
    });

    it('queues the update on the worker\'s queue also when a job on a connection without a worker saves inside its job', function () {
        $id = $this->hello->id();

        dispatch(static function () use ($id) {
            dispatch(static fn () => Entry::find($id)->set('intro', 'A longer intro.')->save())->onConnection('sync');
        })->onConnection('imports')->onQueue('imports');
        workImport();

        expect(importJobs())->toBe([['queue' => 'imports', 'job' => UpdateTranslations::class, 'timeout' => 0, 'attempts' => 0]]);
    });

    it('still updates after the response an entry a job saved on a connection without a worker', function () {
        Bus::fake([UpdateTranslations::class]);
        $id = $this->hello->id();

        dispatch(static fn () => Entry::find($id)->set('intro', 'A longer intro.')->save())->onConnection('sync');

        Bus::assertDispatchedAfterResponseTimes(UpdateTranslations::class, 1);
        expect(importJobs())->toBe([]);
    });

    it('reads again for each job what the user who saved may access, so a worker no longer updates a site they lost since its last job', function (Closure $revoke) {
        $this->asQueueWorker();
        $permissions = ['access cp', 'translate content', 'access en site', 'access el site', 'edit articles entries'];
        Role::make('translator')->title('Translator')->addPermission([...$permissions, 'access de site'])->save();
        Role::make('greek')->title('Greek')->addPermission($permissions)->save();
        $editor = User::make()->email('translator@example.com')->assignRole('translator');
        $editor->save();
        $update = function (string $intro) use ($editor) {
            $this->hello->set('intro', $intro)->saveQuietly();
            dispatch((new UpdateTranslations($this->hello->id(), (string) $editor->id()))->onConnection('imports')->onQueue('imports'));
            workImport();
        };

        $update('A longer intro.');

        expect(autoEntry($this->hello, 'de')->get('intro'))->toBe('[T] A longer intro.');

        $revoke($editor, $permissions);
        $update('The longest intro.');

        expect(autoRuns())->toHaveCount(2)
            ->and(autoRuns()[0]->scope['sites'])->toBe(['el'])
            ->and(autoEntry($this->hello, 'el')->get('intro'))->toBe('[T] The longest intro.')
            ->and(autoEntry($this->hello, 'de')->get('intro'))->toBe('[T] A longer intro.');
    })->with([
        // Another process saved the role: roles.yaml changed, and this process's roles and permissions did not.
        'its role loses the site' => [function (UserContract $editor, array $permissions) {
            file_put_contents(config('statamic.users.repositories.file.paths.roles'), YAML::dump([
                'translator' => ['title' => 'Translator', 'permissions' => $permissions],
                'greek' => ['title' => 'Greek', 'permissions' => $permissions],
            ]));
        }],
        'it is given a role without the site' => [fn (UserContract $editor) => User::find($editor->id())->explicitRoles(['greek'])->save()],
    ]);
});

describe('the banner', function () {
    it('says when saving an origin updates its translations', function () {
        $this->signInAdmin();
        autoTranslated($this->hello, ['el']);
        $status = fn (EntryContract $entry) => $this->getJson(cp_route('tolk.editor.status', ['reference' => $entry->reference()]))->assertOk();

        $status($this->hello)->assertJsonPath('origin.auto_update', null)->assertJsonPath('origin.auto_update_sites', []);

        autoOn();
        $status($this->hello)
            ->assertJsonPath('origin.auto_update', 'save')
            ->assertJsonPath('origin.localizations.*.site', ['el', 'de'])
            ->assertJsonPath('origin.auto_update_sites', ['el']);

        autoOn(['auto_update_create' => true]);
        $status($this->hello)->assertJsonPath('origin.auto_update_sites', ['el', 'de']);

        $this->hello->published(false)->saveQuietly();
        $status($this->hello)->assertJsonPath('origin.auto_update', 'publish');

        autoOn(['auto_update_collections' => ['pages']]);
        $status($this->hello)->assertJsonPath('origin.auto_update', null)->assertJsonPath('origin.auto_update_sites', []);
    });

    it('names only the sites the user may access among those a save updates', function () {
        autoTranslated($this->hello, ['el']);
        $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'edit articles entries']);
        autoOn(['auto_update_create' => true]);

        $this->getJson(cp_route('tolk.editor.status', ['reference' => $this->hello->reference()]))
            ->assertOk()
            ->assertJsonPath('origin.localizations.*.site', ['el'])
            ->assertJsonPath('origin.auto_update_sites', ['el']);
    });

    it('follows the running automatic run of the user who saved', function () {
        config(['queue.default' => 'database']);
        Queue::fake();
        $admin = $this->signInAdmin();
        autoTranslated($this->hello, ['el']);
        autoOn();
        $this->hello->set('intro', 'A longer intro.')->saveQuietly();

        $run = app(AutoUpdater::class)->start($this->hello, $admin, drive: false);

        $this->getJson(cp_route('tolk.editor.status', ['reference' => $this->hello->reference()]))
            ->assertOk()
            ->assertJsonPath('active_run.run', $run->id)
            ->assertJsonPath('active_run.automatic', true);
    });

    it('tells a localization when an automatic update last saved it', function () {
        $admin = $this->signInAdmin();
        autoTranslated($this->hello, ['el']);
        autoOn();
        $this->hello->set('intro', 'A longer intro.')->saveQuietly();
        $status = fn () => $this->getJson(cp_route('tolk.editor.status', ['reference' => autoEntry($this->hello, 'el')->reference()]))->assertOk();

        $status()->assertJsonPath('localization.auto_updated', null);

        $this->travel(5)->minutes();
        app()->call([new UpdateTranslations($this->hello->id(), (string) $admin->id()), 'handle']);

        $status()->assertJsonPath('localization.auto_updated', ['at' => now()->toIso8601ZuluString(), 'unpublished' => false]);

        autoOn(['auto_update' => false]);
        $status()->assertJsonPath('localization.auto_updated', null);
    });

    describe('with revisions', function () {
        beforeEach(function () {
            $this->admin = $this->signInAdmin();
            autoTranslated($this->hello, ['el']);
            config(['statamic.revisions.enabled' => true]);
            Collection::find('articles')->revisionsEnabled(true)->save();
            autoEntry($this->hello, 'el')->publish(['user' => $this->admin]);
            $this->hello->set('intro', 'A longer intro.')->saveQuietly();
            $this->travel(5)->minutes();

            $this->unpublished = fn () => $this->getJson(cp_route('tolk.editor.status', ['reference' => autoEntry($this->hello, 'el')->reference()]))
                ->assertOk()
                ->json('localization.auto_updated.unpublished');
            // An editor's change saved from the CP: it goes into the working copy of the published localization.
            $this->editWorkingCopy = function (string $title) {
                $this->travel(1)->minutes();
                autoEntry($this->hello, 'el')->fromWorkingCopy()->set('title', $title)->makeWorkingCopy()->user($this->admin)->save();
            };
        });

        it('says the update waits in the working copy until somebody publishes the localization', function () {
            autoOn();
            app()->call([new UpdateTranslations($this->hello->id(), (string) $this->admin->id()), 'handle']);

            expect(autoEntry($this->hello, 'el')->fromWorkingCopy()->get('intro'))->toBe('[T] A longer intro.')
                ->and(($this->unpublished)())->toBeTrue();

            ($this->editWorkingCopy)('Γεια');
            expect(($this->unpublished)())->toBeTrue();

            $this->travel(1)->minutes();
            autoEntry($this->hello, 'el')->publish(['user' => $this->admin]);
            expect(($this->unpublished)())->toBeFalse();

            ($this->editWorkingCopy)('Γεια σου');
            expect(autoEntry($this->hello, 'el')->hasWorkingCopy())->toBeTrue()
                ->and(($this->unpublished)())->toBeFalse();
        });

        it('does not say an update it published is not published when a later change waits in the working copy', function () {
            autoOn(['auto_update_publish' => true]);
            app()->call([new UpdateTranslations($this->hello->id(), (string) $this->admin->id()), 'handle']);

            expect(autoEntry($this->hello, 'el')->get('intro'))->toBe('[T] A longer intro.')
                ->and(($this->unpublished)())->toBeFalse();

            ($this->editWorkingCopy)('Γεια');
            expect(autoEntry($this->hello, 'el')->hasWorkingCopy())->toBeTrue()
                ->and(($this->unpublished)())->toBeFalse();
        });
    });
});
