<?php

namespace Reach\StatamicTolk\Support;

use Statamic\Facades\Blink;

/**
 * What core memoizes in Blink for the entries a process reads, which nothing forgets for the rest of the process: in the
 * default store each entry's localizations (its direct ones and, since Statamic 6.36, its whole family), origin and
 * blueprint, and in `structure-entries` the entries of tree pages
 * (Page::entry(), which a page's parent is read through). Work over a whole collection marks the memo before each slice
 * of entries and forgets what the slice added after it, so it holds one slice of entries in memory, not the collection.
 */
final readonly class EntryMemo
{
    private const string KEYS = '/^(entry-(all-)?descendants-|origin-Entry-|entry-.+-blueprint$)/';

    /**
     * @param  array<string, true>  $default  the keys of the default store when marked
     * @param  array<string, true>  $pages  the keys of `structure-entries` when marked
     */
    private function __construct(private array $default, private array $pages) {}

    public static function mark(): self
    {
        return new self(
            array_fill_keys(array_keys(Blink::store()->all()), true),
            array_fill_keys(array_keys(Blink::store('structure-entries')->all()), true),
        );
    }

    /**
     * Forgets the entries memoized since mark(); what was memoized before stays.
     */
    public function forget(): void
    {
        $default = Blink::store();
        $pages = Blink::store('structure-entries');

        foreach (array_keys($default->all()) as $key) {
            if (! isset($this->default[$key]) && preg_match(self::KEYS, (string) $key) === 1) {
                $default->forget((string) $key);
            }
        }

        foreach (array_keys($pages->all()) as $key) {
            if (! isset($this->pages[$key])) {
                $pages->forget((string) $key);
            }
        }
    }
}
