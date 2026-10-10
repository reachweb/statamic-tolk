<?php

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\UsageMap;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\Finish;
use Reach\StatamicTolk\Enums\TargetResultStatus;
use Reach\StatamicTolk\Events\TargetTranslated;
use Reach\StatamicTolk\Exceptions\ProviderPaused;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\Exceptions\ProviderUnavailable;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Tests\Support\ScriptedEngine;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetResult;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true],
        'body' => ['type' => 'textarea', 'localizable' => true],
        'note' => ['type' => 'text'],
    ]);

    $this->origin = $this->makeEntry('articles', 'en', [
        'title' => 'Hello',
        'intro' => 'A short intro.',
        'body' => 'The body.',
        'note' => 'Internal',
    ], 'hello');

    $this->planKeys = [];
    Event::listen(KeyWritten::class, function (KeyWritten $event) {
        if (str_starts_with($event->key, 'tolk:plan:')) {
            $this->planKeys[] = $event->key;
        }
    });
});

function translatorOptions(array $overrides = []): PlanOptions
{
    return PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), ['profile' => 'background', ...$overrides]);
}

function translateTarget(EntryContract|string $source, string $site = 'el', ?PlanOptions $options = null, ApplyOptions $apply = new ApplyOptions): TargetResult
{
    return app(TargetTranslator::class)->translate(app(TargetFactory::class)->forEntry($source, $site), $options ?? translatorOptions(), $apply);
}

/**
 * Every plan key written so far is gone from the cache.
 */
function assertPlansForgotten(array $keys): void
{
    expect($keys)->not->toBeEmpty();

    foreach ($keys as $key) {
        expect(Cache::get($key))->toBeNull();
    }
}

describe('translating a target', function () {
    it('translates a missing localization to current and forgets the plan', function () {
        $this->fakeTranslations();

        $result = translateTarget($this->origin);
        $localization = $this->origin->fresh()->in('el');

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and($result->created)->toBeTrue()
            ->and($result->ref)->toEqual(new TargetRef(ContentType::Entry, $localization->id()))
            ->and($result->fields)->toBe(['title' => 'applied', 'intro' => 'applied', 'body' => 'applied'])
            ->and($result->warnings)->toBe([])
            ->and($result->corrections)->toBe([])
            ->and($result->reason)->toBeNull()
            ->and($result->error)->toBeNull()
            ->and($localization->data()->all())->toBe(['title' => '[T] Hello', 'intro' => '[T] A short intro.', 'body' => '[T] The body.'])
            ->and($localization->published())->toBeFalse()
            ->and(StateFixtures::statuses($this->origin))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current, 'body' => FieldStatus::Current])
            ->and($this->modelCalls())->toBe(1);
        assertPlansForgotten($this->planKeys);
    });

    it('translates a textarea holding HTML, restores its tags and reads it current afterwards', function () {
        $sent = [];
        $this->fakeTranslations(function (string $text) use (&$sent) {
            $sent[] = $text;

            return '[T] '.$text;
        });
        $this->origin->set('body', '<p>Our <strong>spa</strong> opens at <a href="{{ link to="spa" }}">9:00</a>.<br>Kids &lt; 12 swim free.</p><!-- checked -->')->save();

        $result = translateTarget($this->origin);
        $again = translateTarget($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and($result->warnings)->toBe([])
            ->and($sent)->toContain('<1>Our <2>spa</2> opens at <3>9:00</3>.<x1/>Kids &lt; 12 swim free.</1><x2/>')
            ->and($this->origin->fresh()->in('el')->get('body'))->toBe('[T] <p>Our <strong>spa</strong> opens at <a href="{{ link to="spa" }}">9:00</a>.<br>Kids &lt; 12 swim free.</p><!-- checked -->')
            ->and(StateFixtures::statuses($this->origin)['body'])->toBe(FieldStatus::Current)
            ->and($again->reason)->toBe(TargetResult::NOTHING_TO_DO);
    });

    it('reads current after a translation that reorders the HTML pairs', function () {
        $this->fakeTranslations(fn (string $text) => $text === '<1><2>Book</2> a <3>suite</3>.</1>' ? '<1>Μια <3>σουίτα</3> <2>κλείστε</2>.</1>' : $text);
        $this->origin->set('body', '<p><a href="/rooms">Book</a> a <strong>suite</strong>.</p>')->save();

        translateTarget($this->origin);

        expect($this->origin->fresh()->in('el')->get('body'))->toBe('<p>Μια <strong>σουίτα</strong> <a href="/rooms">κλείστε</a>.</p>')
            ->and(StateFixtures::statuses($this->origin)['body'])->toBe(FieldStatus::Current);
    });

    it('translates a markdown field block by block, keeps its code and links, and reads it current afterwards', function () {
        $sent = [];
        $this->fakeTranslations(function (string $text, array $segment) use (&$sent) {
            $sent[] = [$segment['kind'], $text];

            return '[T] '.$text;
        });
        $this->makeCollection('guides', ['en', 'el'], ['body' => ['type' => 'markdown', 'localizable' => true]]);
        $origin = $this->makeEntry('guides', 'en', ['body' => "# Walk\n\nSee [the map](statamic://asset::assets::map.pdf \"Map\") and [our story].\n\n```\nbook --walk\n```\n\n[our story]: /about"], 'walk');

        $result = translateTarget($origin);
        $again = translateTarget($origin);

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and($sent)->toBe([['markdown', '# Walk'], ['markdown', 'See [the map](<x1/> "Map") and [our story]<x2/>.']])
            ->and($origin->fresh()->in('el')->get('body'))->toBe("[T] # Walk\n\n[T] See [the map](statamic://asset::assets::map.pdf \"Map\") and [our story][our story].\n\n```\nbook --walk\n```\n\n[our story]: /about")
            ->and(StateFixtures::statuses($origin))->toBe(['body' => FieldStatus::Current])
            ->and($again->reason)->toBe(TargetResult::NOTHING_TO_DO);
    });

    it('sends nothing when the target is current', function () {
        $this->fakeTranslations();
        $first = translateTarget($this->origin);

        $result = translateTarget($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Skipped)
            ->and($result->reason)->toBe(TargetResult::NOTHING_TO_DO)
            ->and($result->ref)->toEqual($first->ref)
            ->and($result->created)->toBeFalse()
            ->and($result->fields)->toBe(['title' => 'current', 'intro' => 'current', 'body' => 'current'])
            ->and($result->usage->isEmpty())->toBeTrue()
            ->and($this->modelCalls())->toBe(1);
    });

    it('sends only the changed field in mode update', function () {
        $sent = [];
        $this->fakeTranslations(function (string $text) use (&$sent) {
            $sent[] = $text;

            return '[T] '.$text;
        });
        translateTarget($this->origin);
        $sent = [];

        $this->origin->set('intro', 'A longer intro.')->save();
        $result = translateTarget($this->origin, options: translatorOptions(['mode' => 'update']));

        expect($sent)->toBe(['A longer intro.'])
            ->and($result->status)->toBe(TargetResultStatus::Done)
            ->and($result->created)->toBeFalse()
            ->and($result->fields)->toBe(['title' => 'current', 'intro' => 'applied', 'body' => 'current'])
            ->and($this->origin->fresh()->in('el')->get('intro'))->toBe('[T] A longer intro.')
            ->and(StateFixtures::statuses($this->origin))->each->toBe(FieldStatus::Current);
    });

    it('leaves a manually edited field alone', function () {
        $this->fakeTranslations();
        translateTarget($this->origin);
        $this->origin->fresh()->in('el')->set('title', 'Γεια σας')->save();

        $this->origin->set('title', 'Hello again')->set('intro', 'A longer intro.')->save();
        $result = translateTarget($this->origin, options: translatorOptions(['mode' => 'update']));
        $localization = $this->origin->fresh()->in('el');

        expect($result->fields)->toBe(['title' => 'manual', 'intro' => 'applied', 'body' => 'current'])
            ->and($localization->get('title'))->toBe('Γεια σας')
            ->and($localization->get('intro'))->toBe('[T] A longer intro.')
            ->and(StateFixtures::statuses($this->origin)['title'])->toBe(FieldStatus::Manual);
    });

    it('replaces a manually edited field with overwriteManual', function () {
        $this->fakeTranslations();
        translateTarget($this->origin);
        $this->origin->fresh()->in('el')->set('title', 'Γεια σας')->save();

        $this->origin->set('title', 'Hello again')->save();
        $result = translateTarget(
            $this->origin,
            options: translatorOptions(['mode' => 'update', 'overwriteManual' => true]),
            apply: new ApplyOptions(overwriteManual: true),
        );

        expect($result->fields)->toBe(['title' => 'applied', 'intro' => 'current', 'body' => 'current'])
            ->and($this->origin->fresh()->in('el')->get('title'))->toBe('[T] Hello again')
            ->and(StateFixtures::statuses($this->origin)['title'])->toBe(FieldStatus::Current);
    });

    it('confirms pending state first, even when nothing is sent', function () {
        $this->fakeTranslations();
        $localization = $this->localize($this->origin, 'el', ['title' => '[T] Hello']);
        StateFixtures::store($localization, ['title' => new FieldState(pending: [StateFixtures::pending('Hello', '[T] Hello')])]);

        $result = translateTarget($this->origin, options: translatorOptions(['fields' => ['title']]));
        $state = app(StateRepository::class)->get(StateFixtures::ref($localization));

        expect($result->status)->toBe(TargetResultStatus::Skipped)
            ->and($result->fields)->toBe(['title' => 'current', 'intro' => 'not_requested', 'body' => 'not_requested'])
            ->and($state->hasPending())->toBeFalse()
            ->and($state->field('title')->targetHash)->toBe(Fingerprint::ofLeaves(['[T] Hello']));
        $this->assertNoModelCalls();
    });

    it('fires TargetTranslated with the reference, the handles, the models and a usage map', function () {
        Event::fake([TargetTranslated::class]);
        config(['tolk.batch.max_segments' => 2]);
        $this->fakeTranslations();

        $result = translateTarget($this->origin);

        Event::assertDispatchedTimes(TargetTranslated::class, 1);
        Event::assertDispatched(TargetTranslated::class, function (TargetTranslated $event) use ($result) {
            expect($event->ref)->toEqual($result->ref)
                ->and($event->handles)->toBe(['title', 'intro', 'body'])
                ->and($event->models)->toBe(['anthropic:claude-sonnet-5-5'])
                ->and($event->usage)->toBeInstanceOf(UsageMap::class)
                ->and($event->usage->toArray())->toHaveKeys(['anthropic:claude-sonnet-5-5'])
                ->and($event->usage->toArray()['anthropic:claude-sonnet-5-5'])->toHaveKeys(['input', 'output', 'cache_read', 'cache_write', 'reasoning', 'calls'])
                ->and($event->usage->get(ModelRef::fromString('anthropic:claude-sonnet-5-5'))->calls)->toBe(2)
                ->and($event->usage)->toEqual($result->usage);

            return true;
        });
    });

    it('fires no event when nothing was saved', function () {
        $this->fakeTranslations();
        translateTarget($this->origin);
        Event::fake([TargetTranslated::class]);

        translateTarget($this->origin);

        Event::assertNotDispatched(TargetTranslated::class);
    });
});

describe('failures', function () {
    it('fails an entry whose root page is missing in the target site before planning', function () {
        $this->fakeTranslations();
        $collection = $this->makeCollection('pages', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]], structured: true);
        $collection->structureContents(['root' => true])->save();
        $home = $this->makeEntry('pages', 'en', ['title' => 'Home'], 'home');
        $about = $this->makeEntry('pages', 'en', ['title' => 'About'], 'about');
        Collection::find('pages')->structure()->in('en')->tree([['entry' => $home->id()], ['entry' => $about->id()]])->save();

        $result = translateTarget($about->id());

        expect($result->status)->toBe(TargetResultStatus::Failed)
            ->and($result->reason)->toBe(TargetResult::ROOT_MISSING)
            ->and($result->error)->toBe('Translate Home into Greek first. The Pages collection has no Greek entries yet, and the first one saved becomes its Greek home page, so translating the home page first keeps the structure of the collection\'s tree.')
            ->and($result->ref)->toBeNull()
            ->and($this->planKeys)->toBe([])
            ->and(Entry::query()->where('collection', 'pages')->where('site', 'el')->count())->toBe(0);
        $this->assertNoModelCalls();

        expect(translateTarget($home->id())->status)->toBe(TargetResultStatus::Done)
            ->and(translateTarget($about->id())->status)->toBe(TargetResultStatus::Done);
    });

    it('plans again once when the source changed while its units ran', function () {
        $edits = 0;
        $this->fakeTranslations(function (string $text) use (&$edits) {
            if ($text === 'A short intro.' && $edits++ === 0) {
                Entry::find($this->origin->id())->set('intro', 'An edited intro.')->save();
            }

            return '[T] '.$text;
        });

        $result = translateTarget($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and($this->origin->fresh()->in('el')->get('intro'))->toBe('[T] An edited intro.')
            ->and($this->modelCalls())->toBe(2)
            ->and($result->usage->get(ModelRef::fromString('anthropic:claude-sonnet-5-5'))->calls)->toBe(2)
            ->and(StateFixtures::statuses($this->origin))->each->toBe(FieldStatus::Current);
        assertPlansForgotten($this->planKeys);
    });

    it('plans again once when another process changed the origin of an existing localization while its units ran', function () {
        $this->localize($this->origin, 'el');
        $edits = 0;
        $this->fakeTranslations(function (string $text) use (&$edits) {
            if ($text === 'A short intro.' && $edits++ === 0) {
                $this->saveEntryElsewhere($this->origin->id(), ['intro' => 'An edited intro.']);
            }

            return '[T] '.$text;
        });

        $result = translateTarget($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and($this->origin->fresh()->in('el')->get('intro'))->toBe('[T] An edited intro.')
            ->and($this->modelCalls())->toBe(2)
            ->and(StateFixtures::statuses($this->origin))->each->toBe(FieldStatus::Current);
    });

    it('fails the target when the source changed again', function () {
        $edits = 0;
        $this->fakeTranslations(function (string $text) use (&$edits) {
            if (str_contains($text, 'intro')) {
                $edits++;
                Entry::find($this->origin->id())->set('intro', "Edited intro {$edits}.")->save();
            }

            return '[T] '.$text;
        });

        $result = translateTarget($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Failed)
            ->and($result->reason)->toBe(TargetResult::SOURCE_CHANGED)
            ->and($result->error)->toContain('[intro]')
            ->and($result->usage->isEmpty())->toBeFalse()
            ->and($this->modelCalls())->toBe(2)
            ->and($this->origin->fresh()->existsIn('el'))->toBeFalse();
        assertPlansForgotten($this->planKeys);
    });

    it('waits through transient failures as long as each one asks', function () {
        Sleep::fake();
        $engine = ScriptedEngine::bind(fn (EngineCall $call, int $number) => match ($number) {
            1 => throw ProviderPaused::for('anthropic', 7),
            2 => throw ProviderUnavailable::connection('anthropic', 0),
            default => ScriptedEngine::translated($call),
        });

        $result = translateTarget($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and(count($engine->calls))->toBe(3)
            ->and($result->usage->toArray()['anthropic:claude-sonnet-5-5']['calls'])->toBe(1);
        Sleep::assertSequence([Sleep::for(7)->seconds(), Sleep::for(1)->seconds()]);
    });

    it('gives up after ten waits in a row', function () {
        Sleep::fake();
        $engine = ScriptedEngine::bind(fn (EngineCall $call) => throw ProviderUnavailable::connection('anthropic', 3));

        $result = translateTarget($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Failed)
            ->and($result->reason)->toBe(TargetResult::PROVIDER_UNAVAILABLE)
            ->and($result->error)->toBe('The AI provider [anthropic] was still unavailable after 10 waits.')
            ->and(count($engine->calls))->toBe(11)
            ->and($this->origin->fresh()->existsIn('el'))->toBeFalse();
        Sleep::assertSleptTimes(10);
        assertPlansForgotten($this->planKeys);
    });

    it('counts the waits of each unit on its own', function () {
        Sleep::fake();
        config(['tolk.batch.max_segments' => 1]);
        $engine = ScriptedEngine::bind(fn (EngineCall $call, int $number) => $number % 9 === 0
            ? ScriptedEngine::translated($call)
            : throw ProviderUnavailable::connection('anthropic', 3));

        $result = translateTarget($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and(count($engine->calls))->toBe(27);
        Sleep::assertSleptTimes(24);
    });

    it('lets ProviderRejected propagate and forgets the plan', function () {
        $this->fakeTranslations();
        $options = translatorOptions();
        $this->withProviderKeys();

        expect(fn () => translateTarget($this->origin, options: $options))->toThrow(ProviderRejected::class)
            ->and($this->origin->fresh()->existsIn('el'))->toBeFalse();
        assertPlansForgotten($this->planKeys);
    });

    it('saves a field with a part left in the source language as incomplete', function () {
        ScriptedEngine::bind(fn (EngineCall $call) => in_array('A short intro.', array_column($call->segments, 'text'), true)
            ? ScriptedEngine::result($call, Finish::Refused)
            : ScriptedEngine::translated($call));

        $result = translateTarget($this->origin);

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and($result->fields)->toBe(['title' => 'applied', 'intro' => 'incomplete', 'body' => 'applied'])
            ->and($result->warnings)->toHaveCount(1)
            ->and($result->warnings[0])->toContain('was left untranslated (refused)')
            ->and($this->origin->fresh()->in('el')->get('intro'))->toBe('A short intro.');
    });

    it('fails a field with strict fields and saves the others', function () {
        Event::fake([TargetTranslated::class]);
        ScriptedEngine::bind(fn (EngineCall $call) => in_array('A short intro.', array_column($call->segments, 'text'), true)
            ? ScriptedEngine::result($call, Finish::Refused)
            : ScriptedEngine::translated($call));

        $result = translateTarget($this->origin, options: translatorOptions(['strict' => true]), apply: new ApplyOptions(newStatus: 'mirror'));
        $localization = $this->origin->fresh()->in('el');

        expect($result->status)->toBe(TargetResultStatus::Done)
            ->and($result->fields)->toBe(['title' => 'applied', 'intro' => 'refused', 'body' => 'applied'])
            ->and($result->warnings)->toBe(['The field [intro] could not be translated completely: s2 (refused).'])
            ->and($localization->data()->all())->toBe(['title' => '[T] Hello', 'body' => '[T] The body.'])
            ->and($localization->published())->toBeFalse();
        Event::assertDispatched(TargetTranslated::class, fn (TargetTranslated $event) => $event->handles === ['title', 'body']);
    });

    it('fails the target and saves nothing when every field failed with strict fields', function () {
        Event::fake([TargetTranslated::class]);
        ScriptedEngine::bind(fn (EngineCall $call) => ScriptedEngine::result($call, Finish::Refused));

        $result = translateTarget($this->origin, options: translatorOptions(['strict' => true]));

        expect($result->status)->toBe(TargetResultStatus::Failed)
            ->and($result->reason)->toBe(TargetResult::FIELDS_FAILED)
            ->and($result->error)->toBe('No field could be translated completely.')
            ->and($result->fields)->toBe(['title' => 'refused', 'intro' => 'refused', 'body' => 'refused'])
            ->and($result->warnings)->toHaveCount(3)
            ->and($this->origin->fresh()->existsIn('el'))->toBeFalse();
        Event::assertNotDispatched(TargetTranslated::class);
    });

    it('skips a field a human edited while its units ran', function () {
        $this->fakeTranslations();
        translateTarget($this->origin);
        $this->origin->set('title', 'Hello again')->save();
        $this->fakeTranslations(function (string $text) {
            Entry::find($this->origin->id())->in('el')->set('title', 'Γεια σας')->save();

            return '[T] '.$text;
        });

        $result = translateTarget($this->origin, options: translatorOptions(['mode' => 'update']));

        expect($result->status)->toBe(TargetResultStatus::Skipped)
            ->and($result->reason)->toBe(TargetResult::TARGET_CHANGED)
            ->and($result->fields)->toBe(['title' => 'target_changed', 'intro' => 'current', 'body' => 'current'])
            ->and($this->origin->fresh()->in('el')->get('title'))->toBe('Γεια σας');
    });
});

it('reports what the handlers noticed about a field they leave untouched, with and without other work', function () {
    $this->makeCollection('stories', ['en', 'el'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'content' => ['type' => 'bard', 'localizable' => true, 'display' => 'Content', 'sets' => ['quote' => ['display' => 'Quote', 'fields' => [
            ['handle' => 'text', 'field' => ['type' => 'textarea', 'display' => 'Text']],
        ]]]],
    ]);
    $legacy = [['type' => 'text', 'text' => '<p>Welcome to our hotel.</p>'], ['type' => 'quote', 'text' => 'Lovely stay']];
    $note = 'Content (content) is stored in the Bard format of Statamic 2 and was left untouched. Saving it once in the control panel converts it.';
    $this->fakeTranslations();

    $done = translateTarget($this->makeEntry('stories', 'en', ['title' => 'Legacy', 'content' => $legacy], 'legacy'));
    $skipped = translateTarget($this->makeEntry('stories', 'en', ['content' => $legacy], 'only-legacy'));

    expect($done->status)->toBe(TargetResultStatus::Done)
        ->and($done->fields)->toBe(['title' => TargetResult::APPLIED, 'content' => 'not_applicable'])
        ->and($done->warnings)->toBe([$note])
        ->and($skipped->status)->toBe(TargetResultStatus::Skipped)
        ->and($skipped->reason)->toBe(TargetResult::NOTHING_TO_DO)
        ->and($skipped->warnings)->toBe([$note]);
});
