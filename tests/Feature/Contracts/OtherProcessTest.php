<?php

use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;

/*
 * Holds CreatesContent::saveEntryElsewhere() and deleteEntryElsewhere() to what a real other process does: this process
 * reads the Stache's items at once, but keeps what it memoized (a localization's origin in Blink, the descendants behind
 * existsIn(), index items) until ContentWriter::refreshStache(). The tests that change content elsewhere while a unit
 * runs rely on that staleness; see docs/research/07-spike-findings.md "Processes and locks".
 */

/**
 * @return array{0: EntryContract, 1: EntryContract} an English article and its Greek localization, which inherits
 */
function articleInGreek(): array
{
    test()->makeCollection('articles', ['en', 'el'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true],
    ]);

    $origin = test()->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'A short intro.'], 'hello');

    return [$origin, test()->localize($origin, 'el')];
}

it('needs the file cache, through which the other process shares the Stache', function () {
    [$origin] = articleInGreek();

    expect(fn () => $this->saveEntryElsewhere($origin->id(), ['intro' => 'An edited intro.']))
        ->toThrow(LogicException::class, 'useFileCache()');
});

describe('with the file cache', function () {
    beforeEach(function () {
        $this->useFileCache();

        [$this->origin, $this->greek] = articleInGreek();
    });

    it('saves an entry that this process reads at once, while a localization keeps the old origin and the indexes their old values until the refresh', function () {
        expect(Entry::find($this->greek->id())->value('intro'))->toBe('A short intro.')
            ->and(Entry::query()->where('title', 'Hello')->count())->toBe(2);

        $this->saveEntryElsewhere($this->origin->id(), ['title' => 'Hi', 'intro' => 'An edited intro.']);

        expect(Entry::find($this->origin->id())->value('intro'))->toBe('An edited intro.')
            ->and(Blink::has('origin-Entry-'.$this->greek->id()))->toBeTrue()
            ->and(Entry::find($this->greek->id())->value('intro'))->toBe('A short intro.')
            ->and(Entry::query()->where('title', 'Hi')->count())->toBe(0);

        ContentWriter::refreshStache();

        expect(Entry::find($this->greek->id())->value('intro'))->toBe('An edited intro.')
            ->and(Entry::query()->where('title', 'Hi')->count())->toBe(2)
            ->and(Entry::query()->where('title', 'Hello')->count())->toBe(0);
    });

    it('deletes an entry that this process stops finding at once, while existsIn() and the site index keep it until the refresh, and forgets its state there', function () {
        StateFixtures::store($this->greek, ['title' => StateFixtures::machine('Hello', 'Γεια')]);

        expect($this->origin->fresh()->existsIn('el'))->toBeTrue()
            ->and(Entry::query()->where('site', 'el')->count())->toBe(1);

        $this->deleteEntryElsewhere($this->greek->id());

        expect(Entry::find($this->greek->id()))->toBeNull()
            ->and($this->origin->fresh()->existsIn('el'))->toBeTrue()
            ->and(Entry::query()->where('site', 'el')->count())->toBe(1)
            ->and(StateFixtures::stored($this->greek)->isEmpty())->toBeTrue();

        ContentWriter::refreshStache();

        expect($this->origin->fresh()->existsIn('el'))->toBeFalse()
            ->and(Entry::query()->where('site', 'el')->count())->toBe(0);
    });

    it('throws a failure again at the end of the test, so one the code under test catches still fails it', function (?string $id, array $changes, string $reason) {
        try {
            $this->saveEntryElsewhere($id ?? $this->origin->id(), $changes);
        } catch (Throwable $caught) {
        }

        // beforeApplicationDestroyed() puts its callback first; taken out here, it does not fail this test at tearDown.
        $atTearDown = array_shift($this->beforeApplicationDestroyedCallbacks);

        expect($caught ?? null)->toBeInstanceOf(RuntimeException::class)
            ->and($caught->getMessage())->toContain($reason)
            ->and($atTearDown)->toThrow(fn (RuntimeException $again) => expect($again)->toBe($caught));
    })->with([
        'reported by the other process' => ['missing', ['intro' => 'An edited intro.'], 'The entry [missing] does not exist.'],
        'before the other process starts' => [null, ['intro' => "\xB1"], 'Malformed UTF-8'],
    ]);
});
