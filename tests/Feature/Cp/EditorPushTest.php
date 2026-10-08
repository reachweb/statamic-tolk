<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Jobs\WorkRun;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

beforeEach(function () {
    $this->useFileCache();
    $this->freezeSecond();
    config(['tolk.runs.execution' => 'browser']);

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true],
    ]);

    $this->hello = $this->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'A short intro.'], 'hello');
    $this->runs = app(RunRepository::class);
});

/**
 * Pushes the entry into Greek and German as the banner's Translate into… does; the body's keys replace those.
 */
function pushEntry(EntryContract|string $entry, array $body = []): TestResponse
{
    return test()->postJson(cp_route('tolk.editor.push'), [
        'reference' => $entry instanceof EntryContract ? $entry->reference() : $entry,
        'sites' => ['el', 'de'],
        'mode' => 'missing',
        ...$body,
    ]);
}

function pushedRun(TestResponse $response): Run
{
    return app(RunRepository::class)->find($response->assertCreated()->json('run'));
}

function pushStatus(EntryContract $entry): TestResponse
{
    return test()->getJson(cp_route('tolk.editor.status', ['reference' => $entry->reference()]));
}

/**
 * Ticks one lane and applies through the URLs the push answered with, as the banner does, until neither has work
 * left; at most ten rounds.
 */
function drivePush(array $urls): array
{
    for ($round = 0; $round < 10; $round++) {
        $ticked = test()->postJson($urls['tick'], ['nonce' => 'bannerNonce1', 'lane' => 1])->assertOk()->json('report');
        $applied = test()->postJson($urls['apply'])->assertOk()->json();

        if (! $ticked['remains'] && ! $applied['report']['remains']) {
            return $applied;
        }
    }

    throw new RuntimeException('The push did not finish in ten rounds.');
}

/**
 * A user who may translate articles in the editor into every site, but not run bulk translations.
 */
function editorTranslator(): UserContract
{
    return test()->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'access de site', 'edit articles entries']);
}

describe('push', function () {
    it('creates and starts a run of the open entry with one item per site', function () {
        $this->fakeTranslations();
        $admin = $this->signInAdmin();

        $response = pushEntry($this->hello, ['overwrite_manual' => true, 'review' => true, 'new_status' => 'mirror']);
        $run = pushedRun($response);

        expect($run->status)->toBe(RunStatus::Running)
            ->and($run->driver)->toBe(RunDriver::Browser)
            ->and($run->createdBy['id'])->toBe((string) $admin->id())
            ->and($run->scope)->toMatchArray(['sites' => ['el', 'de'], 'source_site' => 'en', 'entry_ids' => [$this->hello->id()], 'reference' => $this->hello->reference()])
            ->and($run->options)->toMatchArray(['mode' => 'missing', 'overwrite_manual' => true, 'review' => true, 'new_status' => 'mirror'])
            ->and(array_map(fn ($item) => [$item->sourceId, $item->targetSite, $item->status->value], RunFixtures::items($run)))->toBe([
                [$this->hello->id(), 'el', 'pending'],
                [$this->hello->id(), 'de', 'pending'],
            ])
            ->and($run->estimate)->toBeNull()
            ->and($response->json())->toMatchArray([
                'run' => $run->id,
                'driver' => 'browser',
                'lanes' => config('tolk.concurrency.browser'),
                'urls' => [
                    'state' => cp_route('tolk.runs.state', $run->id),
                    'tick' => cp_route('tolk.runs.tick', $run->id),
                    'apply' => cp_route('tolk.runs.apply', $run->id),
                    'cancel' => cp_route('tolk.runs.cancel', $run->id),
                ],
            ])
            ->and($response->json('state.run.status'))->toBe('running')
            ->and($response->json('state.counts.pending'))->toBe(2);
        $this->assertNoModelCalls();
    });

    it('creates the localizations as drafts, or as the origin is with Same as origin', function () {
        $this->fakeTranslations();
        $this->signInAdmin();

        drivePush(pushEntry($this->hello, ['sites' => ['el']])->assertCreated()->json('urls'));
        drivePush(pushEntry($this->hello, ['sites' => ['de'], 'new_status' => 'mirror'])->assertCreated()->json('urls'));

        $greek = Entry::find($this->hello->id())->in('el');
        $german = Entry::find($this->hello->id())->in('de');

        expect($greek->get('title'))->toBe('[T] Hello')
            ->and($greek->published())->toBeFalse()
            ->and($german->get('intro'))->toBe('[T] A short intro.')
            ->and($german->published())->toBeTrue();
    });

    it('updates only the outdated fields of the sites asked for', function () {
        $this->fakeTranslations();
        $this->signInAdmin();
        drivePush(pushEntry($this->hello)->assertCreated()->json('urls'));

        $this->hello->set('intro', 'A longer intro.')->save();
        pushStatus($this->hello)->assertJsonPath('origin.localizations.0.status', 'outdated');

        $done = drivePush(pushEntry($this->hello, ['mode' => 'update'])->assertCreated()->json('urls'));

        expect($done['run']['status'])->toBe('completed')
            ->and(array_column($done['items'], 'fields'))->toEqual([
                ['intro' => 'applied', 'title' => 'current'],
                ['intro' => 'applied', 'title' => 'current'],
            ])
            ->and(Entry::find($this->hello->id())->in('el')->get('intro'))->toBe('[T] A longer intro.')
            ->and(Entry::find($this->hello->id())->in('de')->get('title'))->toBe('[T] Hello');
    });

    it('pushes from an entry in the middle of a chain into its own localizations only', function () {
        $this->fakeTranslations();
        $this->signInAdmin();
        $german = $this->localize($this->hello, 'de', ['title' => 'Hallo']);
        $german->makeLocalization('el')->save();

        pushEntry($this->hello, ['sites' => ['el']])->assertUnprocessable()->assertJsonPath('code', 'invalid_sites');

        $response = pushEntry($german, ['sites' => ['el'], 'mode' => 'all']);
        $run = pushedRun($response);
        drivePush($response->json('urls'));

        expect(RunFixtures::items($run)[0]->sourceId)->toBe($german->id())
            ->and($run->scope['source_site'])->toBe('de')
            ->and(Entry::find($german->id())->in('el')->get('title'))->toBe('[T] Hallo');
    });

    it('starts a queue run with its lanes', function () {
        Queue::fake();
        config(['tolk.runs.execution' => 'queue', 'tolk.concurrency.queue' => 2]);
        $this->signInAdmin();

        $response = pushEntry($this->hello);
        $run = pushedRun($response);

        expect($response->json('driver'))->toBe('queue')
            ->and($run->status)->toBe(RunStatus::Running);
        Queue::assertPushed(WorkRun::class, 2);
    });
});

describe('refusals', function () {
    it('refuses sites the banner does not list', function () {
        $this->signInAdmin();

        pushEntry($this->hello, ['sites' => ['el', 'fr']])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_sites')
            ->assertJsonPath('message', 'This entry cannot be translated into fr from here.');
        pushEntry($this->hello, ['sites' => ['en']])->assertUnprocessable()->assertJsonPath('code', 'invalid_sites');

        expect($this->runs->recent(10))->toBe([]);
    });

    it('refuses a localization that is the origin of nothing, and an unknown entry', function () {
        $this->signInAdmin();
        $greek = $this->localize($this->hello, 'el');

        pushEntry($greek, ['sites' => ['de']])->assertUnprocessable()->assertJsonPath('code', 'not_an_origin');
        pushEntry('entry::missing')->assertNotFound()->assertJsonPath('code', 'unknown_reference');
        pushEntry('term::tags::hotel')->assertNotFound()->assertJsonPath('code', 'unknown_reference');

        expect($this->runs->recent(10))->toBe([]);
    });

    it('refuses a second push while the first runs, naming it', function () {
        $this->signInAdmin();
        $first = pushedRun(pushEntry($this->hello));

        pushEntry($this->hello, ['sites' => ['el']])
            ->assertConflict()
            ->assertJsonPath('code', 'run_active')
            ->assertJsonPath('active_run.run', $first->id);

        expect($this->runs->recent(10))->toHaveCount(1);
    });

    it('refuses a user who may not edit the entry or the sites, or lacks translate content', function () {
        $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'view articles entries']);
        pushEntry($this->hello, ['sites' => ['el']])->assertForbidden();

        // German is refused to a user without access to it, before the request is validated (T12.3).
        $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'edit articles entries']);
        pushEntry($this->hello, ['sites' => ['de']])->assertForbidden();
        pushEntry($this->hello, ['sites' => ['el', 'de'], 'mode' => 'review'])->assertForbidden();

        $this->signInWithPermissions(['access cp', 'run bulk translations', 'access en site', 'access el site', 'edit articles entries']);
        pushEntry($this->hello, ['sites' => ['el']])->assertForbidden();

        expect($this->runs->recent(10))->toBe([]);
    });

    it('refuses a push into sites whose translations the user may not edit, and skips them in a push with others', function () {
        $this->makeCollection('stories', ['en', 'el', 'de'], [
            'title' => ['type' => 'text', 'localizable' => true],
            'author' => ['type' => 'users', 'localizable' => true],
        ]);
        $user = $this->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'access de site', 'edit stories entries']);
        $story = $this->makeEntry('stories', 'en', ['title' => 'Story', 'author' => [$user->id()]], 'story');
        $this->localize($story, 'el', ['author' => ['someone-else']]);

        pushEntry($story, ['sites' => ['el']])->assertForbidden();

        $run = pushedRun(pushEntry($story, ['sites' => ['el', 'de']]));

        expect(array_map(fn ($item) => $item->targetSite, RunFixtures::items($run)))->toBe(['de'])
            ->and($run->scope['skipped'])->toBe(['unauthorized' => 1]);
    });

    it('refuses a push from a collection excluded in the settings', function () {
        $this->signInAdmin();
        $this->withSettings(['excluded_collections' => ['articles']]);

        pushEntry($this->hello)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'nothing_to_translate')
            ->assertJsonPath('message', 'Entries of this collection are excluded from translation in the settings.');
        expect($this->runs->recent(10))->toBe([]);
    });

    it('refuses a push when no model can translate', function () {
        $this->withProviderKeys();
        $this->signInAdmin();

        pushEntry($this->hello)->assertUnprocessable()->assertJsonPath('code', 'no_translator');
    });
});

describe('the creator', function () {
    it('lets a user with translate content drive the push, but not open or manage runs', function () {
        $this->fakeTranslations();
        editorTranslator();

        $response = pushEntry($this->hello)->assertCreated();
        $run = pushedRun($response);
        $done = drivePush($response->json('urls'));

        expect($done['run']['status'])->toBe('completed')
            ->and($done['counts']['done'])->toBe(2)
            ->and(Entry::find($this->hello->id())->in('el')->published())->toBeFalse();

        $this->getJson($response->json('urls.state'))->assertOk();
        $this->postJson($response->json('urls.cancel'))->assertOk();
        $this->getJson(cp_route('tolk.runs.index'))->assertForbidden();
        $this->getJson(cp_route('tolk.runs.show', $run->id))->assertForbidden();
        $this->postJson(cp_route('tolk.runs.retry', $run->id))->assertForbidden();
        $this->postJson(cp_route('tolk.runs.resume', $run->id))->assertForbidden();
    });

    it('can cancel the push', function () {
        $this->fakeTranslations();
        editorTranslator();
        $response = pushEntry($this->hello)->assertCreated();

        $this->postJson($response->json('urls.cancel'))->assertOk()->assertJsonPath('run.status', 'cancelled');
        $this->postJson($response->json('urls.tick'), ['nonce' => 'bannerNonce1', 'lane' => 1])->assertOk()->assertJsonPath('report.items', 0);
        $this->assertNoModelCalls();
    });
});

describe('active_run', function () {
    it('is reported on the origin until the run is final', function () {
        $this->fakeTranslations();
        editorTranslator();
        pushStatus($this->hello)->assertJsonPath('active_run', null);

        $response = pushEntry($this->hello)->assertCreated();
        $active = pushStatus($this->hello)->assertOk()->json('active_run');

        expect($active)->toBe([
            'run' => $response->json('run'),
            'driver' => 'browser',
            'lanes' => config('tolk.concurrency.browser'),
            'urls' => $response->json('urls'),
        ]);

        $this->postJson($response->json('urls.tick'), ['nonce' => 'bannerNonce1', 'lane' => 1])->assertOk();
        pushStatus($this->hello)->assertJsonPath('active_run.run', $response->json('run'));

        drivePush($response->json('urls'));

        pushStatus($this->hello)->assertJsonPath('active_run', null);
    });

    it('is not reported after a cancel, to another user, on another entry or for a bulk run', function () {
        $this->fakeTranslations();
        $admin = $this->signInAdmin();
        $other = $this->makeEntry('articles', 'en', ['title' => 'Other'], 'other');
        $response = pushEntry($this->hello)->assertCreated();

        pushStatus($other)->assertJsonPath('active_run', null);

        editorTranslator();
        pushStatus($this->hello)->assertJsonPath('active_run', null);

        $this->actingAs($admin);
        $this->postJson($response->json('urls.cancel'))->assertOk();
        pushStatus($this->hello)->assertJsonPath('active_run', null);

        // A run of the same entry that was not pushed from the editor, and a pushed run that never started: the
        // banner could not drive it, because starting a run needs `run bulk translations`.
        RunFixtures::started(['entryIds' => [$this->hello->id()], 'user' => $admin]);
        RunFixtures::pending(['entryIds' => [$this->hello->id()], 'user' => $admin, 'reference' => $this->hello->reference()]);
        pushStatus($this->hello)->assertJsonPath('active_run', null);
    });

    it('is reported only with the origin block, which a localization without localizations has not', function () {
        $admin = $this->signInAdmin();
        $greek = $this->localize($this->hello, 'el');
        RunFixtures::started(['entryIds' => [$greek->id()], 'user' => $admin, 'reference' => $greek->reference()]);

        pushStatus($greek)->assertJsonPath('origin', null)->assertJsonPath('active_run', null);
    });

    it('carries the default status of new localizations', function () {
        $this->signInAdmin();
        pushStatus($this->hello)->assertJsonPath('defaults.new_status', 'draft');

        $this->withSettings(['new_localization_status' => 'mirror']);
        pushStatus($this->hello)->assertJsonPath('defaults.new_status', 'mirror');
    });
});
