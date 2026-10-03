<?php

namespace Reach\StatamicTolk\Support;

use Closure;
use Illuminate\Support\Facades\Event;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Git;
use Statamic\Git\Subscriber;

/**
 * Statamic's git integration. The config is checked on every call: the Git class throws while git is disabled, and the
 * facade keeps its instance once resolved (T2.3).
 */
final class GitListeners
{
    /**
     * Runs the callback without Statamic's commit listener and turns it back on even when the callback throws, which core's
     * withoutListeners() does not.
     */
    public static function suspended(Closure $callback): mixed
    {
        // enable() subscribes the listener again without checking, so a listener that is already off stays off.
        if (! config('statamic.git.enabled') || ! self::listening()) {
            return $callback();
        }

        Subscriber::disable();

        try {
            return $callback();
        } finally {
            Subscriber::enable();
        }
    }

    /**
     * Dispatches one commit of the tracked content paths. CommitJob is unique, so while one is pending on a real queue,
     * this dispatch is dropped and the pending job commits everything.
     */
    public static function commit(string $message): void
    {
        if (config('statamic.git.enabled') && config('statamic.git.automatic')) {
            Git::dispatchCommit($message);
        }
    }

    private static function listening(): bool
    {
        return in_array(Subscriber::class.'@commit', Event::getRawListeners()[EntrySaved::class] ?? [], true);
    }
}
