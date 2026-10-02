<?php

use Illuminate\Support\Facades\Queue;
use Statamic\Facades\Git;
use Statamic\Git\CommitJob;
use Statamic\Git\Git as GitManager;
use Statamic\Git\Subscriber;

beforeEach(function () {
    Queue::fake();

    $this->makeCollection('articles', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
    $this->origin = $this->makeEntry('articles', 'en', ['title' => 'Hello']);

    config(['statamic.git.enabled' => true, 'statamic.git.automatic' => true]);
});

function countCommits(): void
{
    test()->commits = 0;
    Git::shouldReceive('as')->andReturnSelf();
    Git::shouldReceive('dispatchCommit')->andReturnUsing(function () {
        test()->commits++;
    });
}

function saveOrigin(): void
{
    test()->origin->fresh()->set('title', 'Changed')->save();
}

it('switches the commit listener off and on with disable() and enable()', function () {
    countCommits();

    saveOrigin();
    expect($this->commits)->toBeGreaterThan(0);

    $this->commits = 0;
    Subscriber::disable();
    saveOrigin();
    expect($this->commits)->toBe(0);

    Subscriber::enable();
    saveOrigin();
    expect($this->commits)->toBeGreaterThan(0);
});

it('leaves the listener off when the callback of withoutListeners() throws', function () {
    countCommits();

    expect(fn () => Subscriber::withoutListeners(function () {
        saveOrigin();

        throw new RuntimeException('failed');
    }))->toThrow(RuntimeException::class);

    saveOrigin();

    expect($this->commits)->toBe(0);

    Subscriber::enable();
});

it('re-enables the listener in a finally block', function () {
    countCommits();

    try {
        Subscriber::disable();
        saveOrigin();
        throw new RuntimeException('failed');
    } catch (RuntimeException) {
    } finally {
        Subscriber::enable();
    }

    expect($this->commits)->toBe(0);

    saveOrigin();

    expect($this->commits)->toBeGreaterThan(0);
});

it('dispatches one commit job with a message through Git::dispatchCommit(), and a second while it is pending is dropped', function () {
    Git::dispatchCommit('Tolk: translated 3 entries');
    Git::dispatchCommit('Tolk: translated 1 entry');

    Queue::assertPushed(CommitJob::class, 1);
    Queue::assertPushed(CommitJob::class, fn (CommitJob $job) => $job->message === 'Tolk: translated 3 entries');
});

it('refuses to construct Git while git is disabled', function () {
    config(['statamic.git.enabled' => false]);

    expect(fn () => app(GitManager::class))->toThrow(Exception::class);
});
