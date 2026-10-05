<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Events\RunFinished;
use Reach\StatamicTolk\Jobs\WorkRun;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunItem;
use Reach\StatamicTolk\Runs\RunProcessor;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Statamic\Contracts\Auth\User as UserContract;

const RUN_NONCE = 'pageNonce123';

beforeEach(function () {
    $this->useFileCache();
    $this->freezeSecond();
    config(['tolk.runs.execution' => 'browser']);

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true],
    ]);

    $this->hello = $this->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'A short intro.'], 'hello');
    $this->world = $this->makeEntry('articles', 'en', ['title' => 'World', 'intro' => 'Another intro.'], 'world');
    $this->again = $this->makeEntry('articles', 'en', ['title' => 'Again', 'intro' => 'A third intro.'], 'again');

    $this->runs = app(RunRepository::class);
});

/**
 * Creates a run of the Greek articles; a null in the body leaves that key out.
 */
function storeRun(array $body = []): TestResponse
{
    $body = array_filter(['sites' => ['el'], 'collections' => ['articles'], ...$body], fn ($value) => $value !== null);

    return test()->postJson(cp_route('tolk.runs.store'), $body);
}

function createdRun(array $body = []): Run
{
    return app(RunRepository::class)->find(storeRun($body)->assertCreated()->json('run'));
}

function runAction(Run $run, string $action, array $body = []): TestResponse
{
    return test()->postJson(cp_route('tolk.runs.'.$action, $run->id), $body);
}

function tick(Run $run, int $lane = 1, string $nonce = RUN_NONCE, array $body = []): TestResponse
{
    return runAction($run, 'tick', ['nonce' => $nonce, 'lane' => $lane, ...$body]);
}

function runState(Run $run, array $query = []): TestResponse
{
    return test()->getJson(cp_route('tolk.runs.state', ['run' => $run->id, ...$query]));
}

/**
 * Ticks one lane and applies, as the run page does, until neither has work left; at most ten rounds.
 */
function driveRun(Run $run): array
{
    for ($round = 0; $round < 10; $round++) {
        $ticked = tick($run)->assertOk()->json('report');
        $applied = runAction($run, 'apply')->assertOk()->json();

        if (! $ticked['remains'] && ! $applied['report']['remains']) {
            return $applied;
        }
    }

    throw new RuntimeException('The run did not finish in ten rounds.');
}

/**
 * Every item status with the given counts, the others 0.
 */
function runCounts(array $counts): array
{
    return [...array_fill_keys(array_column(RunItemStatus::cases(), 'value'), 0), ...$counts];
}

/**
 * A user who may translate articles into Greek in the editor, but not run bulk translations.
 */
function greekTranslator(): UserContract
{
    return test()->signInWithPermissions(['access cp', 'translate content', 'access en site', 'access el site', 'edit articles entries']);
}

/**
 * Fails the item as a worker would, and gives back the items claimed on the way.
 */
function failItem(Run $run, int $index): void
{
    $repository = app(RunRepository::class);
    $passed = [];

    while ($item = $repository->claimNext($run, 'test-worker-'.count($passed), 300)) {
        if ($item->index === $index) {
            $item->status = RunItemStatus::Failed;
            $item->error = 'Boom';
            $repository->saveItem($run, $item);
            break;
        }

        $passed[] = $item;
    }

    foreach ($passed as $item) {
        $repository->release($run, $item);
    }
}

describe('store', function () {
    it('creates a pending run of the request and translates nothing', function () {
        $this->fakeTranslations();
        $admin = $this->signInAdmin();

        $response = storeRun(['options' => ['mode' => 'all', 'new_status' => 'mirror']])->assertCreated();
        $run = $this->runs->find($response->json('run'));

        expect($response->json('url'))->toBe(cp_route('tolk.runs.show', $run->id))
            ->and($run->status)->toBe(RunStatus::Pending)
            ->and($run->driver)->toBe(RunDriver::Browser)
            ->and($run->createdBy)->toBe(['id' => (string) $admin->id(), 'name' => 'admin@example.com'])
            ->and($run->options)->toMatchArray(['mode' => 'all', 'new_status' => 'mirror'])
            ->and($run->scope)->toMatchArray(['sites' => ['el'], 'collections' => ['articles']])
            ->and(RunFixtures::statuses($run))->toBe(['pending', 'pending', 'pending'])
            ->and($this->hello->fresh()->in('el'))->toBeNull();
        $this->assertNoModelCalls();
    });

    it('takes the driver from the execution setting', function () {
        config(['tolk.runs.execution' => 'queue']);
        Queue::fake();
        $this->signInAdmin();

        expect(createdRun()->driver)->toBe(RunDriver::Queue);
        Queue::assertNotPushed(WorkRun::class);
    });

    it('resolves selected entries to their families', function () {
        $this->signInAdmin();
        $greek = $this->localize($this->hello, 'el');

        $run = createdRun(['collections' => null, 'entry_ids' => [$greek->id(), $this->world->id()], 'sites' => ['el', 'de']]);

        expect(array_map(fn (RunItem $item) => [$item->sourceId, $item->targetSite], RunFixtures::items($run)))->toBe([
            [$this->hello->id(), 'el'],
            [$this->hello->id(), 'de'],
            [$this->world->id(), 'el'],
            [$this->world->id(), 'de'],
        ]);
    });

    it('refuses a request it cannot plan', function (array $body, string $error) {
        $this->signInAdmin();

        $response = storeRun($body);

        expect($response->status())->toBe(422)
            ->and($response->json('message'))->toContain($error);
    })->with([
        'unknown site' => [['sites' => ['fr']], 'fr'],
        'unknown source site' => [['source_site' => 'fr'], 'fr'],
        'unknown collection' => [['collections' => ['news']], 'Collection [news] not found.'],
        'unknown entry' => [['collections' => null, 'entry_ids' => ['missing']], 'missing'],
    ]);

    it('validates the request', function (array $body, string $field) {
        $this->signInAdmin();

        storeRun($body)->assertUnprocessable()->assertJsonValidationErrors($field);
    })->with([
        'no sites' => [['sites' => []], 'sites'],
        'sites as a map' => [['sites' => ['a' => 'el']], 'sites'],
        'nothing to translate' => [['collections' => null], 'collections'],
        'an unknown option' => [['options' => ['speed' => 'fast']], 'options'],
        'an unknown mode' => [['options' => ['mode' => 'quick']], 'options.mode'],
        'an unknown status' => [['options' => ['new_status' => 'published']], 'options.new_status'],
        'a malformed model' => [['options' => ['translator' => 'claude']], 'options.translator'],
    ]);

    it('accepts only catalog models from a user who may not edit the settings', function () {
        $this->signInWithPermissions(['access cp', 'run bulk translations', 'access en site', 'access el site', 'edit articles entries']);

        storeRun(['options' => ['translator' => 'openai:gpt-custom']])->assertUnprocessable()->assertJsonValidationErrors('options.translator');
        storeRun(['options' => ['translator' => 'openai:gpt-6-luna']])->assertCreated();
    });

    it('counts the targets the user may not edit as unauthorized', function () {
        $this->signInWithPermissions(['access cp', 'run bulk translations', 'access en site', 'access el site', 'edit articles entries']);

        $run = createdRun(['sites' => ['el', 'de']]);

        expect(RunFixtures::items($run))->toHaveCount(3)
            ->and($run->scope['skipped'])->toBe(['unauthorized' => 3]);
    });
});

describe('estimate', function () {
    it('estimates the run to completion in steps, from the next index it returns', function () {
        $this->signInAdmin();
        $run = createdRun();
        $from = 0;
        $calls = 0;

        do {
            $progress = runAction($run, 'estimate', ['from' => $from])->assertOk()->json();
            $from = $progress['next'];
            $calls++;
        } while ($from !== null && $calls < 10);

        expect($from)->toBeNull()
            ->and($progress['segments'])->toBe(6)
            ->and($progress['chars'])->toBeGreaterThan(0)
            ->and($progress['input_tokens'])->toBeGreaterThan(0)
            ->and($progress['output_tokens'])->toBeGreaterThan(0)
            ->and($progress['cost'])->toBeGreaterThan(0)
            ->and(runState($run)->json('run.estimate'))->toBe($progress)
            ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::Pending);
        $this->assertNoModelCalls();
    });

    it('answers a retried step with the stored progress and adds nothing', function () {
        $this->signInAdmin();
        $run = createdRun();
        $finished = runAction($run, 'estimate', ['from' => 0])->json();

        expect(runAction($run, 'estimate', ['from' => 2])->json())->toBe($finished);
    });
});

describe('a browser run', function () {
    it('starts, and finishes a three-item run with alternating tick and apply calls', function () {
        Event::fake([RunFinished::class]);
        $this->fakeTranslations();
        $this->signInAdmin();
        $run = createdRun();

        $started = runAction($run, 'start')->assertOk();
        $ticked = tick($run)->assertOk();
        $applied = runAction($run, 'apply')->assertOk();

        expect($started->json('run.status'))->toBe('running')
            ->and($started->json('counts.pending'))->toBe(3)
            ->and($ticked->json('report'))->toBe(['units' => 3, 'items' => 3, 'remains' => false, 'wait' => null])
            ->and($ticked->json('counts.translated'))->toBe(3)
            ->and($applied->json('report'))->toBe(['items' => 3, 'returned' => 0, 'remains' => false, 'wait' => null])
            ->and($applied->json('run.status'))->toBe('completed')
            ->and($applied->json('counts.done'))->toBe(3)
            ->and($this->hello->fresh()->in('el')->get('title'))->toBe('[T] Hello')
            ->and($this->again->fresh()->in('el')->get('intro'))->toBe('[T] A third intro.');
        Event::assertDispatchedTimes(RunFinished::class, 1);
    });

    it('reports progress in its state: run, counts, a page of items, usage and cost', function () {
        $this->fakeTranslations();
        $this->signInAdmin();
        $run = createdRun();
        runAction($run, 'start');

        $before = runState($run)->assertOk()->json();
        driveRun($run);
        $after = runState($run)->assertOk()->json();
        $page = runState($run, ['page' => 2, 'per_page' => 2])->json();
        $done = runState($run, ['status' => 'done', 'per_page' => 1])->json();

        expect($before['counts'])->toMatchArray(['pending' => 3, 'done' => 0])
            ->and($before['run']['usage'])->toBe([])
            ->and($before['run']['cost'])->toEqual(0)
            ->and($after['run']['cost'])->not->toBeNull()
            ->and($before['stalled'])->toBeFalse()
            ->and($after['run']['status'])->toBe('completed')
            ->and($after['counts'])->toMatchArray(['pending' => 0, 'done' => 3])
            ->and($after['run']['usage'])->toHaveKey('anthropic:claude-sonnet-5-5')
            ->and($after['run'])->not->toHaveKeys(['generation', 'saves', 'committed_saves'])
            ->and(array_column($after['items'], 'status'))->toBe(['done', 'done', 'done'])
            ->and($after['items'][0])->toMatchArray(['index' => 0, 'title' => 'Hello', 'target_site' => 'el', 'fields' => ['title' => 'applied', 'intro' => 'applied']])
            ->and($after['items'][0])->not->toHaveKeys(['claim', 'plan_id'])
            ->and($after['items'][0]['edit_url'])->toBe($this->hello->fresh()->in('el')->editUrl())
            ->and($after['pagination'])->toBe(['page' => 1, 'per_page' => 50, 'total' => 3, 'last_page' => 1])
            ->and(array_column($page['items'], 'index'))->toBe([2])
            ->and($page['pagination'])->toBe(['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->and($done['pagination'])->toMatchArray(['total' => 3, 'last_page' => 3]);
    });

    it('builds the worker id from the user, the nonce and the lane', function () {
        // One batch per field, and the deadline passes during the first unit, so the item stays claimed.
        config(['tolk.batch.max_chars.web' => 10]);
        $this->fakeTranslations(function (string $text) {
            $this->travel(10)->seconds();

            return '[T] '.$text;
        });
        $admin = $this->signInAdmin();
        $run = createdRun();
        runAction($run, 'start');

        $report = tick($run, 2)->json('report');

        expect($report)->toMatchArray(['units' => 1, 'items' => 0, 'remains' => true])
            ->and(RunFixtures::items($run)[0]->claim->by)->toBe('browser-'.$admin->id().'-'.RUN_NONCE.'-2');
    });

    it('validates the nonce and the lane', function (array $body, string $field) {
        $this->signInAdmin();
        $run = createdRun();

        runAction($run, 'tick', [...['nonce' => RUN_NONCE, 'lane' => 1], ...$body])->assertUnprocessable()->assertJsonValidationErrors($field);
    })->with([
        'no nonce' => [['nonce' => null], 'nonce'],
        'a short nonce' => [['nonce' => 'abc'], 'nonce'],
        'a nonce with a dash' => [['nonce' => 'abcdefgh-1'], 'nonce'],
        'no lane' => [['lane' => null], 'lane'],
        'lane 0' => [['lane' => 0], 'lane'],
        'a lane beyond the browser lanes' => [['lane' => 3], 'lane'],
    ]);

    it('does not let a forged worker id take another worker\'s item', function () {
        $this->fakeTranslations();
        $admin = $this->signInAdmin();
        $run = createdRun();
        runAction($run, 'start');
        $owner = 'browser-'.$admin->id().'-'.RUN_NONCE.'-1';
        $held = $this->runs->claimNext($run, $owner, 300);

        $this->signInWithPermissions(['access cp', 'run bulk translations', 'access en site', 'access el site', 'edit articles entries']);
        tick($run, 1, RUN_NONCE, ['worker' => $owner, 'worker_id' => $owner])->assertOk();
        $items = RunFixtures::items($run);

        expect($items[0]->status)->toBe(RunItemStatus::Translating)
            ->and($items[0]->claim->by)->toBe($owner)
            ->and($items[0]->claim->token)->toBe($held->claim->token)
            ->and([$items[1]->status, $items[2]->status])->toBe([RunItemStatus::Translated, RunItemStatus::Translated])
            ->and($this->modelCalls())->toBe(2);
    });

    it('cancels: no more claims, and the content saved so far is committed', function () {
        Event::fake([RunFinished::class]);
        $this->fakeTranslations(function (string $text) {
            $this->travel(10)->seconds();

            return '[T] '.$text;
        });
        $this->signInAdmin();
        $commits = RunFixtures::commits();
        $run = createdRun();
        runAction($run, 'start');

        tick($run);
        runAction($run, 'apply');
        $cancelled = runAction($run, 'cancel')->assertOk();
        $after = tick($run)->assertOk();

        expect(RunFixtures::statuses($run))->toBe(['done', 'pending', 'pending'])
            ->and($cancelled->json('run.status'))->toBe('cancelled')
            ->and($cancelled->json('run.finished_at'))->not->toBeNull()
            ->and($after->json('report'))->toBe(['units' => 0, 'items' => 0, 'remains' => false, 'wait' => null])
            ->and($this->modelCalls())->toBe(1)
            ->and($commits->getArrayCopy())->toBe([ContentWriter::COMMIT_MESSAGE]);
        Event::assertDispatched(RunFinished::class, fn (RunFinished $event) => $event->status === RunStatus::Cancelled);
    });

    it('resumes a cancelled run and finishes it', function () {
        Event::fake([RunFinished::class]);
        $this->fakeTranslations();
        $this->signInAdmin();
        $run = createdRun();
        runAction($run, 'start');
        runAction($run, 'cancel');

        $resumed = runAction($run, 'resume')->assertOk();
        $finished = driveRun($run);

        expect($resumed->json('run'))->toMatchArray(['status' => 'running', 'finished_at' => null, 'finalized_at' => null])
            ->and($finished['run']['status'])->toBe('completed')
            ->and(RunFixtures::statuses($run))->toBe(['done', 'done', 'done']);
        Event::assertDispatchedTimes(RunFinished::class, 2);
    });

    it('resumes an aborted run and clears its error', function () {
        $this->signInAdmin();
        $run = createdRun();
        runAction($run, 'start');
        $run = RunFixtures::stored($run);
        $run->status = RunStatus::Aborted;
        $run->error = 'The provider refused the key.';
        $this->runs->save($run);

        expect(runAction($run, 'resume')->assertOk()->json('run'))->toMatchArray(['status' => 'running', 'error' => null]);
    });

    it('leaves a finished run as it is when it is cancelled', function () {
        $this->fakeTranslations();
        $this->signInAdmin();
        $run = createdRun();
        runAction($run, 'start');
        driveRun($run);

        expect(runAction($run, 'cancel')->assertOk()->json('run.status'))->toBe('completed');
    });

    it('does not resume a run that is pending or completed', function () {
        $this->fakeTranslations();
        $this->signInAdmin();
        $run = createdRun();

        runAction($run, 'resume')->assertStatus(409)->assertJson(['code' => 'not_resumable']);

        runAction($run, 'start');
        driveRun($run);

        runAction($run, 'resume')->assertStatus(409);
        expect(RunFixtures::stored($run)->status)->toBe(RunStatus::Completed);
    });

    it('retries a failed item: back to pending with attempts reset, and the run runs again', function () {
        Event::fake([RunFinished::class]);
        $this->fakeTranslations();
        $this->signInAdmin();
        $run = createdRun();
        runAction($run, 'start');
        failItem($run, 1);
        $item = $this->runs->item($run, 1);
        $item->attempts = 2;
        $item->reason = 'apply_failed';
        $this->runs->saveItem($run, $item);
        driveRun($run);
        expect(RunFixtures::stored($run)->status)->toBe(RunStatus::CompletedWithErrors);

        $retried = runAction($run, 'retry')->assertOk();
        $item = $this->runs->item($run, 1);

        expect($retried->json('retried'))->toBe(1)
            ->and($retried->json('run'))->toMatchArray(['status' => 'running', 'error' => null, 'finished_at' => null, 'finalized_at' => null])
            ->and($item)->toMatchObject(['status' => RunItemStatus::Pending, 'attempts' => 0, 'waits' => 0, 'reason' => null, 'error' => null, 'availableAt' => null]);

        $finished = driveRun($run);

        expect($finished['run']['status'])->toBe('completed')
            ->and(RunFixtures::statuses($run))->toBe(['done', 'done', 'done']);
        Event::assertDispatchedTimes(RunFinished::class, 2);
    });

    it('retries nothing when nothing failed and leaves the run as it is', function () {
        $this->fakeTranslations();
        $this->signInAdmin();
        $run = createdRun();
        runAction($run, 'start');
        driveRun($run);

        $retried = runAction($run, 'retry')->assertOk();

        expect($retried->json('retried'))->toBe(0)
            ->and($retried->json('run.status'))->toBe('completed');
    });

    it('answers 404 for a run that does not exist', function () {
        $this->signInAdmin();
        $run = createdRun();
        $this->runs->delete($run);

        runState($run)->assertNotFound()->assertJson(['code' => 'run_not_found']);
        tick($run)->assertNotFound();
        runAction($run, 'start')->assertNotFound();
        runAction($run, 'retry')->assertNotFound();
        $this->getJson(cp_route('tolk.runs.show', $run->id))->assertNotFound();
    });
});

describe('a queue run', function () {
    beforeEach(function () {
        config(['tolk.runs.execution' => 'queue', 'tolk.concurrency.queue' => 2]);
        Queue::fake();
    });

    it('pushes its lanes on start and is not ticked or applied by a browser', function () {
        $this->fakeTranslations();
        $this->signInAdmin();
        $run = createdRun();

        runAction($run, 'start')->assertOk();
        $ticked = tick($run)->assertOk();
        $applied = runAction($run, 'apply')->assertOk();

        Queue::assertPushed(WorkRun::class, 2);
        expect($ticked->json('report.remains'))->toBeFalse()
            ->and($applied->json('report.remains'))->toBeFalse()
            ->and($ticked->json('counts.pending'))->toBe(3);
        $this->assertNoModelCalls();
    });

    it('retries a failed item and pushes the lanes of a new generation', function () {
        $this->signInAdmin();
        $run = createdRun();
        runAction($run, 'start');
        failItem($run, 0);

        $retried = runAction($run, 'retry')->assertOk();

        expect($retried->json('retried'))->toBe(1)
            ->and($this->runs->item($run, 0)->status)->toBe(RunItemStatus::Pending)
            ->and(array_map(fn (WorkRun $job) => [$job->lane, $job->generation], Queue::pushed(WorkRun::class)->values()->all()))
            ->toBe([[1, 1], [2, 1], [1, 2], [2, 2]]);
    });

    it('is stalled when no worker claimed an item within 30 seconds of the start, or made progress within the claim TTL', function () {
        $this->signInAdmin();
        $run = createdRun();
        runAction($run, 'start');

        expect(runState($run)->json('stalled'))->toBeFalse();

        $this->travel(31)->seconds();
        expect(runState($run)->json('stalled'))->toBeTrue();

        $this->runs->claimNext($run, 'queue-1', 300);
        expect(runState($run)->json('stalled'))->toBeFalse();

        $this->travel(config('tolk.runs.claim_ttl') + 1)->seconds();
        expect(runState($run)->json('stalled'))->toBeTrue();
    });

    it('is taken over by the browser with a new generation, and the browser finishes it', function () {
        $this->fakeTranslations();
        $this->signInAdmin();
        $run = createdRun();
        runAction($run, 'start');

        $taken = runAction($run, 'take-over')->assertOk();
        $finished = driveRun($run);

        expect($taken->json('run.driver'))->toBe('browser')
            ->and($taken->json('stalled'))->toBeFalse()
            ->and(RunFixtures::stored($run)->generation)->toBe(2)
            ->and($finished['run']['status'])->toBe('completed');
    });

    it('resumes a stalled run by pushing new lanes', function () {
        $this->signInAdmin();
        $run = createdRun();
        runAction($run, 'start');

        runAction($run, 'resume')->assertOk();

        Queue::assertPushed(WorkRun::class, fn (WorkRun $job) => $job->generation === 2);
        Queue::assertPushed(WorkRun::class, 4);
    });
});

describe('authorization', function () {
    it('lets the creator with translate content follow and drive the run, but not manage runs', function () {
        $this->fakeTranslations();
        $creator = greekTranslator();
        $run = RunFixtures::pending(['user' => $creator]);
        app(RunProcessor::class)->start($run);

        runState($run)->assertOk();
        tick($run)->assertOk();
        runAction($run, 'apply')->assertOk()->assertJsonPath('counts.done', 3);
        runAction($run, 'cancel')->assertOk();

        runAction($run, 'start')->assertForbidden();
        runAction($run, 'retry')->assertForbidden();
        runAction($run, 'estimate', ['from' => 0])->assertForbidden();
        $this->getJson(cp_route('tolk.runs.index'))->assertForbidden();
        $this->getJson(cp_route('tolk.runs.show', $run->id))->assertForbidden();
    });

    it('forbids a user who is neither the creator nor allowed to run bulk translations', function () {
        $this->fakeTranslations();
        $this->signInAdmin();
        $run = createdRun();
        runAction($run, 'start');

        greekTranslator();

        tick($run)->assertForbidden();
        runState($run)->assertForbidden();
        runAction($run, 'apply')->assertForbidden();
        runAction($run, 'cancel')->assertForbidden();
        expect(RunFixtures::statuses($run))->toBe(['pending', 'pending', 'pending']);
        $this->assertNoModelCalls();
    });

    it('forbids the creator who lost translate content', function () {
        $creator = greekTranslator();
        $run = RunFixtures::pending(['user' => $creator]);

        $this->setTestRoles(['tolk_test' => ['access cp']]);

        runState($run)->assertForbidden();
    });
});

describe('pages', function () {
    it('renders the runs index with the recent runs', function () {
        $this->signInAdmin();
        $run = createdRun();

        $this->get(cp_route('tolk.runs.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('tolk::Runs/Index')
                ->has('runs', 1)
                ->where('runs.0.id', $run->id)
                ->where('runs.0.counts.pending', 3)
                ->where('runs.0.url', cp_route('tolk.runs.show', $run->id)));
    });

    it('renders a run\'s page with its state, URLs and model labels', function () {
        $this->signInAdmin();
        $this->withSettings(['translator_model' => 'openai:gpt-6.1-sol']);
        $run = createdRun(['sites' => ['el', 'de'], 'options' => ['review' => true, 'reviewer' => 'gemini:gemini-3.8-flash']]);

        $this->get(cp_route('tolk.runs.show', $run->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('tolk::Runs/Show')
                ->where('state.run.id', $run->id)
                ->where('state.run.status', 'pending')
                ->where('state.run.estimate', null)
                ->where('state.counts.pending', 6)
                ->where('state.breakdown', [
                    ['type' => 'entry', 'site' => 'el', 'counts' => runCounts(['pending' => 3])],
                    ['type' => 'entry', 'site' => 'de', 'counts' => runCounts(['pending' => 3])],
                ])
                ->has('state.items', 6)
                ->where('state.pagination.total', 6)
                ->where('urls.tick', cp_route('tolk.runs.tick', $run->id))
                ->where('urls.take_over', cp_route('tolk.runs.take-over', $run->id))
                ->where('models', ['openai:gpt-6.1-sol' => 'GPT-6.1 Sol', 'gemini:gemini-3.8-flash' => 'Gemini 3.8 Flash'])
                ->where('lanes', 2));
    });

    it('passes the text fields left out of scope to the run\'s page', function () {
        $this->signInAdmin();
        $this->makeCollection('notes', ['en', 'el'], [
            'title' => ['type' => 'text', 'localizable' => true],
            'internal_note' => ['type' => 'textarea', 'display' => 'Internal note'],
        ]);
        $this->makeEntry('notes', 'en', ['title' => 'One'], 'one');
        $run = createdRun(['collections' => ['notes']]);

        $this->get(cp_route('tolk.runs.show', $run->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('tolk::Runs/Show')
                ->where('state.run.scope.out_of_scope', [
                    ['container' => 'notes', 'handle' => 'internal_note', 'label' => 'Internal note', 'type' => 'textarea', 'reason' => 'not_localizable'],
                ]));
    });

    it('refuses both pages to a user who may not run bulk translations', function () {
        $this->signInAdmin();
        $run = createdRun();
        greekTranslator();

        $this->getJson(cp_route('tolk.runs.index'))->assertForbidden();
        $this->getJson(cp_route('tolk.runs.show', $run->id))->assertForbidden();
    });
});
