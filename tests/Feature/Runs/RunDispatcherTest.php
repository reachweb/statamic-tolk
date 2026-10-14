<?php

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunItemStatus;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Exceptions\ProviderPaused;
use Reach\StatamicTolk\Exceptions\ProviderUnavailable;
use Reach\StatamicTolk\Jobs\WorkRun;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunDispatcher;
use Reach\StatamicTolk\Runs\RunProcessor;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Reach\StatamicTolk\Tests\Support\ScriptedEngine;
use Statamic\Events\EntrySaving;
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
    $this->dispatcher = app(RunDispatcher::class);
});

function queueRun(array $request = []): Run
{
    return RunFixtures::pending(['driver' => RunDriver::Queue, ...$request]);
}

function workLane(WorkRun $job): void
{
    app()->call([$job, 'handle']);
}

/**
 * @return list<WorkRun>
 */
function pushedLanes(): array
{
    return Queue::pushed(WorkRun::class)->values()->all();
}

describe('resolveDriver', function () {
    it('runs on the queue when its connection has a worker, and in the browser otherwise', function (string $driver, RunDriver $expected) {
        config(['queue.default' => 'tolk', 'queue.connections.tolk' => ['driver' => $driver], 'tolk.runs.execution' => 'auto']);

        expect(RunDispatcher::resolveDriver())->toBe($expected);
    })->with([
        'sync' => ['sync', RunDriver::Browser],
        'database' => ['database', RunDriver::Queue],
        'redis' => ['redis', RunDriver::Queue],
        'deferred' => ['deferred', RunDriver::Browser],
        'background' => ['background', RunDriver::Browser],
        'null' => ['null', RunDriver::Browser],
    ]);

    it('takes the explicit settings as given', function (string $execution, string $default, RunDriver $expected) {
        config(['tolk.runs.execution' => $execution, 'queue.default' => $default]);

        expect(RunDispatcher::resolveDriver())->toBe($expected);
    })->with([
        'queue on sync' => ['queue', 'sync', RunDriver::Queue],
        'queue on database' => ['queue', 'database', RunDriver::Queue],
        'browser on sync' => ['browser', 'sync', RunDriver::Browser],
        'browser on database' => ['browser', 'database', RunDriver::Browser],
    ]);

    it('looks at the runs connection rather than the default one', function () {
        config(['tolk.runs.execution' => 'auto', 'queue.default' => 'sync', 'tolk.runs.queue.connection' => 'database']);

        expect(RunDispatcher::resolveDriver())->toBe(RunDriver::Queue);

        config(['queue.default' => 'database', 'tolk.runs.queue.connection' => 'sync']);

        expect(RunDispatcher::resolveDriver())->toBe(RunDriver::Browser);
    });

    it('follows a failover connection to its first connection', function (array $connections, RunDriver $expected) {
        config(['tolk.runs.execution' => 'auto', 'queue.default' => 'failover', 'queue.connections.failover.connections' => $connections]);

        expect(RunDispatcher::resolveDriver())->toBe($expected);
    })->with([
        'database first' => [['database', 'deferred'], RunDriver::Queue],
        'deferred first' => [['deferred', 'database'], RunDriver::Browser],
    ]);

    it('runs in the browser when the connection is not configured', function () {
        config(['tolk.runs.execution' => 'auto', 'tolk.runs.queue.connection' => 'missing']);

        expect(RunDispatcher::resolveDriver())->toBe(RunDriver::Browser);
    });

    it('refuses an unknown execution setting', function () {
        config(['tolk.runs.execution' => 'queues']);

        RunDispatcher::resolveDriver();
    })->throws(InvalidArgumentException::class, 'tolk.runs.execution must be auto, queue or browser, not [queues]');
});

describe('start', function () {
    it('starts a queue run with a new generation and one lane job per lane', function () {
        Queue::fake();
        config(['tolk.concurrency.queue' => 3, 'tolk.runs.queue.connection' => 'redis', 'tolk.runs.queue.name' => 'translations']);
        $run = queueRun();

        $this->dispatcher->start($run);

        $lanes = pushedLanes();
        expect($run->status)->toBe(RunStatus::Running)
            ->and($run->generation)->toBe(1)
            ->and(RunFixtures::stored($run)->generation)->toBe(1)
            ->and(array_map(fn (WorkRun $job) => [$job->runId, $job->lane, $job->generation, $job->restarts], $lanes))
            ->toBe([[$run->id, 1, 1, 0], [$run->id, 2, 1, 0], [$run->id, 3, 1, 0]])
            ->and(array_unique(array_map(fn (WorkRun $job) => $job->connection, $lanes)))->toBe(['redis'])
            ->and(array_unique(array_map(fn (WorkRun $job) => $job->timeout, $lanes)))->toBe([80])
            ->and(RunFixtures::statuses($run))->toBe(['pending', 'pending', 'pending']);
        Queue::assertPushedOn('translations', WorkRun::class);
    });

    it('starts the lanes of a new generation when a running run is started again', function () {
        Queue::fake();
        config(['tolk.concurrency.queue' => 2]);
        $run = queueRun();

        $this->dispatcher->start($run);
        $this->dispatcher->start($run);

        expect(RunFixtures::stored($run)->generation)->toBe(2)
            ->and(array_map(fn (WorkRun $job) => [$job->lane, $job->generation], pushedLanes()))->toBe([[1, 1], [2, 1], [1, 2], [2, 2]]);
    });

    it('pushes nothing for a browser run, which its page drives', function () {
        Queue::fake();
        $run = queueRun(['driver' => RunDriver::Browser]);

        $this->dispatcher->start($run);

        expect(RunFixtures::stored($run)->status)->toBe(RunStatus::Running)
            ->and(RunFixtures::stored($run)->generation)->toBe(0);
        Queue::assertNotPushed(WorkRun::class);
    });

    it('hands a CLI run started here, whose command has ended, to the driver the CP uses', function (string $connection, RunDriver $driver, int $lanes) {
        Queue::fake();
        config(['queue.default' => $connection, 'tolk.concurrency.queue' => 2]);
        $run = queueRun(['driver' => RunDriver::Cli]);
        app(RunProcessor::class)->cancel($run);
        app(RunProcessor::class)->resume($run);

        $this->dispatcher->start($run);

        expect($run->driver)->toBe($driver)
            ->and(RunFixtures::stored($run)->driver)->toBe($driver)
            ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::Running);
        Queue::assertPushed(WorkRun::class, $lanes);
    })->with([
        'no worker' => ['sync', RunDriver::Browser, 0],
        'a worker' => ['database', RunDriver::Queue, 2],
    ]);

    it('leaves a finished run alone', function () {
        Queue::fake();
        $run = queueRun();
        $run->status = RunStatus::Cancelled;
        $this->runs->save($run);

        $this->dispatcher->start($run);

        expect(RunFixtures::stored($run)->status)->toBe(RunStatus::Cancelled)
            ->and(RunFixtures::stored($run)->generation)->toBe(0);
        Queue::assertNotPushed(WorkRun::class);
    });
});

describe('takeOver', function () {
    it('moves a queue run to the browser with a new generation, so its lane jobs end', function () {
        Queue::fake();
        $run = queueRun();
        $this->dispatcher->start($run);
        $lane = pushedLanes()[0];

        $this->dispatcher->takeOver($run);
        workLane($lane);

        expect(RunFixtures::stored($run))
            ->driver->toBe(RunDriver::Browser)
            ->generation->toBe(2)
            ->status->toBe(RunStatus::Running)
            ->and(RunFixtures::statuses($run))->toBe(['pending', 'pending', 'pending'])
            ->and(pushedLanes())->toHaveCount(4);
        $this->assertNoModelCalls();
    });

    it('leaves a run of another driver as it is', function () {
        $run = RunFixtures::started();

        $this->dispatcher->takeOver($run);

        expect(RunFixtures::stored($run))->driver->toBe(RunDriver::Browser)->generation->toBe(0);
    });
});

describe('WorkRun', function () {
    it('exits without claiming when its generation is not the run\'s', function () {
        Queue::fake();
        config(['tolk.concurrency.queue' => 1]);
        $this->fakeTranslations();
        $run = queueRun();
        $this->dispatcher->start($run);
        $old = pushedLanes()[0];
        $this->dispatcher->start($run);

        workLane($old);

        expect(RunFixtures::statuses($run))->toBe(['pending', 'pending', 'pending'])
            ->and(array_map(fn ($item) => $item->claim, RunFixtures::items($run)))->toBe([null, null, null])
            ->and(pushedLanes())->toHaveCount(2);
        $this->assertNoModelCalls();
    });

    it('exits without claiming when the run is not running or was deleted', function (Closure $change) {
        Queue::fake();
        $this->fakeTranslations();
        $run = queueRun();
        $this->dispatcher->start($run);
        $change($run, $this->runs);

        workLane(new WorkRun($run->id, 1, 1));

        expect(pushedLanes())->toHaveCount(4);
        $this->assertNoModelCalls();
    })->with([
        'cancelled' => function (Run $run, RunRepository $runs) {
            $run->status = RunStatus::Cancelled;
            $runs->save($run);
        },
        'deleted' => fn (Run $run, RunRepository $runs) => $runs->delete($run),
    ]);

    it('goes on with a command-line run\'s plans in the timeout and batch size of the queue, inside the job\'s timeout', function () {
        Queue::fake();
        config(['queue.default' => 'database', 'tolk.concurrency.queue' => 1, 'tolk.batch.max_chars.web' => 10]);
        $engine = ScriptedEngine::bind(fn (EngineCall $call, int $number) => $number === 1
            ? throw ProviderUnavailable::connection('anthropic', 3)
            : ScriptedEngine::translated($call));
        $run = RunFixtures::started(['entryIds' => [$this->hello->id()], 'driver' => RunDriver::Cli]);
        RunFixtures::translate($run);
        app(RunProcessor::class)->cancel(RunFixtures::stored($run));
        app(RunProcessor::class)->resume($run);
        $this->dispatcher->start($run);
        $this->travel(4)->seconds();

        workLane(pushedLanes()[0]);

        expect(RunFixtures::stored($run)->driver)->toBe(RunDriver::Queue)
            ->and($engine->segmentIds())->toBe([['s1', 's2'], ['s1'], ['s2']])
            ->and(array_column($engine->calls, 'timeout'))->toBe([180, 45, 45])
            ->and(max(array_column(array_slice($engine->calls, 1), 'timeout')))->toBeLessThan(pushedLanes()[0]->timeout)
            ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::Completed)
            ->and($this->hello->fresh()->in('el')->get('intro'))->toBe('[T] A short intro.');
    });

    it('ends quietly when the run is deleted while it works', function () {
        Queue::fake();
        config(['tolk.concurrency.queue' => 1]);
        $run = queueRun();
        ScriptedEngine::bind(function (EngineCall $call) use ($run) {
            $this->runs->delete($run);

            return ScriptedEngine::translated($call);
        });
        $this->dispatcher->start($run);

        workLane(pushedLanes()[0]);

        expect($this->runs->find($run->id))->toBeNull()
            ->and(pushedLanes())->toHaveCount(1);
    });

    it('completes a small run on the sync connection by dispatching itself again', function () {
        config(['queue.default' => 'sync', 'tolk.concurrency.queue' => 1]);
        // Every call takes longer than the lane's deadline, so each job translates one item.
        ScriptedEngine::bind(function (EngineCall $call) {
            $this->travel(WorkRun::deadlineSeconds() + 5)->seconds();

            return ScriptedEngine::translated($call);
        });
        $lanes = 0;
        Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$lanes) {
            $lanes += (int) ($event->job->resolveName() === WorkRun::class);
        });
        $run = queueRun();

        $this->dispatcher->start($run);

        $greek = Entry::query()->where('collection', 'articles')->where('site', 'el')->get();
        expect($lanes)->toBe(3)
            ->and(RunFixtures::stored($run)->status)->toBe(RunStatus::Completed)
            ->and(RunFixtures::statuses($run))->toBe(['done', 'done', 'done'])
            ->and($greek->map->get('title')->sort()->values()->all())->toBe(['[T] Again', '[T] Hello', '[T] World']);
    });

    it('waits on the sync connection instead of dispatching itself again at once', function () {
        config(['queue.default' => 'sync', 'tolk.concurrency.queue' => 1]);
        Sleep::fake(syncWithCarbon: true);
        ScriptedEngine::bind(fn (EngineCall $call, int $number) => $number === 1
            ? throw ProviderPaused::for('anthropic', 7)
            : ScriptedEngine::translated($call));
        $run = queueRun(['entryIds' => [$this->hello->id()]]);

        $this->dispatcher->start($run);

        Sleep::assertSequence([Sleep::for(7)->seconds()]);
        expect(RunFixtures::stored($run)->status)->toBe(RunStatus::Completed)
            ->and(RunFixtures::statuses($run))->toBe(['done']);
    });

    it('dispatches itself again after the provider\'s wait, counting restarts from zero again', function () {
        Queue::fake();
        config(['tolk.concurrency.queue' => 1]);
        ScriptedEngine::bind(fn () => throw ProviderPaused::for('anthropic', 20));
        $run = queueRun(['entryIds' => [$this->hello->id()]]);
        $this->dispatcher->start($run);

        workLane(new WorkRun($run->id, 1, 1, restarts: 3));

        $next = pushedLanes()[1];
        expect([$next->lane, $next->generation, $next->restarts, $next->delay])->toBe([1, 1, 0, 20])
            ->and(RunFixtures::items($run)[0]->status)->toBe(RunItemStatus::Pending);
    });

    it('waits at least a second when nothing can be claimed', function () {
        Queue::fake();
        config(['tolk.concurrency.queue' => 1]);
        $this->fakeTranslations();
        $run = queueRun(['entryIds' => [$this->hello->id()]]);
        $this->dispatcher->start($run);
        $this->runs->claimNext($run, 'queue-9', 300);

        workLane(pushedLanes()[0]);

        expect(pushedLanes()[1]->delay)->toBe(1)
            ->and(RunFixtures::items($run)[0]->claim->by)->toBe('queue-9');
        $this->assertNoModelCalls();
    });

    it('dispatches itself again at once when its deadline ended the work', function () {
        Queue::fake();
        config(['tolk.concurrency.queue' => 1]);
        ScriptedEngine::bind(function (EngineCall $call) {
            $this->travel(WorkRun::deadlineSeconds() + 5)->seconds();

            return ScriptedEngine::translated($call);
        });
        $run = queueRun();
        $this->dispatcher->start($run);

        workLane(pushedLanes()[0]);

        expect(pushedLanes()[1]->delay)->toBeNull()
            ->and(RunFixtures::statuses($run))->toBe(['done', 'pending', 'pending']);
    });

    it('dispatches itself again at once when its apply step ended with items left', function () {
        Queue::fake();
        config(['tolk.concurrency.queue' => 1]);
        $this->fakeTranslations();
        Event::listen(EntrySaving::class, fn () => $this->travel(WorkRun::APPLY_SECONDS + 1)->seconds());
        $run = queueRun();
        $this->dispatcher->start($run);

        workLane(pushedLanes()[0]);

        expect(pushedLanes()[1]->delay)->toBeNull()
            ->and(RunFixtures::statuses($run))->toBe(['done', 'translated', 'translated']);
    });

    it('dispatches itself again at once when its apply step sent an item back', function () {
        Queue::fake();
        config(['tolk.concurrency.queue' => 1]);
        // The origin changes after the item was planned, so the apply sends it back to be translated again.
        ScriptedEngine::bind(function (EngineCall $call) {
            Entry::find($this->hello->id())->set('intro', 'A changed intro.')->save();

            return ScriptedEngine::translated($call);
        });
        $run = queueRun(['entryIds' => [$this->hello->id()]]);
        $this->dispatcher->start($run);

        workLane(pushedLanes()[0]);

        expect(pushedLanes()[1]->delay)->toBeNull()
            ->and(RunFixtures::items($run)[0])->status->toBe(RunItemStatus::Pending)->reason->toBe('source_changed');
    });

    it('stops dispatching itself when the run is finished', function () {
        Queue::fake();
        config(['tolk.concurrency.queue' => 1]);
        $this->fakeTranslations();
        $run = queueRun();
        $this->dispatcher->start($run);

        workLane(pushedLanes()[0]);

        expect(RunFixtures::stored($run)->status)->toBe(RunStatus::Completed)
            ->and(pushedLanes())->toHaveCount(1);
    });

    it('dispatches the same lane and generation again after a failure, up to the limit', function () {
        Queue::fake();
        config(['tolk.concurrency.queue' => 1]);
        $run = queueRun();
        $this->dispatcher->start($run);

        foreach (range(0, WorkRun::MAX_RESTARTS) as $restarts) {
            (new WorkRun($run->id, 2, 1, $restarts))->failed(new RuntimeException('Killed.'));
        }

        $restarted = array_filter(pushedLanes(), fn (WorkRun $job) => $job->lane === 2);
        expect(array_map(fn (WorkRun $job) => [$job->generation, $job->restarts], array_values($restarted)))
            ->toBe([[1, 1], [1, 2], [1, 3], [1, 4], [1, 5]]);
    });

    it('is not dispatched again after a failure when the run moved on', function (Closure $change) {
        Queue::fake();
        config(['tolk.concurrency.queue' => 1]);
        $run = queueRun();
        $this->dispatcher->start($run);
        $lane = pushedLanes()[0];
        $change($run, $this->runs, $this->dispatcher);

        $lane->failed(new RuntimeException('Killed.'));

        expect(array_filter(pushedLanes(), fn (WorkRun $job) => $job->restarts > 0))->toBe([]);
    })->with([
        'a newer generation' => fn (Run $run, RunRepository $runs, RunDispatcher $dispatcher) => $dispatcher->takeOver($run),
        'cancelled' => function (Run $run, RunRepository $runs) {
            $run->status = RunStatus::Cancelled;
            $runs->save($run);
        },
        'deleted' => fn (Run $run, RunRepository $runs) => $runs->delete($run),
    ]);

    it('ends before the queue hands it out again', function (?int $retryAfter, int $deadline, int $timeout) {
        config(['queue.default' => 'tolk', 'queue.connections.tolk' => array_filter(['driver' => 'redis', 'retry_after' => $retryAfter]), 'tolk.http.timeout.web' => 45]);

        $job = new WorkRun('01J0000000000000000000000A', 1, 1);

        expect(WorkRun::deadlineSeconds())->toBe($deadline)
            ->and(WorkRun::timeoutSeconds())->toBe($timeout)
            ->and($job->timeout)->toBe($timeout)
            ->and($job->timeout)->toBeLessThan($retryAfter ?? WorkRun::RETRY_AFTER)
            ->and($job->tries)->toBe(1);
    })->with([
        'the default' => [90, 25, 80],
        'no retry_after' => [null, 25, 80],
        'a long one' => [600, 30, 590],
        'a short one' => [60, 5, 50],
    ]);

    it('leaves room for a provider call and the apply step before its timeout', function () {
        config(['queue.default' => 'redis']);

        expect(WorkRun::deadlineSeconds() + config('tolk.http.timeout.web') + WorkRun::APPLY_SECONDS)->toBeLessThan(WorkRun::timeoutSeconds());
    });

    it('reads the timing of the first connection of a failover connection', function () {
        config(['queue.default' => 'failover', 'queue.connections.database.retry_after' => 200]);

        expect(WorkRun::timeoutSeconds())->toBe(190);
    });
});
