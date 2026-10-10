<?php

use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Statamic\Facades\Term;

/*
 * Holds CreatesContent's …Elsewhere() helpers to what a real other process does: this process reads the Stache's items
 * at once, but keeps what it memoized (a localization's origin in Blink, the descendants behind in() and existsIn(),
 * index items) until ContentWriter::refreshStache(). The tests that change content elsewhere while a unit runs rely on
 * that staleness; see docs/research/07-spike-findings.md "Processes and locks".
 */

/**
 * @return array{0: EntryContract, 1: EntryContract} an English article and its Greek localization, which inherits
 */
function articleInGreek(): array
{
    test()->makeCollection('articles', ['en', 'el', 'de'], [
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

    it('deletes an origin, which this process stops finding by id at once, while the site index counts it until the refresh', function (string $localizations, bool $greekKept) {
        expect(Entry::query()->where('site', 'en')->count())->toBe(1)
            ->and(Entry::query()->where('site', 'el')->count())->toBe(1);

        $this->deleteEntryElsewhere($this->origin->id(), $localizations);

        expect(Entry::find($this->origin->id()))->toBeNull()
            ->and(Entry::find($this->greek->id()) !== null)->toBe($greekKept)
            ->and(Entry::query()->where('site', 'en')->count())->toBe(1)
            ->and(Entry::query()->where('site', 'el')->count())->toBe(1);

        ContentWriter::refreshStache();

        expect(Entry::query()->where('site', 'en')->count())->toBe(0)
            ->and(Entry::query()->where('site', 'el')->count())->toBe($greekKept ? 1 : 0);
    })->with([
        'with its localizations' => ['delete', false],
        'after detaching its localizations' => ['detach', true],
    ]);

    it('deletes an origin after detaching its localizations, which keep the values they inherited', function () {
        $this->deleteEntryElsewhere($this->origin->id(), 'detach');

        $greek = Entry::find($this->greek->id());

        expect(Entry::find($this->origin->id()))->toBeNull()
            ->and($greek->hasOrigin())->toBeFalse()
            ->and($greek->data()->only(['title', 'intro'])->all())->toBe(['title' => 'Hello', 'intro' => 'A short intro.']);
    });

    it('detaches the localizations of an entry, which this process finds through it until the refresh', function () {
        expect($this->origin->fresh()->in('el')?->id())->toBe($this->greek->id());

        $this->detachLocalizationsElsewhere($this->origin->id());

        expect(Entry::find($this->greek->id())->hasOrigin())->toBeFalse()
            ->and($this->origin->fresh()->in('el')?->id())->toBe($this->greek->id());

        ContentWriter::refreshStache();

        expect($this->origin->fresh()->in('el'))->toBeNull()
            ->and(Entry::find($this->greek->id())->value('intro'))->toBe('A short intro.');
    });

    it('localizes an entry into a site, which this process finds through it only after the refresh', function () {
        expect($this->origin->fresh()->existsIn('de'))->toBeFalse();

        $this->localizeEntryElsewhere($this->origin->id(), 'de', ['title' => 'Hallo']);

        expect($this->origin->fresh()->existsIn('de'))->toBeFalse();

        ContentWriter::refreshStache();

        expect($this->origin->fresh()->in('de')->value('title'))->toBe('Hallo')
            ->and($this->origin->fresh()->in('de')->value('intro'))->toBe('A short intro.');
    });

    it('deletes a term, which this process still finds without its data until the refresh', function () {
        $this->makeTaxonomy('tags', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeTerm('tags', 'food', ['en' => ['title' => 'Food'], 'el' => ['title' => 'Φαγητό']]);
        $ref = new TargetRef(ContentType::Term, 'tags::food', 'el');
        app(StateRepository::class)->update($ref, fn (ItemState $state) => $state->withField('title', StateFixtures::machine('Food', 'Φαγητό')));

        $this->deleteTermElsewhere('tags::food');

        expect(Term::find('tags::food'))->not->toBeNull()
            ->and(Term::find('tags::food')->value('title'))->toBeNull()
            ->and(app(StateRepository::class)->get($ref)->isEmpty())->toBeTrue();

        ContentWriter::refreshStache();

        expect(Term::find('tags::food'))->toBeNull();
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
