<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Ai\ProviderError;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\Finish;
use Reach\StatamicTolk\Enums\ProviderErrorKind;
use Reach\StatamicTolk\Events\TargetTranslated;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Tests\Support\ScriptedEngine;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Statamic\Facades\Git;

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true],
        'note' => ['type' => 'text'],
        'tags' => ['type' => 'taggable', 'localizable' => true],
    ]);

    $this->hello = $this->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'A short intro.', 'note' => 'Internal'], 'hello');
    $this->world = $this->makeEntry('articles', 'en', ['title' => 'World', 'intro' => 'Another intro.'], 'world');
});

/**
 * Runs the command without prompts and returns its exit code and output.
 *
 * @return array{0: int, 1: string}
 */
function translateCommand(array $options): array
{
    $exitCode = Artisan::call('tolk:translate', ['--yes' => true, ...$options]);

    return [$exitCode, Artisan::output()];
}

/**
 * Collects the messages of git commits instead of committing.
 */
function commandCommits(): ArrayObject
{
    config(['statamic.git.enabled' => true, 'statamic.git.automatic' => true]);
    $messages = new ArrayObject;
    Git::shouldReceive('as')->andReturnSelf();
    Git::shouldReceive('dispatchCommit')->andReturnUsing(function (?string $message = null) use ($messages) {
        $messages[] = $message;
    });

    return $messages;
}

describe('validation', function () {
    it('exits 1 for invalid input and sends nothing', function (array $options, string $message) {
        $this->fakeTranslations();

        [$exitCode, $output] = translateCommand($options);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain($message)
            ->and($this->hello->fresh()->existsIn('el'))->toBeFalse();
        $this->assertNoModelCalls();
    })->with([
        'unknown site' => [['--collection' => ['articles'], '--site' => ['fr']], 'Unknown site [fr].'],
        'no site' => [['--collection' => ['articles']], 'Name the sites to translate into with --site.'],
        'nothing named' => [['--site' => ['el']], 'Name what to translate with --collection or --entry.'],
        'unknown collection' => [['--collection' => ['blog'], '--site' => ['el']], 'Collection [blog] not found.'],
        'unknown entry' => [['--entry' => ['missing-id'], '--site' => ['el']], 'Entry [missing-id] not found.'],
        'invalid mode' => [['--collection' => ['articles'], '--site' => ['el'], '--mode' => 'everything'], 'Invalid mode [everything]. Use missing, update, all or review.'],
        'invalid translator' => [['--collection' => ['articles'], '--site' => ['el'], '--translator' => 'mistral:large'], 'Invalid model reference [mistral:large].'],
        'invalid reviewer' => [['--collection' => ['articles'], '--site' => ['el'], '--reviewer' => 'mistral:large'], 'Invalid model reference [mistral:large].'],
        '--no-review with --review' => [['--collection' => ['articles'], '--site' => ['el'], '--review' => true, '--no-review' => true], '--no-review cannot be combined with --review, --reviewer or --mode=review.'],
        '--no-review with --reviewer' => [['--collection' => ['articles'], '--site' => ['el'], '--reviewer' => 'openai:gpt-6.1-sol', '--no-review' => true], '--no-review cannot be combined'],
        '--no-review with mode review' => [['--collection' => ['articles'], '--site' => ['el'], '--mode' => 'review', '--no-review' => true], '--no-review cannot be combined'],
    ]);

    it('fails with a clear message when no provider is configured and the fake engine is off', function () {
        $this->withProviderKeys();

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el']]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('No AI provider is configured.');
    });

    it('fails with a clear message when the catalog has no default model for the provider', function () {
        $this->withProviderKeys(['ai.providers.anthropic.key' => 'test-key']);
        config(['tolk.models.anthropic' => [['id' => 'claude-haiku-4-5', 'tier' => 'fast']]]);

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el']]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('The model catalog (tolk.models) has no quality model for [anthropic].');
    });

    it('fails when the translator\'s provider has no key and the fake engine is off', function () {
        $this->withProviderKeys(['ai.providers.anthropic.key' => 'test-key']);

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--translator' => 'openai:gpt-6-luna']);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('The AI provider [openai] has no API key.')
            ->and($output)->not->toContain('Targets:');
    });

    it('translates with the fake engine when no provider is configured', function () {
        $this->withProviderKeys();
        config(['tolk.fake_engine' => true, 'tolk.fake_delay_ms' => 0]);

        [$exitCode, $output] = translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el']]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('The fake engine is on: nothing is sent to a provider.')
            ->and($output)->toContain('Translator: Claude Sonnet 5.5 (anthropic:claude-sonnet-5-5)')
            ->and($this->hello->fresh()->in('el')->get('title'))->toBe('[el] Hello');
    });
});

describe('dry run', function () {
    it('plans every target and translates nothing', function () {
        $this->fakeTranslations();

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el', 'de'], '--dry-run' => true]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Targets: 4 (Greek, German).')
            ->and($output)->toContain('Hello → Greek: title, intro (2 segments, 19 characters)')
            ->and($output)->toContain('World → German: title, intro (2 segments, 19 characters)')
            ->and($output)->toContain('Dry run: 4 of 4 targets would be translated (8 segments, 76 characters). Nothing was sent or saved.')
            ->and($this->hello->fresh()->existsIn('el'))->toBeFalse()
            ->and(iterator_to_array(app(StateRepository::class)->references(), false))->toBe([]);
        $this->assertNoModelCalls();
    });

    it('shows a target that is current as nothing to do', function () {
        $this->fakeTranslations();
        translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el']]);

        [, $output] = translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el'], '--dry-run' => true]);

        expect($output)->toContain('Hello → Greek: nothing to do')
            ->and($output)->toContain('Dry run: 0 of 1 target would be translated (0 segments, 0 characters).')
            ->and($this->modelCalls())->toBe(1);
    });

    it('shows a target that would fail because its root page is missing, unless the root is planned before it', function () {
        $pages = $this->makePages(['home', 'rooms' => ['suites']]);

        [, $alone] = translateCommand(['--entry' => [$pages['rooms']->id()], '--site' => ['el'], '--dry-run' => true]);
        [, $all] = translateCommand(['--collection' => ['pages'], '--site' => ['el'], '--dry-run' => true]);

        expect($alone)->toContain('Rooms → Greek: would fail, Translate Home into Greek first.')
            ->and($alone)->toContain('Dry run: 0 of 1 target would be translated')
            ->and($all)->not->toContain('would fail')
            ->and($all)->toContain('Rooms → Greek: title (1 segment, 5 characters)')
            ->and($all)->toContain('Dry run: 3 of 3 targets would be translated');
    });
});

describe('translating', function () {
    it('makes every target of a collection current for one site, as drafts', function () {
        $this->fakeTranslations();

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el']]);

        expect($exitCode)->toBe(0)
            ->and(StateFixtures::statuses($this->hello))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current])
            ->and(StateFixtures::statuses($this->world))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current])
            ->and($this->hello->fresh()->in('el')->data()->all())->toBe(['title' => '[T] Hello', 'intro' => '[T] A short intro.'])
            ->and($this->hello->fresh()->in('el')->published())->toBeFalse()
            ->and($this->world->fresh()->in('el')->published())->toBeFalse()
            ->and($this->hello->fresh()->existsIn('de'))->toBeFalse()
            ->and($output)->toContain('Hello → Greek: done, created, 2 fields')
            ->and($output)->toContain('World → Greek: done, created, 2 fields')
            ->and($output)->toMatch('/\| Done \| Skipped \| Failed \| Fields left incomplete \|\n.*\n\| 2 +\| 0 +\| 0 +\| 0 +\|/')
            ->and($this->modelCalls())->toBe(2);
    });

    it('reports nothing to do on a second run', function () {
        $this->fakeTranslations();
        translateCommand(['--collection' => ['articles'], '--site' => ['el']]);

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el']]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Hello → Greek: skipped, nothing to do')
            ->and($output)->toMatch('/\| 0 +\| 2 +\| 0 +\| 0 +\|/')
            ->and($this->modelCalls())->toBe(2);
    });

    it('touches only the edited entry in mode update', function () {
        $sent = [];
        $this->fakeTranslations(function (string $text) use (&$sent) {
            $sent[] = $text;

            return '[T] '.$text;
        });
        translateCommand(['--collection' => ['articles'], '--site' => ['el']]);
        $this->hello->fresh()->set('title', 'Hello again')->save();
        $sent = [];

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--mode' => 'update']);

        expect($exitCode)->toBe(0)
            ->and($sent)->toBe(['Hello again'])
            ->and($this->hello->fresh()->in('el')->get('title'))->toBe('[T] Hello again')
            ->and($output)->toContain('Hello again → Greek: done, 1 field')
            ->and($output)->toContain('World → Greek: skipped, nothing to do')
            ->and(StateFixtures::statuses($this->hello))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current]);
    });

    it('keeps a manual field in mode update and overwrites it with --force', function () {
        $this->fakeTranslations();
        translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el']]);
        $this->hello->fresh()->in('el')->set('title', 'Γεια σου')->save();
        $this->hello->fresh()->set('title', 'Hello again')->save();

        [, $kept] = translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el'], '--mode' => 'update']);
        $afterUpdate = $this->hello->fresh()->in('el')->get('title');
        [, $forced] = translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el'], '--mode' => 'update', '--force' => true]);

        expect($afterUpdate)->toBe('Γεια σου')
            ->and($kept)->toContain('Hello again → Greek: skipped, nothing to do; manual edits kept: title')
            ->and($this->hello->fresh()->in('el')->get('title'))->toBe('[T] Hello again')
            ->and($forced)->toContain('Hello again → Greek: done, 1 field')
            ->and(StateFixtures::statuses($this->hello)['title'])->toBe(FieldStatus::Current);
    });

    it('mirrors the origin\'s published state with --publish', function () {
        $this->fakeTranslations();
        $this->world->published(false)->save();

        translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--publish' => true]);
        translateCommand(['--collection' => ['articles'], '--site' => ['de']]);

        expect($this->hello->fresh()->in('el')->published())->toBeTrue()
            ->and($this->world->fresh()->in('el')->published())->toBeFalse()
            ->and($this->hello->fresh()->in('de')->published())->toBeFalse();
    });

    it('mirrors the origin\'s published state when the setting asks for it', function () {
        $this->fakeTranslations();
        $this->withSettings(['new_localization_status' => 'mirror']);

        translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el']]);

        expect($this->hello->fresh()->in('el')->published())->toBeTrue();
    });

    it('translates with the model of --translator', function () {
        $this->fakeTranslations();
        $models = [];
        Event::listen(TargetTranslated::class, function (TargetTranslated $event) use (&$models) {
            $models = $event->models;
        });

        [, $output] = translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el'], '--translator' => 'openai:gpt-6-luna']);

        expect($models)->toBe(['openai:gpt-6-luna'])
            ->and($output)->toContain('Translator: GPT-6 Luna (openai:gpt-6-luna). Mode: missing.');
    });

    it('asks before it translates, and translates nothing when declined', function () {
        $this->fakeTranslations();

        $this->artisan('tolk:translate', ['--collection' => ['articles'], '--site' => ['el']])
            ->expectsConfirmation('Translate and save them?', 'no')
            ->expectsOutputToContain('Nothing was translated.')
            ->assertExitCode(0);

        expect($this->hello->fresh()->existsIn('el'))->toBeFalse();
        $this->assertNoModelCalls();
    });

    it('translates after the confirmation', function () {
        $this->fakeTranslations();

        $this->artisan('tolk:translate', ['--entry' => [$this->hello->id()], '--site' => ['el']])
            ->expectsConfirmation('Translate and save them?', 'yes')
            ->assertExitCode(0);

        expect($this->hello->fresh()->in('el')->get('title'))->toBe('[T] Hello');
    });
});

describe('enumeration', function () {
    it('translates the family of an entry named by any of its localizations, once, into each site but its own', function () {
        $this->fakeTranslations();
        $german = $this->localize($this->hello, 'de', ['title' => 'Hallo']);

        [$exitCode, $output] = translateCommand([
            '--entry' => [$german->id(), $this->hello->id()],
            '--collection' => ['articles'],
            '--site' => ['en', 'el'],
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Targets: 2 (English, Greek).')
            ->and(substr_count($output, 'Hello → Greek'))->toBe(1)
            ->and($output)->not->toContain('→ English')
            ->and($this->hello->fresh()->in('el')->get('title'))->toBe('[T] Hello')
            ->and($german->fresh()->get('title'))->toBe('Hallo');
    });

    it('translates a structured collection in tree order, so the root page comes first and the tree keeps its shape', function () {
        $this->fakeTranslations();
        $pages = $this->makePages(['home', 'rooms' => ['suites' => ['honeymoon']], 'dining'], creationOrder: ['honeymoon', 'dining', 'suites', 'rooms', 'home']);

        [$exitCode, $output] = translateCommand(['--collection' => ['pages'], '--site' => ['el']]);

        expect($exitCode)->toBe(0)
            ->and($output)->toMatch('/Home → Greek.*\n.*Rooms → Greek.*\n.*Suites → Greek.*\n.*Honeymoon → Greek.*\n.*Dining → Greek/')
            ->and($output)->not->toContain('failed')
            ->and($this->treeOfSlugs('el'))->toBe($this->treeOfSlugs('en'))
            ->and($this->treeOfSlugs('el'))->toBe(['home' => [], 'rooms' => ['suites' => ['honeymoon' => []]], 'dining' => []]);
    });

    it('warns about a collection that is not available in a site', function () {
        $this->makeCollection('notes', ['en'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeEntry('notes', 'en', ['title' => 'A note']);

        [$exitCode, $output] = translateCommand(['--collection' => ['notes'], '--site' => ['el']]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('The collection [notes] is not available in the site [el].')
            ->and($output)->toContain('Nothing to translate.');
    });

    it('skips a collection that the settings exclude', function () {
        $this->fakeTranslations();
        $this->withSettings(['excluded_collections' => ['articles']]);

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--entry' => [$this->hello->id()], '--site' => ['el']]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('The collection [articles] is excluded from translation in the settings.')
            ->and($output)->toContain('Nothing to translate.')
            ->and($this->hello->fresh()->existsIn('el'))->toBeFalse();
    });
});

describe('review', function () {
    it('reviews the translations with --review, applies the corrections and records the reviewer', function () {
        $this->fakeTranslations();
        $this->fakeReview(fn (string $source) => $source === 'A short intro.' ? 'Μια σύντομη εισαγωγή.' : null);

        [$exitCode, $output] = translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el'], '--review' => true]);

        $state = StateFixtures::stored($this->hello->fresh()->in('el'));
        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Translator: Claude Sonnet 5.5 (anthropic:claude-sonnet-5-5). Reviewer: GPT-6.1 Sol (openai:gpt-6.1-sol). Mode: missing.')
            ->and($output)->toContain('Hello → Greek: done, created, 2 fields, 1 correction')
            ->and($output)->toMatch('/\| Done \| Skipped \| Failed \| Fields left incomplete \| Corrections \|\n.*\n\| 1 +\| 0 +\| 0 +\| 0 +\| 1 +\|/')
            ->and($output)->toContain('Run the command with -v to see each correction and its reason.')
            ->and($this->hello->fresh()->in('el')->data()->all())->toBe(['title' => '[T] Hello', 'intro' => 'Μια σύντομη εισαγωγή.'])
            ->and($state->field('title')->reviewer)->toBe('openai:gpt-6.1-sol')
            ->and($state->field('intro')->reviewer)->toBe('openai:gpt-6.1-sol')
            ->and(StateFixtures::statuses($this->hello))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current])
            ->and($this->modelCalls())->toBe(2);
    });

    it('reviews when the settings turn review on, and --no-review overrides them', function () {
        $this->fakeTranslations();
        $reviewed = [];
        $this->fakeReview(function (string $source) use (&$reviewed) {
            $reviewed[] = $source;

            return null;
        });
        $this->withSettings(['review_enabled' => true]);

        [, $notReviewed] = translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el'], '--no-review' => true]);
        $reviewedFirst = $reviewed;
        [, $output] = translateCommand(['--entry' => [$this->world->id()], '--site' => ['el']]);

        expect($reviewedFirst)->toBe([])
            ->and($notReviewed)->not->toContain('Reviewer:')
            ->and($notReviewed)->toMatch('/Hello → Greek: done, created, 2 fields$/m')
            ->and($notReviewed)->not->toContain('Corrections')
            ->and(StateFixtures::stored($this->hello->fresh()->in('el'))->field('title')->reviewer)->toBeNull()
            ->and($reviewed)->toBe(['World', 'Another intro.'])
            ->and($output)->toContain('Reviewer: GPT-6.1 Sol (openai:gpt-6.1-sol).')
            ->and($output)->toContain('World → Greek: done, created, 2 fields, no corrections')
            ->and($output)->not->toContain('Run the command with -v')
            ->and(StateFixtures::stored($this->world->fresh()->in('el'))->field('title')->reviewer)->toBe('openai:gpt-6.1-sol');
    });

    it('reviews with the model of --reviewer, which asks for a review', function () {
        $this->fakeTranslations();
        $this->fakeReview();

        [, $output] = translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el'], '--reviewer' => 'gemini:gemini-3.8-flash']);

        expect($output)->toContain('Reviewer: Gemini 3.8 Flash (gemini:gemini-3.8-flash).')
            ->and(StateFixtures::stored($this->hello->fresh()->in('el'))->field('intro')->reviewer)->toBe('gemini:gemini-3.8-flash');
    });

    it('lists each correction with its reason in verbose output', function () {
        $this->fakeTranslations();
        $this->fakeReview(fn (string $source) => $source === 'Hello' ? ['text' => 'Γεια σας', 'reason' => 'Use the polite form.'] : null);

        [, $output] = translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el'], '--review' => true, '-v' => true]);

        expect($output)->toContain('    title: "[T] Hello" → "Γεια σας" (Use the polite form.)')
            ->and($output)->not->toContain('Run the command with -v');
    });

    it('fails before any call when a reviewer\'s provider has no key', function (array $options, array $settings) {
        $this->withProviderKeys(['ai.providers.anthropic.key' => 'test-key']);
        $this->withSettings($settings);

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el'], ...$options]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('The AI provider [openai] has no API key.')
            ->and($output)->not->toContain('Targets:');
        $this->assertNoModelCalls();
    })->with([
        '--reviewer' => [['--reviewer' => 'openai:gpt-6.1-sol'], []],
        'the settings\' reviewer' => [['--review' => true], ['reviewer_model' => 'openai:gpt-6.1-sol']],
        'mode review' => [['--mode' => 'review'], ['reviewer_model' => 'openai:gpt-6.1-sol']],
    ]);

    it('checks only the reviewer\'s provider in mode review', function () {
        $this->withProviderKeys(['ai.providers.anthropic.key' => 'test-key']);
        $this->withSettings(['translator_model' => 'openai:gpt-6-luna']);

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--mode' => 'review']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Targets: 2 (Greek). Reviewer: Claude Sonnet 5.5 (anthropic:claude-sonnet-5-5). Mode: review.')
            ->and($output)->not->toContain('Translator:')
            ->and($output)->toContain('Hello → Greek: skipped, nothing to do');
        $this->assertNoModelCalls();
    });

    it('reviews existing machine translations in mode review and saves only the corrected fields', function () {
        $translated = [];
        $this->fakeTranslations(function (string $text) use (&$translated) {
            $translated[] = $text;

            return '[T] '.$text;
        });
        translateCommand(['--collection' => ['articles'], '--site' => ['el']]);
        $translated = [];
        $reviewed = [];
        $this->fakeReview(function (string $source, string $translation) use (&$reviewed) {
            $reviewed[] = $translation;

            return $translation === '[T] A short intro.' ? ['text' => 'Μια σύντομη εισαγωγή.', 'reason' => 'The intro was left in English.'] : null;
        });
        $saved = [];
        Event::listen(TargetTranslated::class, function (TargetTranslated $event) use (&$saved) {
            $saved[] = $event->ref->id;
        });

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--mode' => 'review']);

        $hello = $this->hello->fresh()->in('el');
        $state = StateFixtures::stored($hello);
        expect($exitCode)->toBe(0)
            ->and($translated)->toBe([])
            ->and($reviewed)->toBe(['[T] Hello', '[T] A short intro.', '[T] World', '[T] Another intro.'])
            ->and($output)->toContain('Targets: 2 (Greek). Reviewer: GPT-6.1 Sol (openai:gpt-6.1-sol). Mode: review.')
            ->and($output)->toContain('Hello → Greek: done, 1 field, 1 correction')
            ->and($output)->toContain('World → Greek: reviewed, nothing to correct')
            ->and($output)->toMatch('/\| 2 +\| 0 +\| 0 +\| 0 +\| 1 +\|/')
            ->and($hello->data()->all())->toBe(['title' => '[T] Hello', 'intro' => 'Μια σύντομη εισαγωγή.'])
            ->and($this->world->fresh()->in('el')->data()->all())->toBe(['title' => '[T] World', 'intro' => '[T] Another intro.'])
            ->and($saved)->toBe([$hello->id()])
            ->and($state->field('intro')->reviewer)->toBe('openai:gpt-6.1-sol')
            ->and($state->field('title')->reviewer)->toBeNull()
            ->and(StateFixtures::statuses($this->hello))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current]);
    });

    it('reviews manual fields in mode review only with --force, and they stay manual', function () {
        $this->fakeTranslations();
        translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el']]);
        $this->hello->fresh()->in('el')->set('title', 'Γεια σου')->save();
        $this->fakeReview(fn (string $source, string $translation) => $translation === 'Γεια σου' ? 'Γεια σας' : null);

        [, $kept] = translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el'], '--mode' => 'review']);
        $afterKept = $this->hello->fresh()->in('el')->get('title');
        [, $forced] = translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el'], '--mode' => 'review', '--force' => true]);

        expect($afterKept)->toBe('Γεια σου')
            ->and($kept)->toContain('Hello → Greek: reviewed, nothing to correct; manual edits kept: title')
            ->and($forced)->toContain('Hello → Greek: done, 1 field, 1 correction')
            ->and($this->hello->fresh()->in('el')->get('title'))->toBe('Γεια σας')
            ->and(StateFixtures::statuses($this->hello))->toBe(['title' => FieldStatus::Manual, 'intro' => FieldStatus::Current]);
    });

    it('plans a review in a dry run without calling the model', function () {
        $this->fakeTranslations();
        translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el']]);
        $calls = $this->modelCalls();

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--mode' => 'review', '--dry-run' => true]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Hello → Greek: title, intro (2 segments, 19 characters)')
            ->and($output)->toContain('World → Greek: nothing to do')
            ->and($output)->toContain('Dry run: 1 of 2 targets would be reviewed (2 segments, 19 characters). Nothing was sent or saved.')
            ->and($this->modelCalls())->toBe($calls);
    });

    it('does not report a missing root page in a dry run of a review, which creates nothing', function () {
        $pages = $this->makePages(['home', 'rooms']);

        [, $output] = translateCommand(['--entry' => [$pages['rooms']->id()], '--site' => ['el'], '--mode' => 'review', '--dry-run' => true]);

        expect($output)->toContain('Rooms → Greek: nothing to do')
            ->and($output)->not->toContain('would fail');
    });

    it('names the review in a dry run of a translation with --review', function () {
        [, $output] = translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el'], '--review' => true, '--dry-run' => true]);

        expect($output)->toContain('Dry run: 1 of 1 target would be translated and reviewed (2 segments, 19 characters).');
        $this->assertNoModelCalls();
    });

    it('asks before it reviews, and reviews nothing when declined', function () {
        $this->fakeReview();

        $this->artisan('tolk:translate', ['--collection' => ['articles'], '--site' => ['el'], '--mode' => 'review'])
            ->expectsConfirmation('Review them and save the corrections?', 'no')
            ->expectsOutputToContain('Nothing was reviewed.')
            ->assertExitCode(0);

        $this->assertNoModelCalls();
    });
});

describe('failures', function () {
    it('fails a target whose root page is missing before calling the model, and exits 1', function () {
        $this->fakeTranslations();
        $pages = $this->makePages(['home', 'rooms']);

        [$exitCode, $output] = translateCommand(['--entry' => [$pages['rooms']->id()], '--site' => ['el']]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Rooms → Greek: failed, Translate Home into Greek first.')
            ->and($output)->toMatch('/\| 0 +\| 0 +\| 1 +\| 0 +\|/')
            ->and($pages['rooms']->fresh()->existsIn('el'))->toBeFalse();
        $this->assertNoModelCalls();
    });

    it('stops at ProviderRejected with its message and exits 1', function () {
        $engine = ScriptedEngine::bind(fn (EngineCall $call) => throw ProviderRejected::from(
            $call->model,
            new ProviderError(ProviderErrorKind::Rejected, 'Your credit balance is too low.', 400, ProviderError::INSUFFICIENT_CREDITS),
        ));

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el']]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('The AI provider [anthropic] rejected the request')
            ->and($output)->toContain('Your credit balance is too low.')
            ->and($output)->not->toContain('World → Greek')
            ->and(count($engine->calls))->toBe(1)
            ->and($this->hello->fresh()->existsIn('el'))->toBeFalse();
    });

    it('counts fields left incomplete and names them', function () {
        ScriptedEngine::bind(fn (EngineCall $call) => in_array('A short intro.', array_column($call->segments, 'text'), true)
            ? ScriptedEngine::result($call, Finish::Refused)
            : ScriptedEngine::translated($call));

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el']]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Hello → Greek: done, created, 2 fields; left incomplete: intro')
            ->and($output)->toMatch('/\| 2 +\| 0 +\| 0 +\| 1 +\|/')
            ->and($output)->toMatch('/\| Hello → Greek +\| intro +\|/');
    });
});

describe('summary and git', function () {
    it('lists the fields that are not translated, with the reason, and leaves the slug out', function () {
        $this->fakeTranslations();

        [, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el']]);

        expect($output)->toContain('These fields are not translated:')
            ->and($output)->toMatch('/\| articles\.note +\| text +\| not localizable +\|/')
            ->and($output)->toMatch('/\| articles\.tags +\| taggable +\| unsupported fieldtype +\|/')
            ->and(substr_count($output, 'articles.note'))->toBe(1)
            ->and($output)->not->toContain('articles.slug')
            ->and($output)->toContain('Make it localizable in its blueprint to translate it.');
    });

    it('commits once after the loop when a target was saved, and not when nothing was', function () {
        $this->fakeTranslations();
        $commits = commandCommits();

        translateCommand(['--collection' => ['articles'], '--site' => ['el']]);
        $first = $commits->getArrayCopy();
        translateCommand(['--collection' => ['articles'], '--site' => ['el']]);

        expect($first)->toBe([ContentWriter::COMMIT_MESSAGE])
            ->and($commits->getArrayCopy())->toBe([ContentWriter::COMMIT_MESSAGE]);
    });

    it('does not commit a review that corrected nothing', function () {
        $this->fakeTranslations();
        $this->fakeReview();
        translateCommand(['--collection' => ['articles'], '--site' => ['el']]);
        $commits = commandCommits();

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--mode' => 'review']);

        expect($exitCode)->toBe(0)
            ->and($output)->toMatch('/\| 2 +\| 0 +\| 0 +\| 0 +\| 0 +\|/')
            ->and($commits->getArrayCopy())->toBe([]);
    });

    it('commits what was saved before ProviderRejected stopped the loop', function () {
        $commits = commandCommits();
        ScriptedEngine::bind(fn (EngineCall $call, int $number) => $number === 1
            ? ScriptedEngine::translated($call)
            : throw ProviderRejected::from($call->model, new ProviderError(ProviderErrorKind::Rejected, 'Invalid API key.', 401)));

        [$exitCode] = translateCommand(['--collection' => ['articles'], '--site' => ['el']]);

        expect($exitCode)->toBe(1)
            ->and($commits->getArrayCopy())->toBe([ContentWriter::COMMIT_MESSAGE]);
    });
});
