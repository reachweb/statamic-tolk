<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ProviderError;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\Finish;
use Reach\StatamicTolk\Enums\ProviderErrorKind;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Enums\UnitKind;
use Reach\StatamicTolk\Events\RunStarted;
use Reach\StatamicTolk\Exceptions\ProviderPaused;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\Exceptions\ProviderUnavailable;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunItem;
use Reach\StatamicTolk\Runs\RunPlanner;
use Reach\StatamicTolk\Runs\RunProcessor;
use Reach\StatamicTolk\Runs\RunRequest;
use Reach\StatamicTolk\Runs\UsageLog;
use Reach\StatamicTolk\Runs\WorkReport;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Tests\Support\ScriptedEngine;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\PlanStore;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Facades\Entry;

beforeEach(function () {
    $this->useFileCache();
    $this->freezeSecond();

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
 * A stored and started run; without entries or collections, the articles into Greek, three items.
 */
function startedRun(array $request = []): Run
{
    $scope = isset($request['entryIds']) || isset($request['collections']) ? [] : ['collections' => ['articles']];
    $planned = app(RunPlanner::class)->build(new RunRequest(...['sites' => ['el'], 'driver' => RunDriver::Browser, ...$scope, ...$request]));
    $run = app(RunRepository::class)->create($planned->run, $planned->items);

    app(RunProcessor::class)->start($run);

    return $run;
}

function translateRun(Run $run, string $worker = 'lane-1', int $seconds = 30, int $ttl = 300): WorkReport
{
    return app(RunProcessor::class)->translate($run, $worker, CarbonImmutable::now()->addSeconds($seconds), $ttl);
}

/**
 * @return list<RunItem>
 */
function runItems(Run $run): array
{
    return app(RunRepository::class)->items($run, perPage: 1000);
}

/**
 * @return list<string>
 */
function runItemStatuses(Run $run): array
{
    return array_map(fn (RunItem $item) => $item->status->value, runItems($run));
}

function reloadedRun(Run $run): Run
{
    return app(RunRepository::class)->find($run->id);
}

function rejectedKey(EngineCall $call): never
{
    throw ProviderRejected::from($call->model, new ProviderError(ProviderErrorKind::Rejected, 'Invalid API key sk-ant-abcdefghijklmnopqrstuvwxyz.', 401));
}

describe('start', function () {
    it('sets a pending run running from now and fires RunStarted once', function () {
        Event::fake([RunStarted::class]);
        $planned = app(RunPlanner::class)->build(new RunRequest(sites: ['el'], driver: RunDriver::Cli, collections: ['articles']));
        $run = $this->runs->create($planned->run, $planned->items);
        $this->travel(5)->seconds();

        app(RunProcessor::class)->start($run);

        expect($run->status)->toBe(RunStatus::Running)
            ->and(reloadedRun($run)->status)->toBe(RunStatus::Running)
            ->and(reloadedRun($run)->progressedAt)->toEqual(CarbonImmutable::now())
            ->and(reloadedRun($run)->startedAt)->toBeNull();
        Event::assertDispatched(RunStarted::class, fn (RunStarted $event) => $event->runId === $run->id
            && $event->driver === RunDriver::Cli
            && $event->createdBy === null
            && $event->counts['pending'] === 3);

        $this->travel(5)->seconds();
        app(RunProcessor::class)->start($run);

        expect(reloadedRun($run)->progressedAt)->toEqual(CarbonImmutable::now());
        Event::assertDispatchedTimes(RunStarted::class, 1);
    });

    it('leaves a finished run as it is', function () {
        Event::fake([RunStarted::class]);
        $planned = app(RunPlanner::class)->build(new RunRequest(sites: ['el'], driver: RunDriver::Browser, collections: ['articles']));
        $run = $this->runs->create($planned->run, $planned->items);
        $run->status = RunStatus::Cancelled;
        $this->runs->save($run);

        app(RunProcessor::class)->start($run);

        expect(reloadedRun($run)->status)->toBe(RunStatus::Cancelled)
            ->and(reloadedRun($run)->progressedAt)->toBeNull();
        Event::assertNotDispatched(RunStarted::class);
    });

    it('claims nothing of a run that was not started', function () {
        $this->fakeTranslations();
        $planned = app(RunPlanner::class)->build(new RunRequest(sites: ['el'], driver: RunDriver::Browser, collections: ['articles']));
        $run = $this->runs->create($planned->run, $planned->items);

        expect(translateRun($run)->toArray())->toBe(['units' => 0, 'items' => 0, 'remains' => false, 'wait' => null])
            ->and(runItemStatuses($run))->toBe(['pending', 'pending', 'pending']);
        $this->assertNoModelCalls();
    });
});

describe('translate', function () {
    it('translates every item of a three-item run and saves no content', function () {
        $this->fakeTranslations();
        $before = Entry::all()->map(fn ($entry) => [$entry->id(), $entry->locale(), $entry->data()->all()])->all();
        $run = startedRun();

        $report = translateRun($run);

        expect($report->toArray())->toBe(['units' => 3, 'items' => 3, 'remains' => false, 'wait' => null])
            ->and(runItemStatuses($run))->toBe(['translated', 'translated', 'translated'])
            ->and($this->modelCalls())->toBe(3)
            ->and(Entry::all()->map(fn ($entry) => [$entry->id(), $entry->locale(), $entry->data()->all()])->all())->toBe($before)
            ->and(Entry::query()->where('site', 'el')->count())->toBe(0)
            ->and(reloadedRun($run)->status)->toBe(RunStatus::Running)
            ->and(reloadedRun($run)->startedAt)->toEqual(CarbonImmutable::now());

        foreach (runItems($run) as $item) {
            $plan = app(PlanStore::class)->get($item->planId);

            expect($plan->kind)->toBe(UnitKind::Translate)
                ->and($plan->target)->toBe(['type' => 'entry', 'source_id' => $item->sourceId, 'site' => 'el'])
                ->and($item->claim)->toBeNull()
                ->and($item->usage->isEmpty())->toBeFalse();
        }
    });

    it('records each call in the month\'s log as run usage, on the run and on its item', function () {
        $engine = ScriptedEngine::bind(fn (EngineCall $call) => ScriptedEngine::translated($call));
        $run = startedRun(['entryIds' => [$this->hello->id()]]);

        translateRun($run);

        $call = new Usage(input: 10, output: 5, calls: 1);
        $model = (string) $engine->calls[0]->model;

        expect(reloadedRun($run)->usage->toArray())->toBe([$model => $call->toArray()])
            ->and(runItems($run)[0]->usage->toArray())->toBe([$model => $call->toArray()])
            ->and(app(UsageLog::class)->month()[$model]['sources'])->toHaveKey('run')
            ->and(app(UsageLog::class)->month()[$model]['sources'])->not->toHaveKey('cli');
    });

    it('plans a run item without the editor\'s split, with the profile of its driver', function (RunDriver $driver, string $profile) {
        config(['tolk.batch.min_chars' => 1, 'tolk.concurrency.editor' => 4]);
        $this->fakeTranslations();
        $run = startedRun(['entryIds' => [$this->hello->id()], 'driver' => $driver]);

        translateRun($run);

        $plan = app(PlanStore::class)->get(runItems($run)[0]->planId);

        expect($plan->batches)->toHaveCount(1)
            ->and($plan->options->profile)->toBe($profile)
            ->and($plan->options->parallel)->toBeFalse()
            ->and($this->modelCalls())->toBe(1);
    })->with([
        'browser' => [RunDriver::Browser, 'web'],
        'queue' => [RunDriver::Queue, 'web'],
        'cli' => [RunDriver::Cli, 'background'],
    ]);

    it('stops at the deadline with the item translating and its plan stored, and goes on from there', function () {
        config(['tolk.batch.max_chars.web' => 10]);
        $engine = ScriptedEngine::bind(function (EngineCall $call, int $number) {
            if ($number === 1) {
                $this->travel(10)->seconds();
            }

            return ScriptedEngine::translated($call);
        });
        $run = startedRun(['entryIds' => [$this->hello->id()]]);

        $first = translateRun($run, seconds: 5);
        $item = runItems($run)[0];

        expect($first->toArray())->toBe(['units' => 1, 'items' => 0, 'remains' => true, 'wait' => 0])
            ->and($item->status)->toBe(RunItemStatus::Translating)
            ->and($item->claim->by)->toBe('lane-1')
            ->and(app(PlanStore::class)->get($item->planId)->batches)->toHaveCount(2)
            ->and($item->usage->isEmpty())->toBeFalse()
            ->and($engine->segmentIds())->toBe([['s1']]);

        $second = translateRun($run, seconds: 5);

        expect($second->toArray())->toBe(['units' => 1, 'items' => 1, 'remains' => false, 'wait' => null])
            ->and(runItems($run)[0]->status)->toBe(RunItemStatus::Translated)
            ->and(runItems($run)[0]->planId)->toBe($item->planId)
            ->and($engine->segmentIds())->toBe([['s1'], ['s2']]);
    });

    it('starts no unit once the deadline has passed', function () {
        $this->fakeTranslations();
        $run = startedRun();

        expect(translateRun($run, seconds: 0)->toArray())->toBe(['units' => 0, 'items' => 0, 'remains' => true, 'wait' => 0])
            ->and(runItemStatuses($run))->toBe(['pending', 'pending', 'pending']);
        $this->assertNoModelCalls();
    });

    it('plans again when the item\'s plan expired from the cache', function () {
        config(['tolk.batch.max_chars.web' => 10]);
        $engine = ScriptedEngine::bind(function (EngineCall $call, int $number) {
            if ($number === 1) {
                $this->travel(10)->seconds();
            }

            return ScriptedEngine::translated($call);
        });
        $run = startedRun(['entryIds' => [$this->hello->id()]]);
        translateRun($run, seconds: 5);
        $expired = runItems($run)[0]->planId;
        app(PlanStore::class)->forget(app(PlanStore::class)->get($expired));

        translateRun($run);

        expect(runItems($run)[0]->status)->toBe(RunItemStatus::Translated)
            ->and(runItems($run)[0]->planId)->not->toBe($expired)
            ->and($engine->segmentIds())->toBe([['s1'], ['s1'], ['s2']]);
    });

    it('skips an item with nothing to do and completes a run whose last item finished', function () {
        $this->fakeTranslations();
        $options = PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['profile' => 'background']);
        app(TargetTranslator::class)->translate(app(TargetFactory::class)->forEntry($this->hello, 'el'), $options, new ApplyOptions);
        $calls = $this->modelCalls();
        $run = startedRun(['entryIds' => [$this->hello->id()]]);

        $report = translateRun($run);
        $item = runItems($run)[0];

        expect($report->toArray())->toBe(['units' => 0, 'items' => 1, 'remains' => false, 'wait' => null])
            ->and($item)->toMatchObject(['status' => RunItemStatus::Skipped, 'reason' => 'nothing_to_do', 'planId' => null, 'fields' => ['title' => 'current', 'intro' => 'current']])
            ->and(reloadedRun($run)->status)->toBe(RunStatus::Completed)
            ->and(reloadedRun($run)->finishedAt)->toEqual(CarbonImmutable::now())
            ->and($this->modelCalls())->toBe($calls);
    });

    it('confirms the target\'s pending state before planning it', function () {
        $this->fakeTranslations();
        $localization = $this->localize($this->hello, 'el', ['title' => '[T] Hello', 'intro' => '[T] A short intro.']);
        StateFixtures::store($localization, [
            'title' => new FieldState(pending: [StateFixtures::pending('Hello', '[T] Hello')]),
            'intro' => new FieldState(pending: [StateFixtures::pending('A short intro.', '[T] A short intro.')]),
        ]);
        $run = startedRun(['entryIds' => [$this->hello->id()]]);

        translateRun($run);

        $state = app(StateRepository::class)->get(StateFixtures::ref($localization));

        expect(runItems($run)[0])->toMatchObject(['status' => RunItemStatus::Skipped, 'reason' => 'nothing_to_do'])
            ->and($state->hasPending())->toBeFalse()
            ->and($state->field('title')->targetHash)->toBe(Fingerprint::ofLeaves(['[T] Hello']));
        $this->assertNoModelCalls();
    });

    it('extends the claim before every unit', function () {
        config(['tolk.batch.max_chars.web' => 10]);
        $expiries = [];
        ScriptedEngine::bind(function (EngineCall $call) use (&$run, &$expiries) {
            $expiries[] = (int) CarbonImmutable::now()->diffInSeconds(runItems($run)[0]->claim->expiresAt);
            $this->travel(100)->seconds();

            return ScriptedEngine::translated($call);
        });
        $run = startedRun(['entryIds' => [$this->hello->id()]]);

        translateRun($run, seconds: 1000, ttl: 150);

        expect($expiries)->toBe([150, 150])
            ->and(runItems($run)[0]->status)->toBe(RunItemStatus::Translated);
    });

    it('fails an item whose source no longer exists', function () {
        $this->fakeTranslations();
        $run = startedRun(['entryIds' => [$this->hello->id(), $this->world->id()]]);
        $this->hello->delete();

        $report = translateRun($run);

        expect($report->items)->toBe(2)
            ->and(runItems($run)[0])->toMatchObject(['status' => RunItemStatus::Failed, 'reason' => null, 'error' => "Entry [{$this->hello->id()}] not found."])
            ->and(runItems($run)[1]->status)->toBe(RunItemStatus::Translated)
            ->and($this->modelCalls())->toBe(1);
    });

    it('fails a page whose root page was not translated, without a model call', function () {
        $this->fakeTranslations();
        $pages = $this->makePages(['home', 'dining']);
        $run = startedRun(['collections' => ['pages']]);
        // The root's item finished without creating the root page.
        $root = runItems($run)[0];
        $root->status = RunItemStatus::Done;
        $this->runs->saveItem($run, $root);

        translateRun($run);

        expect(runItems($run)[1])->toMatchObject(['status' => RunItemStatus::Failed, 'reason' => 'root_missing', 'error' => 'Translate Home into Greek first.', 'planId' => null])
            ->and($pages['dining']->fresh()->existsIn('el'))->toBeFalse()
            ->and(reloadedRun($run)->status)->toBe(RunStatus::CompletedWithErrors);
        $this->assertNoModelCalls();
    });

    it('reviews the existing translations in mode review', function () {
        $engine = ScriptedEngine::bind(
            fn (EngineCall $call) => ScriptedEngine::translated($call),
            fn (EngineCall $call) => ScriptedEngine::result($call, Finish::Stop),
        );
        $options = PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['profile' => 'background']);
        app(TargetTranslator::class)->translate(app(TargetFactory::class)->forEntry($this->hello, 'el'), $options, new ApplyOptions);
        $run = startedRun(['entryIds' => [$this->hello->id()], 'options' => ['mode' => 'review']]);

        translateRun($run);

        expect(runItems($run)[0]->status)->toBe(RunItemStatus::Translated)
            ->and(app(PlanStore::class)->get(runItems($run)[0]->planId)->kind)->toBe(UnitKind::Review)
            ->and($engine->calls)->toHaveCount(1)
            ->and($engine->reviews)->toHaveCount(1);
    });

    it('releases the item when the run is cancelled between two units', function () {
        config(['tolk.batch.max_chars.web' => 10]);
        $engine = ScriptedEngine::bind(function (EngineCall $call, int $number) use (&$run) {
            if ($number === 1) {
                $other = reloadedRun($run);
                $other->status = RunStatus::Cancelled;
                app(RunRepository::class)->save($other);
            }

            return ScriptedEngine::translated($call);
        });
        $run = startedRun(['entryIds' => [$this->hello->id()]]);

        $report = translateRun($run);
        $item = runItems($run)[0];

        expect($report->toArray())->toBe(['units' => 1, 'items' => 0, 'remains' => false, 'wait' => null])
            ->and($item)->toMatchObject(['status' => RunItemStatus::Pending, 'claim' => null])
            ->and($item->planId)->not->toBeNull()
            ->and($run->status)->toBe(RunStatus::Cancelled)
            ->and($engine->segmentIds())->toBe([['s1']]);
    });
});

describe('failures', function () {
    it('releases the item after a rate limit with a future available_at, reports the wait and stops', function () {
        $engine = ScriptedEngine::bind(fn (EngineCall $call, int $number) => $number === 1
            ? throw ProviderPaused::for('anthropic', 7)
            : ScriptedEngine::translated($call));
        $run = startedRun(['entryIds' => [$this->hello->id(), $this->world->id()]]);

        $report = translateRun($run);
        [$first, $second] = runItems($run);

        expect($report->toArray())->toBe(['units' => 0, 'items' => 0, 'remains' => true, 'wait' => 7])
            ->and($first)->toMatchObject(['status' => RunItemStatus::Pending, 'claim' => null, 'waits' => 1, 'attempts' => 0])
            ->and($first->availableAt)->toEqual(CarbonImmutable::now()->addSeconds(7))
            ->and($second)->toMatchObject(['status' => RunItemStatus::Pending, 'claim' => null])
            ->and($engine->calls)->toHaveCount(1);

        $this->travel(7)->seconds();
        translateRun($run);

        expect(runItemStatuses($run))->toBe(['translated', 'translated'])
            ->and(runItems($run)[0]->waits)->toBe(0)
            ->and($engine->calls)->toHaveCount(3);
    });

    it('fails an item after 10 waits in a row and aborts the run after three such items in a row', function () {
        $engine = ScriptedEngine::bind(fn (EngineCall $call) => throw ProviderUnavailable::connection('anthropic', 3));
        $run = startedRun(['sites' => ['el', 'de']]);
        $message = 'The AI provider [anthropic] was still unavailable after 10 waits.';

        for ($call = 1; $call <= 10; $call++) {
            expect(translateRun($run)->toArray())->toBe(['units' => 0, 'items' => 0, 'remains' => true, 'wait' => 3]);
            $this->travel(3)->seconds();
        }

        expect(runItems($run)[0])->toMatchObject(['status' => RunItemStatus::Pending, 'waits' => 10]);

        expect(translateRun($run)->toArray())->toBe(['units' => 0, 'items' => 1, 'remains' => true, 'wait' => 3])
            ->and(runItems($run)[0])->toMatchObject(['status' => RunItemStatus::Failed, 'reason' => 'provider_unavailable', 'error' => $message])
            ->and($engine->calls)->toHaveCount(11);

        for ($call = 1; $call <= 22 && reloadedRun($run)->status === RunStatus::Running; $call++) {
            $this->travel(3)->seconds();
            translateRun($run);
        }

        expect(runItemStatuses($run))->toBe(['failed', 'failed', 'failed', 'pending', 'pending', 'pending'])
            ->and(reloadedRun($run))->toMatchObject(['status' => RunStatus::Aborted, 'error' => $message])
            ->and(reloadedRun($run)->finishedAt)->toEqual(CarbonImmutable::now())
            ->and($engine->calls)->toHaveCount(33)
            ->and(translateRun($run)->toArray())->toBe(['units' => 0, 'items' => 0, 'remains' => false, 'wait' => null])
            ->and($engine->calls)->toHaveCount(33);

        // Resumed, the run counts the items in a row from zero again.
        $resumed = reloadedRun($run);
        $resumed->status = RunStatus::Running;
        $resumed->error = null;
        app(RunRepository::class)->save($resumed);
        app(RunProcessor::class)->start($resumed);

        for ($call = 1; $call <= 11; $call++) {
            $this->travel(3)->seconds();
            translateRun($resumed);
        }

        expect(runItemStatuses($run))->toBe(['failed', 'failed', 'failed', 'failed', 'pending', 'pending'])
            ->and(reloadedRun($run))->toMatchObject(['status' => RunStatus::Running, 'error' => null])
            ->and($engine->calls)->toHaveCount(44);
    });

    it('does not abort when items failed for an unavailable provider are not in a row', function () {
        $engine = ScriptedEngine::bind(fn (EngineCall $call) => $call->target->site === 'de'
            ? throw ProviderUnavailable::connection('anthropic', 3)
            : ScriptedEngine::translated($call));
        $run = startedRun(['sites' => ['el', 'de']]);

        for ($call = 1; $call <= 40 && in_array('pending', runItemStatuses($run), true); $call++) {
            translateRun($run);
            $this->travel(3)->seconds();
        }

        expect(runItemStatuses($run))->toBe(['translated', 'failed', 'translated', 'failed', 'translated', 'failed'])
            ->and(reloadedRun($run)->status)->toBe(RunStatus::Running)
            ->and(reloadedRun($run)->error)->toBeNull();
    });

    it('aborts the run at a rejected key with the sanitised message and releases the item', function () {
        $engine = ScriptedEngine::bind(fn (EngineCall $call) => rejectedKey($call));
        $run = startedRun(['entryIds' => [$this->hello->id(), $this->world->id()]]);

        $report = translateRun($run);
        $stored = reloadedRun($run);

        expect($report->toArray())->toBe(['units' => 0, 'items' => 0, 'remains' => false, 'wait' => null])
            ->and($stored->status)->toBe(RunStatus::Aborted)
            ->and($stored->error)->toStartWith('The AI provider [anthropic] rejected the request')
            ->and($stored->error)->toContain('[redacted]')
            ->and($stored->error)->not->toContain('abcdefghijklmnopqrstuvwxyz')
            ->and($stored->finishedAt)->toEqual(CarbonImmutable::now())
            ->and(runItems($run)[0])->toMatchObject(['status' => RunItemStatus::Pending, 'claim' => null, 'attempts' => 0])
            ->and(runItemStatuses($run))->toBe(['pending', 'pending']);

        translateRun($run);

        expect($engine->calls)->toHaveCount(1);
    });

    it('releases an item that throws with a growing delay, reports it, and fails it at the third attempt', function () {
        Exceptions::fake();
        $engine = ScriptedEngine::bind(fn (EngineCall $call) => throw new RuntimeException('Something broke at sk-abcdefghijklmnopqrstuvwxyz.'));
        $run = startedRun(['entryIds' => [$this->hello->id()]]);

        expect(translateRun($run)->toArray())->toBe(['units' => 0, 'items' => 0, 'remains' => true, 'wait' => 5])
            ->and(runItems($run)[0])->toMatchObject(['status' => RunItemStatus::Pending, 'attempts' => 1, 'claim' => null])
            ->and(runItems($run)[0]->availableAt)->toEqual(CarbonImmutable::now()->addSeconds(5));

        $this->travel(5)->seconds();

        expect(translateRun($run)->wait)->toBe(10)
            ->and(runItems($run)[0]->attempts)->toBe(2);

        $this->travel(10)->seconds();
        $report = translateRun($run);

        expect($report->toArray())->toBe(['units' => 0, 'items' => 1, 'remains' => false, 'wait' => null])
            ->and(runItems($run)[0])->toMatchObject(['status' => RunItemStatus::Failed, 'attempts' => 3, 'reason' => null, 'error' => 'Something broke at [redacted].'])
            ->and(reloadedRun($run)->status)->toBe(RunStatus::CompletedWithErrors)
            ->and($engine->calls)->toHaveCount(3);
        Exceptions::assertReportedCount(3);
        Exceptions::assertReported(fn (RuntimeException $exception) => str_starts_with($exception->getMessage(), 'Something broke'));
    });

    it('skips an item whose lock another request holds, keeping the claim it got', function () {
        $this->fakeTranslations();
        $run = startedRun(['entryIds' => [$this->hello->id()]]);
        $lock = cache()->lock("tolk:item:{$run->id}:0", 300);
        $lock->get();

        $report = translateRun($run);

        expect($report->toArray())->toBe(['units' => 0, 'items' => 0, 'remains' => true, 'wait' => 1])
            ->and(runItems($run)[0])->toMatchObject(['status' => RunItemStatus::Translating, 'planId' => null])
            ->and(runItems($run)[0]->claim->by)->toBe('lane-1');
        $this->assertNoModelCalls();

        $lock->release();

        expect(translateRun($run)->items)->toBe(1)
            ->and(runItems($run)[0]->status)->toBe(RunItemStatus::Translated);
    });

    it('makes one model call per unit when two calls with the same worker id overlap', function () {
        config(['tolk.batch.max_chars.web' => 10]);
        $overlapping = null;
        $engine = ScriptedEngine::bind(function (EngineCall $call, int $number) use (&$run, &$overlapping) {
            if ($number === 1) {
                $overlapping = translateRun(reloadedRun($run));
            }

            return ScriptedEngine::translated($call);
        });
        $run = startedRun(['entryIds' => [$this->hello->id()]]);

        $first = translateRun($run);

        expect($overlapping->toArray())->toBe(['units' => 0, 'items' => 0, 'remains' => true, 'wait' => 1])
            ->and($first->toArray())->toBe(['units' => 1, 'items' => 0, 'remains' => true, 'wait' => 1])
            ->and(runItems($run)[0]->status)->toBe(RunItemStatus::Translating);

        $third = translateRun($run);

        expect($third->toArray())->toBe(['units' => 1, 'items' => 1, 'remains' => false, 'wait' => null])
            ->and(runItems($run)[0]->status)->toBe(RunItemStatus::Translated)
            ->and($engine->segmentIds())->toBe([['s1'], ['s2']]);
    });
});
