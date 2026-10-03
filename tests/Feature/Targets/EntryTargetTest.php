<?php

use Illuminate\Support\Facades\Event;
use Reach\StatamicTolk\Content\SourceSummary;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Targets\EntryTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

function entryTarget($source, string $site): EntryTarget
{
    return app(TargetFactory::class)->forEntry($source, $site);
}

beforeEach(function () {
    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true],
        'body' => ['type' => 'textarea', 'localizable' => true],
        'note' => ['type' => 'text'],
        'featured' => ['type' => 'toggle', 'localizable' => true],
    ]);

    $this->origin = $this->makeEntry('articles', 'en', [
        'title' => 'Hello',
        'intro' => 'A short intro.',
        'body' => 'The body.',
        'note' => 'Internal',
        'featured' => true,
    ], 'hello');
});

describe('identity', function () {
    it('resolves an existing localization', function () {
        $localization = $this->localize($this->origin, 'el', ['title' => 'Γεια']);

        $target = entryTarget($this->origin, 'el');

        expect($target->exists())->toBeTrue()
            ->and($target->ref())->toEqual(new TargetRef(ContentType::Entry, $localization->id()))
            ->and((string) $target->ref())->toBe('entry::'.$localization->id())
            ->and($target->type())->toBe(ContentType::Entry)
            ->and($target->sourceSite())->toBe('en')
            ->and($target->targetSite())->toBe('el')
            ->and($target->container())->toBe('articles')
            ->and($target->title())->toBe('Hello')
            ->and($target->editUrl())->toBe($localization->editUrl())
            ->and($target->editUrl())->toContain($localization->id())
            ->and($target->describe())->toBe(['type' => 'entry', 'source_id' => $this->origin->id(), 'site' => 'el']);
    });

    it('resolves a missing localization', function () {
        $target = entryTarget($this->origin, 'de');

        expect($target->exists())->toBeFalse()
            ->and($target->ref())->toBeNull()
            ->and($target->editUrl())->toBeNull()
            ->and($target->sourceSite())->toBe('en')
            ->and($target->targetSite())->toBe('de')
            ->and($target->title())->toBe('Hello')
            ->and($target->hasOverride('title'))->toBeFalse()
            ->and($target->targetValue('title'))->toBeNull()
            ->and($target->describe())->toBe(['type' => 'entry', 'source_id' => $this->origin->id(), 'site' => 'de']);
    });

    it('falls back to the slug for a source without a title', function () {
        $untitled = $this->makeEntry('articles', 'en', ['intro' => 'No title here.'], 'untitled');

        expect(entryTarget($untitled, 'el')->title())->toBe('untitled');
    });

    it('sees a localization created after the target object was built through fresh()', function () {
        $target = entryTarget($this->origin, 'el');

        expect($target->exists())->toBeFalse();

        $localization = $this->localize($this->origin->fresh(), 'el', ['title' => 'Γεια']);
        $fresh = $target->fresh();

        expect($fresh)->not->toBe($target)
            ->and($fresh->describe())->toBe($target->describe())
            ->and($fresh->exists())->toBeTrue()
            ->and((string) $fresh->ref())->toBe('entry::'.$localization->id())
            ->and($fresh->targetValue('title'))->toBe('Γεια');
    });

    it('throws when the entry it was built from no longer exists', function () {
        $target = entryTarget($this->origin, 'el');

        $this->origin->delete();

        expect(fn () => $target->fresh()->exists())->toThrow(TargetNotFound::class);
    });
});

describe('content', function () {
    it('reads the source\'s own value and null for a field the source inherits', function () {
        $el = $this->localize($this->origin, 'el', ['title' => 'Γεια']);

        $target = entryTarget($el, 'de');

        expect($target->exists())->toBeFalse()
            ->and($target->sourceSite())->toBe('el')
            ->and($target->sourceIsLocalization())->toBeTrue()
            ->and($target->sourceHasValue('title'))->toBeTrue()
            ->and($target->sourceValue('title'))->toBe('Γεια')
            ->and($target->sourceHasValue('intro'))->toBeFalse()
            ->and($target->sourceValue('intro'))->toBeNull()
            ->and($target->title())->toBe('Γεια');
    });

    it('reads a root source without inheritance', function () {
        $target = entryTarget($this->origin, 'el');

        expect($target->sourceIsLocalization())->toBeFalse()
            ->and($target->sourceHasValue('intro'))->toBeTrue()
            ->and($target->sourceValue('intro'))->toBe('A short intro.')
            ->and($target->sourceHasValue('missing'))->toBeFalse();
    });

    it('translates an existing localization from its own origin, whatever entry it was built from', function () {
        $el = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $this->localize($el->fresh(), 'de', ['title' => 'Hallo']);

        $target = entryTarget($this->origin, 'de');

        expect($target->exists())->toBeTrue()
            ->and($target->sourceSite())->toBe('el')
            ->and($target->sourceIsLocalization())->toBeTrue()
            ->and($target->sourceValue('title'))->toBe('Γεια')
            ->and($target->targetValue('title'))->toBe('Hallo');
    });

    it('reads the target\'s own data, where an explicit null is an override', function () {
        $this->localize($this->origin, 'el', ['title' => 'Γεια', 'intro' => null]);

        $target = entryTarget($this->origin, 'el');

        expect($target->hasOverride('title'))->toBeTrue()
            ->and($target->targetValue('title'))->toBe('Γεια')
            ->and($target->hasOverride('intro'))->toBeTrue()
            ->and($target->targetValue('intro'))->toBeNull()
            ->and($target->hasOverride('body'))->toBeFalse()
            ->and($target->targetValue('body'))->toBeNull();
    });
});

describe('fields', function () {
    beforeEach(function () {
        $this->makeCollection('stories', ['en', 'el'], [
            'title' => ['type' => 'text', 'localizable' => true],
        ]);
        $this->makeBlueprint('collections.stories', 'feature', [
            'title' => ['type' => 'text', 'localizable' => true],
            'tagline' => ['type' => 'text', 'localizable' => true],
        ]);
    });

    it('never lists a non-localizable field', function () {
        $missing = entryTarget($this->origin, 'el');
        $this->localize($this->origin, 'de', ['title' => 'Hallo']);
        $existing = entryTarget($this->origin, 'de');

        expect($missing->fields()->keys()->all())->toBe(['title', 'intro', 'body'])
            ->and($existing->fields()->keys()->all())->toBe(['title', 'intro', 'body']);
    });

    it('marks in scope exactly the handles of fields() and reads excluded fields from the settings', function () {
        $this->withSettings(['excluded_fields' => ['body']]);

        $target = entryTarget($this->origin, 'el');
        $explained = collect($target->explainFields());

        expect($target->fields()->keys()->all())->toBe(['title', 'intro'])
            ->and($explained->where('in_scope', true)->pluck('handle')->all())->toBe($target->fields()->keys()->all())
            ->and($explained->pluck('reason', 'handle')->all())->toBe([
                'title' => 'ok',
                'intro' => 'ok',
                'body' => 'excluded',
                'note' => 'not_localizable',
                'featured' => 'unsupported_fieldtype',
                'slug' => 'slug',
            ]);
    });

    it('leaves out the title when the collection generates titles', function () {
        Collection::find('articles')->titleFormats('{intro}')->save();

        $target = entryTarget(Entry::find($this->origin->id()), 'el');

        expect($target->fields()->keys()->all())->toBe(['intro', 'body'])
            ->and(collect($target->explainFields())->firstWhere('handle', 'title')['reason'])->toBe('auto_title');
    });

    it('takes a missing localization\'s blueprint from its source without building an unsaved localization', function () {
        $feature = $this->makeEntry('stories', 'en', ['title' => 'Feature', 'tagline' => 'Big news', 'blueprint' => 'feature']);

        $entries = [];
        Event::listen(EntryBlueprintFound::class, function (EntryBlueprintFound $event) use (&$entries) {
            $entries[] = $event->entry;
        });

        $target = entryTarget($feature->id(), 'el');
        Blink::flush();

        expect($target->fields()->keys()->all())->toBe(['title', 'tagline'])
            ->and($target->explainFields())->toHaveCount(3)
            ->and($entries)->not->toBeEmpty();

        foreach ($entries as $entry) {
            expect($entry?->id())->toBe($feature->id());
        }
    });

    it('takes an existing localization\'s blueprint from the localization', function () {
        $feature = $this->makeEntry('stories', 'en', ['title' => 'Feature', 'blueprint' => 'feature']);
        $localization = $this->localize($feature, 'el', ['title' => 'Νέα']);

        $entries = [];
        Event::listen(EntryBlueprintFound::class, function (EntryBlueprintFound $event) use (&$entries) {
            $entries[] = $event->entry?->id();
        });

        $target = entryTarget($feature, 'el');
        Blink::flush();

        expect($target->fields()->keys()->all())->toBe(['title', 'tagline'])
            ->and($entries)->not->toContain(null)
            ->and(end($entries))->toBe($localization->id());
    });
});

describe('document', function () {
    it('describes the source for prompts', function () {
        expect(entryTarget($this->origin, 'el')->document())->toBe([
            'type' => 'entry',
            'title' => 'Hello',
            'container' => 'Articles',
            'url' => '/articles/hello',
            'summary' => 'A short intro.',
        ]);
    });

    it('summarises the longest in-scope field as plain text, cut at 600 characters', function () {
        $long = trim(str_repeat("Καλημέρα από τα Χανιά.\n\n", 40));
        $entry = $this->makeEntry('articles', 'en', [
            'title' => 'Long',
            'intro' => 'Fish & chips at {{ site:name }}, where a < b.',
            'body' => $long,
            'note' => str_repeat('Not localizable. ', 100),
        ]);

        $summary = entryTarget($entry, 'el')->document()['summary'];

        expect(mb_strlen($summary))->toBe(SourceSummary::LENGTH)
            ->and($summary)->toBe(mb_substr(trim(str_repeat('Καλημέρα από τα Χανιά. ', 40)), 0, 600));
    });

    it('strips placeholders and unescapes text in the summary', function () {
        $entry = $this->makeEntry('articles', 'en', [
            'title' => 'Short',
            'intro' => "Fish & chips at {{ site:name }},\nwhere a < b.",
        ]);

        expect(entryTarget($entry, 'el')->document()['summary'])->toBe('Fish & chips at , where a < b.');
    });

    it('leaves fields the source inherits out of the summary', function () {
        $el = $this->localize($this->origin, 'el', ['title' => 'Γεια']);

        expect(entryTarget($el, 'de')->document())
            ->toMatchArray(['title' => 'Γεια', 'url' => '/el/articles/hello', 'summary' => 'Γεια']);
    });

    it('has an empty summary and no url when there is nothing to summarise and no route', function () {
        Collection::find('articles')->routes([])->save();
        $entry = $this->makeEntry('articles', 'en', ['featured' => true], 'flag');

        expect(entryTarget(Entry::find($entry->id()), 'el')->document())
            ->toMatchArray(['title' => 'flag', 'url' => null, 'summary' => '']);
    });
});

describe('authorization', function () {
    it('requires access to the target site and the right to edit the localization', function () {
        $this->localize($this->origin, 'el', ['title' => 'Γεια']);
        $this->localize($this->origin, 'de', ['title' => 'Hallo']);

        $user = $this->signInWithPermissions(['access el site', 'edit articles entries']);

        expect(entryTarget($this->origin, 'el')->authorize($user))->toBeTrue()
            ->and(entryTarget($this->origin, 'de')->authorize($user))->toBeFalse();
    });

    it('requires access to the target site and the right to edit the source for a missing localization', function () {
        $withoutTarget = $this->signInWithPermissions(['access en site', 'edit articles entries']);

        expect(entryTarget($this->origin, 'el')->authorize($withoutTarget))->toBeFalse();

        $withoutSource = $this->signInWithPermissions(['access el site', 'edit articles entries']);

        expect(entryTarget($this->origin, 'el')->authorize($withoutSource))->toBeFalse();

        $withSource = $this->signInWithPermissions(['access en site', 'access el site', 'edit articles entries']);

        expect(entryTarget($this->origin, 'el')->authorize($withSource))->toBeTrue();
    });

    it('denies a user without the edit permission and allows a super user', function () {
        $this->localize($this->origin, 'el', ['title' => 'Γεια']);

        expect(entryTarget($this->origin, 'el')->authorize($this->signInWithPermissions(['access el site'])))->toBeFalse()
            ->and(entryTarget($this->origin, 'el')->authorize($this->signInAdmin()))->toBeTrue();
    });
});
