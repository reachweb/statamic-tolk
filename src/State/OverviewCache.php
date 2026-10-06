<?php

namespace Reach\StatamicTolk\State;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Reach\StatamicTolk\Enums\ContentType;

/**
 * The Overview's cells in the cache store of `tolk.cache_store`, as ScanResult arrays: finished cells, and the running
 * totals of a cell whose scan continues in the next request. A cell's slot holds a version of its container and a
 * version of everything, so a bump makes every cell cached before it unreachable; those expire after TTL.
 */
final readonly class OverviewCache
{
    public const int TTL = 600;

    private const string PREFIX = 'tolk:overview:';

    /**
     * The cache key prefix of a cell under the current versions. Read it once before scanning, so a bump during the scan
     * leaves its result where nobody reads it.
     */
    public function slot(ContentType $type, string $container, string $site): string
    {
        return implode(':', [
            self::PREFIX.'cell',
            $this->version(self::PREFIX.'version'),
            $type->value,
            $container,
            $this->version($this->versionKey($type, $container)),
            $site,
        ]);
    }

    public function finished(string $slot): ?ScanResult
    {
        return $this->read($slot);
    }

    /**
     * The totals of a cell's scan up to $cursor.
     */
    public function partial(string $slot, string $cursor): ?ScanResult
    {
        return $this->read($slot.':at:'.$cursor);
    }

    /**
     * Stores a finished cell, or the totals of an unfinished one under the cursor its scan continues from.
     */
    public function put(string $slot, ScanResult $totals): void
    {
        $key = $totals->isFinished() ? $slot : $slot.':at:'.$totals->cursor;

        $this->store()->put($key, $totals->toArray(), self::TTL);
    }

    /**
     * Content or state of the container changed, so its cells are counted again.
     */
    public function bump(ContentType $type, string $container): void
    {
        $this->store()->forever($this->versionKey($type, $container), self::token());
    }

    /**
     * Something every cell may depend on changed: the settings, a fieldset, or the Stache.
     */
    public function bumpAll(): void
    {
        $this->store()->forever(self::PREFIX.'version', self::token());
    }

    private function read(string $key): ?ScanResult
    {
        $cached = $this->store()->get($key);

        return is_array($cached) ? ScanResult::fromArray($cached) : null;
    }

    // A missing version is created, never read as a default, so cells cached before a store lost it are not served.
    private function version(string $key): string
    {
        return (string) $this->store()->rememberForever($key, self::token(...));
    }

    private function versionKey(ContentType $type, string $container): string
    {
        return self::PREFIX.'version:'.$type->value.':'.$container;
    }

    private static function token(): string
    {
        return Str::random(12);
    }

    private function store(): Repository
    {
        return Cache::store(config('tolk.cache_store'));
    }
}
