<?php

namespace Reach\StatamicTolk\Support;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Reach\StatamicTolk\Exceptions\WriterBusy;
use Statamic\Facades\Blink;
use Statamic\Facades\Stache;
use Statamic\Stache\Stores\AggregateStore;
use Statamic\Stache\Stores\Store;
use Statamic\Support\Blink as BlinkManager;

/**
 * The one writer of Statamic content (§A11): parallel Stache writers lose each other's paths and index entries (T2.3).
 */
final class ContentWriter
{
    public const string LOCK = 'tolk:write';

    /**
     * The message of the one commit that follows a batch of writes, which run without Statamic's git listener.
     */
    public const string COMMIT_MESSAGE = 'Content translated';

    private static bool $active = false;

    /**
     * Runs the callback under the global write lock, after reloading what other processes cached, without Statamic's git
     * listeners. A call from inside the callback runs at once, because the lock is already held.
     */
    public static function write(Closure $callback): mixed
    {
        if (self::$active) {
            return $callback();
        }

        $lock = Cache::store(config('tolk.cache_store'))->lock(self::LOCK, (int) config('tolk.write.lock_seconds'));

        try {
            $lock->block((int) config('tolk.write.wait_seconds'));
        } catch (LockTimeoutException) {
            throw WriterBusy::waited((int) config('tolk.write.wait_seconds'));
        }

        self::$active = true;

        try {
            self::refreshStache();

            return GitListeners::suspended($callback);
        } finally {
            self::$active = false;
            $lock->release();
        }
    }

    public static function active(): bool
    {
        return self::$active;
    }

    /**
     * Makes this process see what other processes saved: core's between-jobs reset, each store's memoized keys and index
     * usage (protected, so cleared through a bound closure), and every Blink store (07-spike-findings.md "Stache and
     * parallel writers"). StacheRefreshTest fails when Statamic renames what this clears.
     */
    public static function refreshStache(): void
    {
        Stache::stores()->each->resetMemoizedState();
        app('stache.indexes')->each->resetMemoizedState();

        Stache::stores()
            ->flatMap(fn (Store $store) => $store instanceof AggregateStore ? [$store, ...$store->stores()->all()] : [$store])
            ->each(fn (Store $store) => Closure::bind(function () {
                $this->keys = null;
                $this->usedIndexes = null;
            }, $store, Store::class)());

        Blink::clearResolvedInstance(BlinkManager::class);
    }
}
