<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Reach\StatamicTolk\Support\GitListeners;
use Statamic\Events\EntrySaved;
use Statamic\Git\CommitJob;
use Statamic\Git\Subscriber;

beforeEach(function () {
    $this->useFileCache();
    Queue::fake();

    $this->makeCollection('articles', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
    $this->origin = $this->makeEntry('articles', 'en', ['title' => 'Hello']);

    config(['statamic.git.enabled' => true, 'statamic.git.automatic' => true]);
});

function commitListeners(): int
{
    return count(array_filter(Event::getRawListeners()[EntrySaved::class] ?? [], fn ($listener) => $listener === Subscriber::class.'@commit'));
}

it('is active again after the callback threw', function () {
    expect(fn () => GitListeners::suspended(function () {
        $this->origin->set('title', 'Inside')->save();

        throw new RuntimeException('failed');
    }))->toThrow(RuntimeException::class, 'failed');

    Queue::assertNotPushed(CommitJob::class);
    expect(commitListeners())->toBe(1);

    $this->origin->set('title', 'After')->save();

    Queue::assertPushed(CommitJob::class, 1);
});

it('returns the result of the callback, which saves without a commit', function () {
    $result = GitListeners::suspended(function () {
        $this->origin->set('title', 'Inside')->save();

        return 'saved';
    });

    expect($result)->toBe('saved')
        ->and(commitListeners())->toBe(1);
    Queue::assertNotPushed(CommitJob::class);
});

it('subscribes the listener once after nested calls and leaves a listener that was off off', function () {
    $inside = GitListeners::suspended(fn () => GitListeners::suspended(fn () => commitListeners()));
    $nested = commitListeners();

    Subscriber::disable();
    GitListeners::suspended(fn () => null);
    $disabled = commitListeners();
    Subscriber::enable();

    expect([$inside, $nested, $disabled])->toBe([0, 1, 0]);
});

it('leaves the listener alone while git is disabled', function () {
    config(['statamic.git.enabled' => false]);

    expect(GitListeners::suspended(fn () => commitListeners()))->toBe(1);
});

it('commits only while git and automatic commits are enabled', function () {
    config(['statamic.git.enabled' => false]);
    GitListeners::commit('Disabled');

    config(['statamic.git.enabled' => true, 'statamic.git.automatic' => false]);
    GitListeners::commit('Manual');

    Queue::assertNotPushed(CommitJob::class);

    config(['statamic.git.automatic' => true]);
    GitListeners::commit('Translation state saved');

    Queue::assertPushed(CommitJob::class, 1);
    Queue::assertPushed(CommitJob::class, fn (CommitJob $job) => $job->message === 'Translation state saved');
});
