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

    private const string ENTRIES = self::PREFIX.'version:entries';

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
            // A navigation's status follows the localizations of the entries it links (T9.4).
            ...($type === ContentType::Nav ? [$this->version(self::ENTRIES)] : []),
            $site,
        ]);
    }

    public function finished(string $slot): ?ScanResult
    {
        return self::result($this->store()->get($slot));
    }

    /**
     * The totals of a cell's scan up to $cursor, taken out of the cache: the next slice stores its own.
     */
    public function partial(string $slot, string $cursor): ?ScanResult
    {
        return self::result($this->store()->pull(self::key($slot, $cursor)));
    }

    /**
     * Stores a finished cell, or the totals of an unfinished one under the cursor its scan continues from.
     */
    public function put(string $slot, ScanResult $totals): void
    {
        $this->store()->put(self::key($slot, $totals->cursor), $totals->toArray(), self::TTL);
    }

    /**
     * Drops a finished cell, so it is not answered while its count starts over.
     */
    public function forget(string $slot): void
    {
        $this->store()->forget($slot);
    }

    /**
     * Content or state of the container changed, so its cells are counted again.
     */
    public function bump(ContentType $type, string $container): void
    {
        $this->store()->forever($this->versionKey($type, $container), self::token());
    }

    /**
     * An entry of the collection was saved or deleted, so its cells and those of every navigation are counted again.
     */
    public function bumpEntries(string $collection): void
    {
        $this->bump(ContentType::Entry, $collection);
        $this->store()->forever(self::ENTRIES, self::token());
    }

    /**
     * Something every cell may depend on changed: the settings, a fieldset, or the Stache.
     */
    public function bumpAll(): void
    {
        $this->store()->forever(self::PREFIX.'version', self::token());
    }

    private static function key(string $slot, ?string $cursor): string
    {
        return $cursor === null ? $slot : $slot.':at:'.$cursor;
    }

    private static function result(mixed $cached): ?ScanResult
    {
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
