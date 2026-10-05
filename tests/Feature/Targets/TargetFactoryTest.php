<?php

use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\InvalidTarget;
use Reach\StatamicTolk\Exceptions\InvalidTargetRef;
use Reach\StatamicTolk\Exceptions\NotALocalization;
use Reach\StatamicTolk\Exceptions\NotSupportedYet;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\Targets\EntryTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;

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

    it('does not support the other content types yet', function (string $reference) {
        targets()->fromReference($reference);
    })->throws(NotSupportedYet::class)->with([
        'globals::footer::el',
        'nav::main::el',
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

    it('does not support the other content types yet', function () {
        targets()->fromDescription(['type' => 'globals', 'source_id' => 'footer', 'site' => 'el']);
    })->throws(NotSupportedYet::class);
});
