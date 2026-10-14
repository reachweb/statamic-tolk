<?php

use Illuminate\Filesystem\Filesystem;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\InvalidTarget;
use Reach\StatamicTolk\Exceptions\InvalidTargetRef;
use Reach\StatamicTolk\Exceptions\NotALocalization;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\Targets\EntryTarget;
use Reach\StatamicTolk\Targets\NavTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Statamic\Facades\Entry;

function targets(): TargetFactory
{
    return app(TargetFactory::class);
}

beforeEach(function () {
    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
    ]);

    $this->origin = $this->makeEntry('articles', 'en', ['title' => 'Hello'], 'hello');
});

describe('fromReference', function () {
    it('builds the target of a localization from a string or a TargetRef', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $reference = 'entry::'.$localization->id();

        foreach ([$reference, TargetRef::parse($reference)] as $input) {
            $target = targets()->fromReference($input);

            expect($target)->toBeInstanceOf(EntryTarget::class)
                ->and((string) $target->ref())->toBe($reference)
                ->and($target->sourceSite())->toBe('en')
                ->and($target->targetSite())->toBe('el')
                ->and($target->describe())->toBe(['type' => 'entry', 'source_id' => $this->origin->id(), 'site' => 'el']);
        }
    });

    it('translates a localization in a chain from its own origin', function () {
        $el = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $de = $this->localize($el->fresh(), 'de', ['title' => 'Hallo']);

        $target = targets()->fromReference('entry::'.$de->id());

        expect((string) $target->ref())->toBe('entry::'.$de->id())
            ->and($target->sourceSite())->toBe('el')
            ->and($target->sourceIsLocalization())->toBeTrue();
    });

    it('rejects an entry without an origin', function () {
        targets()->fromReference('entry::'.$this->origin->id());
    })->throws(NotALocalization::class);

    it('rejects an unknown entry', function () {
        targets()->fromReference('entry::does-not-exist');
    })->throws(TargetNotFound::class);

    it('rejects an invalid reference', function () {
        targets()->fromReference('page::1');
    })->throws(InvalidTargetRef::class);

    it('builds the target of a navigation tree, translated from the default site\'s tree', function () {
        $this->makeNav('main', ['en' => [['id' => 'book', 'title' => 'Book']], 'el' => []]);

        $target = targets()->fromReference('nav::main::el');

        expect($target)->toBeInstanceOf(NavTarget::class)
            ->and((string) $target->ref())->toBe('nav::main::el')
            ->and($target->sourceSite())->toBe('en');
    });

    it('translates a navigation tree, a translation file and form texts from the site their state records, also into the default site', function () {
        $this->app->useLangPath($this->temporaryPath('lang'));
        (new Filesystem)->ensureDirectoryExists(lang_path('de'));
        file_put_contents(lang_path('de/messages.php'), "<?php return ['book' => 'Jetzt buchen'];");
        $this->makeNav('main', ['en' => [['id' => 'book', 'title' => 'Book']], 'el' => [['id' => 'book', 'title' => 'Κράτηση']], 'de' => []]);
        $recorded = ['nav::main::en' => 'el', 'nav::main::de' => 'el', 'lang::messages::en' => 'de', 'lang::messages::el' => 'de', 'forms::en' => 'de'];

        foreach ($recorded as $reference => $sourceSite) {
            app(StateRepository::class)->update(TargetRef::parse($reference), fn (ItemState $state) => $state->withField('title', new FieldState(sourceHash: 'a1b2c3d4e5f60718'))->withSourceSite($sourceSite));
        }

        expect(array_map(fn (string $reference) => targets()->fromReference($reference)->describe(), array_keys($recorded)))->toBe([
            ['type' => 'nav', 'source_id' => 'main::el', 'site' => 'en'],
            ['type' => 'nav', 'source_id' => 'main::el', 'site' => 'de'],
            ['type' => 'lang', 'source_id' => 'messages::de', 'site' => 'en'],
            ['type' => 'lang', 'source_id' => 'messages::de', 'site' => 'el'],
            ['type' => 'forms', 'source_id' => 'de', 'site' => 'en'],
        ]);
    });

    it('rejects a reference into the source site its state records, and one whose recorded source site is left out now', function (string $reference, string $sourceSite, string $exception) {
        $this->app->useLangPath($this->temporaryPath('lang'));
        $this->makeNav('main', ['en' => [['id' => 'book', 'title' => 'Book']], 'el' => []]);
        app(StateRepository::class)->update(TargetRef::parse($reference), fn (ItemState $state) => $state->withField('title', new FieldState(sourceHash: 'a1b2c3d4e5f60718'))->withSourceSite($sourceSite));

        expect(fn () => targets()->fromReference($reference))->toThrow($exception);
    })->with([
        'nav into its source' => ['nav::main::el', 'el', NotALocalization::class],
        'forms into their source' => ['forms::de', 'de', NotALocalization::class],
        'nav from a site left out' => ['nav::main::el', 'fr', UnknownSite::class],
        'lang from a site left out' => ['lang::_json::de', 'fr', UnknownSite::class],
        'forms from a site left out' => ['forms::de', 'fr', UnknownSite::class],
    ]);
});

describe('forEntry', function () {
    it('accepts an entry or its id', function () {
        $byObject = targets()->forEntry($this->origin, 'el');
        $byId = targets()->forEntry($this->origin->id(), 'el');

        expect($byId->describe())->toBe($byObject->describe())
            ->and($byId->type())->toBe(ContentType::Entry);
    });

    it('rejects an unknown entry id', function () {
        targets()->forEntry('does-not-exist', 'el');
    })->throws(TargetNotFound::class);

    it('rejects an unknown site', function () {
        targets()->forEntry($this->origin, 'fr');
    })->throws(UnknownSite::class);

    it('rejects the source\'s own site', function () {
        targets()->forEntry($this->origin, 'en');
    })->throws(InvalidTarget::class);

    it('rejects a site the collection is not available in', function () {
        $this->makeCollection('notes', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $note = $this->makeEntry('notes', 'en', ['title' => 'Note']);

        targets()->forEntry($note, 'de');
    })->throws(InvalidTarget::class);
});

describe('forLoadedEntry', function () {
    it('reads the entry it was given instead of finding it again, until fresh()', function () {
        // Another instance of the same entry, as a query in another request would return.
        $loaded = Entry::make()->id($this->origin->id())->collection('articles')->locale('en')->slug('hello')->data(['title' => 'Changed in memory']);

        expect(targets()->forLoadedEntry($loaded, 'el')->sourceValue('title'))->toBe('Changed in memory')
            ->and(targets()->forLoadedEntry($loaded, 'el')->fresh()->sourceValue('title'))->toBe('Hello')
            ->and(targets()->forEntry($loaded, 'el')->sourceValue('title'))->toBe('Hello')
            ->and(targets()->forLoadedEntry($loaded, 'el')->describe())->toBe(targets()->forEntry($this->origin, 'el')->describe());
    });

    it('rejects what forEntry() rejects', function (string $site, string $exception) {
        expect(fn () => targets()->forLoadedEntry($this->origin, $site))->toThrow($exception);
    })->with([
        'an unknown site' => ['fr', UnknownSite::class],
        'the source\'s own site' => ['en', InvalidTarget::class],
    ]);

    it('takes a site the collection lacks when the request assumes the collection to have it, as forEntry() does', function () {
        $this->makeCollection('notes', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $note = $this->makeEntry('notes', 'en', ['title' => 'Note']);

        expect(targets()->forLoadedEntry($note, 'de', ['collection:notes' => null])->describe())->toBe(['type' => 'entry', 'source_id' => $note->id(), 'site' => 'de'])
            ->and(fn () => targets()->forLoadedEntry($note, 'de'))->toThrow(InvalidTarget::class);
    });
});

describe('fromDescription', function () {
    it('rebuilds a target from its description', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);

        $existing = targets()->fromDescription(targets()->forEntry($this->origin, 'el')->describe());
        $missing = targets()->fromDescription(targets()->forEntry($this->origin, 'de')->describe());

        expect((string) $existing->ref())->toBe('entry::'.$localization->id())
            ->and($existing->targetSite())->toBe('el')
            ->and($missing->exists())->toBeFalse()
            ->and($missing->describe())->toBe(['type' => 'entry', 'source_id' => $this->origin->id(), 'site' => 'de']);
    });

    it('accepts an integer source id', function () {
        $entry = $this->makeEntry('articles', 'en', ['title' => 'Numbered']);

        expect(fn () => targets()->fromDescription(['type' => 'entry', 'source_id' => 42, 'site' => 'el']))
            ->toThrow(TargetNotFound::class, 'Entry [42] not found.')
            ->and(targets()->fromDescription(['type' => 'entry', 'source_id' => $entry->id(), 'site' => 'el'])->title())
            ->toBe('Numbered');
    });

    it('fails when the source no longer exists', function () {
        $describe = targets()->forEntry($this->origin, 'el')->describe();

        $this->origin->delete();

        targets()->fromDescription($describe);
    })->throws(TargetNotFound::class);

    it('rejects an invalid description', function (array $describe) {
        targets()->fromDescription($describe);
    })->throws(InvalidTarget::class)->with([
        'empty' => [[]],
        'unknown type' => [['type' => 'page', 'source_id' => 'a', 'site' => 'el']],
        'no source' => [['type' => 'entry', 'site' => 'el']],
        'empty source' => [['type' => 'entry', 'source_id' => '', 'site' => 'el']],
        'array source' => [['type' => 'entry', 'source_id' => ['a'], 'site' => 'el']],
        'no site' => [['type' => 'entry', 'source_id' => 'a']],
    ]);

    it('rebuilds a navigation target with the source site its source id names', function () {
        $this->makeNav('main', ['en' => [['id' => 'book', 'title' => 'Book']], 'de' => []]);

        $target = targets()->fromDescription(['type' => 'nav', 'source_id' => 'main::de', 'site' => 'el']);

        expect($target)->toBeInstanceOf(NavTarget::class)
            ->and($target->describe())->toBe(['type' => 'nav', 'source_id' => 'main::de', 'site' => 'el'])
            ->and($target->exists())->toBeFalse();
    });
});
