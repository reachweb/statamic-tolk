<?php

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval as Duration;
use Illuminate\Console\Signals;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Ai\ProviderError;
use Reach\StatamicTolk\Console\Commands\TranslateCommand;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\Finish;
use Reach\StatamicTolk\Enums\ProviderErrorKind;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\RunStatus;
use Reach\StatamicTolk\Events\RunStarted;
use Reach\StatamicTolk\Events\TargetTranslated;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\Jobs\WorkRun;
use Reach\StatamicTolk\Runs\RunProcessor;
use Reach\StatamicTolk\Runs\UsageLog;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Tests\Support\RunFixtures;
use Reach\StatamicTolk\Tests\Support\ScriptedEngine;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Tests\Support\TerminalTranslate;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Term;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process as SymfonyProcess;

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
 * Runs the command without prompts, in this process, and returns its exit code and output.
 *
 * @return array{0: int, 1: string}
 */
function translateCommand(array $options): array
{
    $exitCode = Artisan::call('tolk:translate', ['--yes' => true, '--concurrency' => 1, ...$options]);

    return [$exitCode, Artisan::output()];
}

describe('validation', function () {
    it('exits 1 for invalid input and sends nothing', function (array $options, string $message) {
        $this->fakeTranslations();

        [$exitCode, $output] = translateCommand($options);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain($message)
            ->and($this->hello->fresh()->existsIn('el'))->toBeFalse()
            ->and(app(RunRepository::class)->recent(10))->toBe([]);
        $this->assertNoModelCalls();
    })->with([
        'unknown site' => [['--collection' => ['articles'], '--site' => ['fr']], 'Unknown site [fr].'],
        'no site' => [['--collection' => ['articles']], 'Name the sites to translate into with --site.'],
        'nothing named' => [['--site' => ['el']], 'Name what to translate with --collection, --entry, --taxonomy, --globals, --nav or --all.'],
        '--all with --collection' => [['--all' => true, '--collection' => ['articles'], '--site' => ['el']], '--all cannot be combined with --collection, --entry, --taxonomy, --globals or --nav.'],
        '--all with --taxonomy' => [['--all' => true, '--taxonomy' => ['topics'], '--site' => ['el']], '--all cannot be combined with --collection, --entry, --taxonomy, --globals or --nav.'],
        '--all with --globals' => [['--all' => true, '--globals' => ['site_settings'], '--site' => ['el']], '--all cannot be combined with --collection, --entry, --taxonomy, --globals or --nav.'],
        '--all with --nav' => [['--all' => true, '--nav' => ['main'], '--site' => ['el']], '--all cannot be combined with --collection, --entry, --taxonomy, --globals or --nav.'],
        'unknown taxonomy' => [['--taxonomy' => ['colours'], '--site' => ['el']], 'Taxonomy [colours] not found.'],
        'unknown global set' => [['--globals' => ['colours'], '--site' => ['el']], 'Global set [colours] not found.'],
        'unknown navigation' => [['--nav' => ['footer'], '--site' => ['el']], 'Navigation [footer] not found.'],
        'unknown source site' => [['--collection' => ['articles'], '--site' => ['el'], '--from' => 'fr'], 'Unknown site [fr].'],
        'the source site as the only site' => [['--nav' => ['main'], '--site' => ['el'], '--from' => 'el'], '--from [el] is the site to translate from, so it cannot be the only --site.'],
        '--queue with --concurrency' => [['--collection' => ['articles'], '--site' => ['el'], '--queue' => true], '--concurrency cannot be combined with --queue'],
        'invalid concurrency' => [['--collection' => ['articles'], '--site' => ['el'], '--concurrency' => '0'], 'Invalid concurrency [0]. Use a whole number of at least 1.'],
        '--queue on a connection without a worker' => [['--collection' => ['articles'], '--site' => ['el'], '--queue' => true, '--concurrency' => null], 'The queue connection [sync] has no worker, so --queue would run the whole run inside this command.'],
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
        config(['tolk.models.anthropic' => [['id' => 'claude-sonnet-5-5', 'tier' => 'fast']]]);

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

    it('shows a target that would be skipped because its root page is missing, as the run skips it, unless the root is planned before it', function () {
        $pages = $this->makePages(['home', 'rooms' => ['suites']]);

        [, $alone] = translateCommand(['--entry' => [$pages['rooms']->id()], '--site' => ['el'], '--dry-run' => true]);
        [, $all] = translateCommand(['--collection' => ['pages'], '--site' => ['el'], '--dry-run' => true]);

        expect($alone)->toContain('Rooms → Greek: would be skipped, Translate Home into Greek first.')
            ->and($alone)->not->toContain('would fail')
            ->and($alone)->toContain('Dry run: 0 of 1 target would be translated')
            ->and($all)->not->toContain('would be skipped')
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

    it('keeps the ids of the rows and sets of a localization translated again from an origin without ids', function () {
        $this->fakeTranslations();
        $this->makeCollection('stories', ['en', 'el'], [
            'blocks' => ['type' => 'replicator', 'localizable' => true, 'sets' => ['main' => ['sets' => [
                'text' => ['display' => 'Text', 'fields' => [['handle' => 'heading', 'field' => ['type' => 'text']]]],
            ]]]],
            'faq' => ['type' => 'grid', 'localizable' => true, 'fields' => [['handle' => 'question', 'field' => ['type' => 'text']]]],
            'body' => ['type' => 'bard', 'localizable' => true, 'sets' => ['main' => ['sets' => [
                'quote' => ['display' => 'Quote', 'fields' => [['handle' => 'text', 'field' => ['type' => 'textarea']]]],
            ]]]],
        ]);
        // Seeded as code or an importer writes content: no row or set ids.
        $story = $this->makeEntry('stories', 'en', [
            'blocks' => [['type' => 'text', 'heading' => 'Rooms'], ['type' => 'text', 'heading' => 'Dining']],
            'faq' => [['question' => 'When is breakfast?']],
            'body' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Welcome.']]],
                ['type' => 'set', 'attrs' => ['values' => ['type' => 'quote', 'text' => 'A fine stay.']]],
            ],
        ], 'story');
        $ids = function () use ($story) {
            $greek = $story->fresh()->in('el');

            return [...array_column($greek->get('blocks'), 'id'), ...array_column($greek->get('faq'), 'id'), $greek->get('body')[1]['attrs']['id']];
        };

        translateCommand(['--entry' => [$story->id()], '--site' => ['el']]);
        $first = $ids();
        [$exitCode] = translateCommand(['--entry' => [$story->id()], '--site' => ['el'], '--mode' => 'all']);

        expect($exitCode)->toBe(0)
            ->and($story->fresh()->in('el')->get('blocks')[0]['heading'])->toBe('[T] Rooms')
            ->and($first)->toHaveCount(4)->each->toBeString()
            ->and($ids())->toBe($first);
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

    it('reports the targets in a site that their collection does not have, and creates no run without targets', function () {
        $this->makeCollection('notes', ['en'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeEntry('notes', 'en', ['title' => 'A note']);
        $this->makeEntry('notes', 'en', ['title' => 'Another note']);

        [$exitCode, $output] = translateCommand(['--collection' => ['notes'], '--site' => ['el']]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('2 targets are in sites their collection does not have.')
            ->and($output)->toContain('Nothing to translate.')
            ->and(app(RunRepository::class)->recent(10))->toBe([]);
    });

    it('skips a collection that the settings exclude', function () {
        $this->fakeTranslations();
        $this->withSettings(['excluded_collections' => ['articles']]);

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--entry' => [$this->hello->id()], '--site' => ['el']]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('2 targets are in collections excluded in the settings.')
            ->and($output)->toContain('Nothing to translate.')
            ->and($this->hello->fresh()->existsIn('el'))->toBeFalse();
    });
});

describe('terms', function () {
    beforeEach(function () {
        $this->makeTaxonomy('topics', ['en', 'el', 'de'], [
            'title' => ['type' => 'text', 'localizable' => true],
            'description' => ['type' => 'textarea', 'localizable' => true],
        ]);
        $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food', 'description' => 'Eat well.']]);
        $this->makeTerm('topics', 'wellness', ['en' => ['title' => 'Wellness']]);
    });

    it('translates every term of a taxonomy with --taxonomy, after the entries, under each site', function () {
        $this->fakeTranslations();

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--taxonomy' => ['topics'], '--site' => ['el', 'de']]);
        $food = Term::find('topics::food');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Targets: 8 (Greek, German).')
            ->and($output)->toContain('Terms → Greek')
            ->and($output)->toMatch('/World → German.*\n(.*\n)*.*Food → Greek/')
            ->and($output)->not->toContain('failed')
            ->and($food->in('el')->data()->all())->toBe(['title' => '[T] Food', 'description' => '[T] Eat well.'])
            ->and($food->in('de')->get('title'))->toBe('[T] Food')
            ->and($food->in('en')->data()->all())->toBe(['title' => 'Food', 'description' => 'Eat well.'])
            ->and(Term::find('topics::wellness')->in('el')->data()->all())->toBe(['title' => '[T] Wellness']);

        $calls = $this->modelCalls();
        [$exitCode, $output] = translateCommand(['--taxonomy' => ['topics'], '--site' => ['el', 'de']]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Food → Greek: skipped, nothing to do')
            ->and($this->modelCalls())->toBe($calls);
    });

    it('skips a taxonomy that the settings exclude', function () {
        $this->fakeTranslations();
        $this->withSettings(['excluded_taxonomies' => ['topics']]);

        [$exitCode, $output] = translateCommand(['--taxonomy' => ['topics'], '--site' => ['el']]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('2 targets are in taxonomies excluded in the settings.')
            ->and($output)->toContain('Nothing to translate.')
            ->and(Term::find('topics::food')->in('el')->data()->all())->toBe([]);
    });
});

describe('global sets', function () {
    beforeEach(function () {
        $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en', 'de' => 'en'], [
            'tagline' => ['type' => 'text', 'localizable' => true],
            'footer' => ['type' => 'textarea', 'localizable' => true],
            'phone' => ['type' => 'text'],
        ], ['en' => ['tagline' => 'By the sea', 'footer' => 'Open all year.', 'phone' => '+30 1234']]);
    });

    it('translates a global set with --globals, after the entries, under each site', function () {
        $this->fakeTranslations();

        [$exitCode, $output] = translateCommand(['--entry' => [$this->hello->id()], '--globals' => ['site_settings'], '--site' => ['el', 'de']]);
        $set = GlobalSet::findByHandle('site_settings');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Targets: 4 (Greek, German).')
            ->and($output)->toContain('Global sets → Greek')
            ->and($output)->toMatch('/Hello → German.*\n(.*\n)*.*Site Settings → Greek/')
            ->and($output)->not->toContain('failed')
            ->and($set->in('el')->data()->all())->toBe(['tagline' => '[T] By the sea', 'footer' => '[T] Open all year.'])
            ->and($set->in('de')->get('tagline'))->toBe('[T] By the sea')
            ->and($set->in('en')->data()->all())->toBe(['tagline' => 'By the sea', 'footer' => 'Open all year.', 'phone' => '+30 1234']);

        $calls = $this->modelCalls();
        [$exitCode, $output] = translateCommand(['--globals' => ['site_settings'], '--site' => ['el']]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Site Settings → Greek: skipped, nothing to do')
            ->and($this->modelCalls())->toBe($calls);
    });

    it('reports a site the set has no origin for and translates nothing there', function () {
        $this->fakeTranslations();
        GlobalSet::findByHandle('site_settings')->sites(['en' => null, 'el' => 'en', 'de' => null])->save();

        [$exitCode, $output] = translateCommand(['--globals' => ['site_settings'], '--site' => ['de']]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('1 target is in a site its global set has no origin for.')
            ->and($output)->toContain('Nothing to translate.')
            ->and(GlobalSet::findByHandle('site_settings')->in('de')->data()->all())->toBe([]);
        $this->assertNoModelCalls();
    });
});

describe('navigation', function () {
    beforeEach(function () {
        $this->makeNav('main', ['en' => [
            ['id' => 'hello', 'entry' => $this->hello->id()],
            ['id' => 'world', 'entry' => $this->world->id(), 'title' => 'The world'],
            ['id' => 'book', 'title' => 'Book your stay', 'url' => 'https://booking.example'],
        ]]);
    });

    it('translates a navigation with --nav after the entries, with the localizations they created', function () {
        $this->fakeTranslations();

        [$exitCode, $output] = translateCommand(['--entry' => [$this->hello->id()], '--nav' => ['main'], '--site' => ['el']]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Targets: 2 (Greek).')
            ->and($output)->toContain('Navigation → Greek')
            ->and($output)->toMatch('/Hello → Greek.*\n(.*\n)*.*Main → Greek/')
            ->and($output)->toContain('The navigation item "The world" was left out: its entry has no localization in Greek.')
            ->and($output)->not->toContain('failed')
            ->and(Nav::findByHandle('main')->in('el')->tree())->toBe([
                ['id' => 'hello', 'entry' => $this->hello->fresh()->in('el')->id()],
                ['id' => 'book', 'title' => '[T] Book your stay', 'url' => 'https://booking.example'],
            ]);
    });

    it('reports a navigation without a tree in the site it is translated from', function () {
        $this->fakeTranslations();
        $this->makeNav('footer', ['de' => [['id' => 'legal', 'title' => 'Impressum']]]);

        [$exitCode, $output] = translateCommand(['--nav' => ['footer'], '--site' => ['el']]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('1 target is in a navigation without a tree in the site it is translated from.')
            ->and($output)->toContain('Nothing to translate.')
            ->and(Nav::findByHandle('footer')->in('el'))->toBeNull();
        $this->assertNoModelCalls();

        [$exitCode, $output] = translateCommand(['--nav' => ['footer'], '--site' => ['el'], '--from' => 'de']);

        expect($exitCode)->toBe(0)
            ->and(Nav::findByHandle('footer')->in('el')->tree())->toBe([['id' => 'legal', 'title' => '[T] Impressum']]);
    });

    it('reports the navigation of the --from site, which the other sites are translated from', function () {
        $this->fakeTranslations();
        $this->makeNav('footer', ['en' => [['id' => 'legal', 'title' => 'Legal']], 'de' => [['id' => 'legal', 'title' => 'Impressum']]]);

        [$exitCode, $output] = translateCommand(['--nav' => ['footer'], '--site' => ['el', 'de'], '--from' => 'de', '--mode' => 'all']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('1 target is a navigation in the site it is translated from (--from).')
            ->and(Nav::findByHandle('footer')->in('el')->tree())->toBe([['id' => 'legal', 'title' => '[T] Impressum']])
            ->and(Nav::findByHandle('footer')->in('de')->tree())->toBe([['id' => 'legal', 'title' => 'Impressum']]);
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
            ->and($output)->not->toContain('would be skipped');
    });

    it('names the review in a dry run of a translation with --review', function () {
        [, $output] = translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el'], '--review' => true, '--dry-run' => true]);

        expect($output)->toContain('Dry run: 1 of 1 target would be translated and reviewed (2 segments, 19 characters).');
        $this->assertNoModelCalls();
    });

    it('asks before it reviews, and reviews nothing when declined', function () {
        $this->fakeTranslations();
        translateCommand(['--collection' => ['articles'], '--site' => ['el']]);
        $this->fakeReview();
        $calls = $this->modelCalls();

        $this->artisan('tolk:translate', ['--collection' => ['articles'], '--site' => ['el'], '--mode' => 'review', '--concurrency' => 1])
            ->expectsConfirmation('Review them and save the corrections?', 'no')
            ->expectsOutputToContain('Nothing was reviewed.')
            ->assertExitCode(0);

        expect($this->modelCalls())->toBe($calls);
    });

    it('does not ask when the estimate finds nothing to review', function () {
        $this->fakeReview();

        $this->artisan('tolk:translate', ['--collection' => ['articles'], '--site' => ['el'], '--mode' => 'review', '--concurrency' => 1])
            ->expectsOutputToContain('nothing to do')
            ->assertExitCode(0);

        $this->assertNoModelCalls();
    });
});

describe('failures', function () {
    it('skips a target whose root page is missing before calling the model, with the reason', function () {
        $this->fakeTranslations();
        $pages = $this->makePages(['home', 'rooms']);

        [$exitCode, $output] = translateCommand(['--entry' => [$pages['rooms']->id()], '--site' => ['el']]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Rooms → Greek: skipped, Translate Home into Greek first.')
            ->and($output)->toMatch('/\| 0 +\| 1 +\| 0 +\| 0 +\|/')
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
    it('lists the text fields that are not translated, with the reason and the doctor hint', function () {
        $this->fakeTranslations();

        [, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el']]);

        expect($output)->toContain('These fields are not translated:')
            ->and($output)->toMatch('/\| articles\.note +\| text +\| not localizable +\|/')
            ->and(substr_count($output, 'articles.note'))->toBe(1)
            ->and($output)->not->toContain('articles.tags')
            ->and($output)->not->toContain('articles.slug')
            ->and($output)->toContain('To translate it, make it localizable in its blueprint: php please tolk:doctor --fix-localizable does that.');
    });

    it('commits once after the loop when a target was saved, and not when nothing was', function () {
        $this->fakeTranslations();
        $commits = RunFixtures::commits();

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
        $commits = RunFixtures::commits();

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--mode' => 'review']);

        expect($exitCode)->toBe(0)
            ->and($output)->toMatch('/\| 2 +\| 0 +\| 0 +\| 0 +\| 0 +\|/')
            ->and($commits->getArrayCopy())->toBe([]);
    });

    it('commits what was saved before ProviderRejected aborted the run', function () {
        $commits = RunFixtures::commits();
        ScriptedEngine::bind(fn (EngineCall $call, int $number) => $number === 1
            ? ScriptedEngine::translated($call)
            : throw ProviderRejected::from($call->model, new ProviderError(ProviderErrorKind::Rejected, 'Invalid API key.', 401)));

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el']]);

        $run = app(RunRepository::class)->recent(1)[0];
        expect($exitCode)->toBe(1)
            ->and($commits->getArrayCopy())->toBe([ContentWriter::COMMIT_MESSAGE])
            ->and($this->hello->fresh()->in('el')->get('title'))->toBe('[T] Hello')
            ->and($run->status)->toBe(RunStatus::Aborted)
            ->and($run->error)->toContain('Invalid API key.')
            ->and($output)->toContain('Hello → Greek: done, created, 2 fields')
            ->and($output)->toMatch('/(?<!Scanning) 1\/2 \[/')
            ->and($output)->not->toMatch('/(?<!Scanning) 2\/2 \[/')
            ->and($output)->toContain('1 target was not translated. Resume the run in the Control Panel once the cause above is fixed:')
            ->and($output)->not->toContain('Run the command again')
            ->and($output)->toContain("\n  ".cp_route('tolk.runs.show', $run->id)."\n");
    });
});

describe('the run engine', function () {
    it('translates through a CLI run, which stays as completed with its items', function () {
        $this->fakeTranslations();
        $started = [];
        Event::listen(RunStarted::class, function (RunStarted $event) use (&$started) {
            $started[] = $event->driver;
        });

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el', 'de']]);

        $runs = app(RunRepository::class)->recent(10);
        expect($exitCode)->toBe(0)
            ->and($runs)->toHaveCount(1)
            ->and($runs[0]->driver)->toBe(RunDriver::Cli)
            ->and($runs[0]->status)->toBe(RunStatus::Completed)
            ->and($runs[0]->options['mode'])->toBe('missing')
            ->and($started)->toBe([RunDriver::Cli])
            ->and(RunFixtures::statuses($runs[0]))->toBe(['done', 'done', 'done', 'done'])
            ->and($output)->toContain('Scanning 4/4')
            ->and($output)->toMatch('/Entries → Greek \.+ 2 to translate/')
            ->and($output)->toMatch('/Entries → German \.+ 2 to translate/')
            ->and($output)->toMatch('/Segments \.+ 8 \(76 characters\)/')
            ->and($output)->toMatch('/Estimated tokens \.+ [\d,]+ input, [\d,]+ output/')
            ->and($output)->toMatch('/Estimated cost \.+ \$0\.\d+/')
            ->and($output)->not->toContain('No worker process could be started')
            ->and($this->world->fresh()->in('de')->get('title'))->toBe('[T] World');
    });

    it('records its calls under the command line in the month\'s usage', function () {
        $engine = ScriptedEngine::bind(fn (EngineCall $call) => ScriptedEngine::translated($call));

        [$exitCode] = translateCommand(['--collection' => ['articles'], '--site' => ['el']]);

        expect($exitCode)->toBe(0)
            ->and(array_keys(app(UsageLog::class)->month()[(string) $engine->calls[0]->model]['sources']))->toBe(['cli']);
    });

    it('leaves no run behind after a dry run, and prints the estimate', function () {
        $this->fakeTranslations();

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--dry-run' => true]);

        expect($exitCode)->toBe(0)
            ->and($output)->toMatch('/Segments \.+ 4 \(38 characters\)/')
            ->and($output)->toMatch('/Estimated cost \.+ \$0\.00[1-9]\d  \n/')
            ->and($output)->toContain('Dry run: 2 of 2 targets would be translated (4 segments, 38 characters).')
            ->and(app(RunRepository::class)->recent(10))->toBe([]);
        $this->assertNoModelCalls();
    });

    it('says that the cost is unknown when a model has no price', function () {
        [, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--translator' => 'anthropic:claude-unpriced', '--dry-run' => true]);

        expect($output)->toMatch('/Estimated cost \.+ unknown, a model has no price/');
    });

    it('reports a run with nothing to do without keeping it, and asks nothing', function () {
        $this->fakeTranslations();
        translateCommand(['--collection' => ['articles'], '--site' => ['el']]);

        $this->artisan('tolk:translate', ['--collection' => ['articles'], '--site' => ['el'], '--concurrency' => 1])
            ->expectsOutputToContain('nothing to do')
            ->assertExitCode(0);

        expect(app(RunRepository::class)->recent(10))->toHaveCount(1);
    });

    it('leaves no run behind when the confirmation is declined', function () {
        $this->fakeTranslations();

        $this->artisan('tolk:translate', ['--collection' => ['articles'], '--site' => ['el'], '--concurrency' => 1])
            ->expectsConfirmation('Translate and save them?', 'no')
            ->assertExitCode(0);

        expect(app(RunRepository::class)->recent(10))->toBe([]);
    });

    it('starts without an estimate with --no-estimate', function () {
        $this->fakeTranslations();

        [, $dryRun] = translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--no-estimate' => true, '--dry-run' => true]);
        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--no-estimate' => true]);

        expect($dryRun)->toContain('Dry run: 2 targets, not estimated. Nothing was sent or saved.')
            ->and($dryRun)->not->toContain('Scanning')
            ->and($exitCode)->toBe(0)
            ->and($output)->not->toContain('Scanning')
            ->and($output)->not->toContain('Estimated')
            ->and($output)->toMatch('/Entries → Greek \.+ 2 to translate/')
            ->and($this->hello->fresh()->in('el')->get('title'))->toBe('[T] Hello');
    });

    it('hands the run to the queue with --queue and prints its address', function () {
        config(['queue.default' => 'database', 'tolk.runs.execution' => 'browser']);
        Queue::fake();
        $this->fakeTranslations();

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--queue' => true, '--concurrency' => null]);

        $run = app(RunRepository::class)->recent(1)[0];
        expect($exitCode)->toBe(0)
            ->and($run->driver)->toBe(RunDriver::Queue)
            ->and($run->status)->toBe(RunStatus::Running)
            ->and($output)->toContain("The run {$run->id} is on the queue [database].")
            ->and($output)->toContain('Follow it in the Control Panel: '.cp_route('tolk.runs.show', $run->id))
            ->and($this->hello->fresh()->existsIn('el'))->toBeFalse();
        Queue::assertPushed(WorkRun::class, config('tolk.concurrency.queue'));
        $this->assertNoModelCalls();
    });

    it('translates every collection, taxonomy and global set with more than one site, and every navigation, with --all', function () {
        $this->fakeTranslations();
        $this->makeCollection('news', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $news = $this->makeEntry('news', 'en', ['title' => 'News']);
        $this->makeCollection('notes', ['en'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $note = $this->makeEntry('notes', 'en', ['title' => 'A note']);
        $this->makeTaxonomy('topics', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeTerm('topics', 'food', ['en' => ['title' => 'Food']]);
        $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en'], ['tagline' => ['type' => 'text', 'localizable' => true]], ['en' => ['tagline' => 'By the sea']]);
        $this->makeNav('main', ['en' => [['id' => 'book', 'title' => 'Book your stay']]]);

        [$exitCode, $output] = translateCommand(['--all' => true, '--site' => ['el']]);
        $scope = app(RunRepository::class)->recent(1)[0]->scope;

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Targets: 6 (Greek).')
            ->and($news->fresh()->in('el')->get('title'))->toBe('[T] News')
            ->and($this->world->fresh()->in('el')->get('title'))->toBe('[T] World')
            ->and($note->fresh()->existsIn('el'))->toBeFalse()
            ->and(Term::find('topics::food')->in('el')->get('title'))->toBe('[T] Food')
            ->and(GlobalSet::findByHandle('site_settings')->in('el')->get('tagline'))->toBe('[T] By the sea')
            ->and(Nav::findByHandle('main')->in('el')->tree())->toBe([['id' => 'book', 'title' => '[T] Book your stay']])
            ->and([$scope['collections'], $scope['taxonomies'], $scope['globals'], $scope['navs']])->toBe([['*'], ['*'], ['*'], ['*']]);
    });

    it('translates a missing localization from the site of --from where the entry has one there', function () {
        $sent = [];
        $this->fakeTranslations(function (string $text) use (&$sent) {
            $sent[] = $text;

            return '[T] '.$text;
        });
        $this->localize($this->hello, 'de', ['title' => 'Hallo']);

        translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--from' => 'de']);

        expect($sent)->toContain('Hallo')
            ->and($sent)->toContain('World')
            ->and($sent)->not->toContain('Hello')
            ->and($this->hello->fresh()->in('el')->get('title'))->toBe('[T] Hallo');
    });

    it('keeps --publish-changes and --publish in the run\'s options', function () {
        $this->fakeTranslations();

        translateCommand(['--entry' => [$this->hello->id()], '--site' => ['el'], '--publish-changes' => true, '--publish' => true]);
        translateCommand(['--entry' => [$this->world->id()], '--site' => ['el']]);

        [$second, $first] = app(RunRepository::class)->recent(2);
        expect($first->options)->toMatchArray(['publish_changes' => true, 'new_status' => 'mirror'])
            ->and($second->options)->toMatchArray(['publish_changes' => false, 'new_status' => 'draft']);
    });

    it('starts worker processes through the site\'s please file, and translates itself once they have ended', function () {
        Process::fake(['*' => Process::describe()->runsFor(iterations: 2)]);
        Sleep::fake();
        $this->fakeTranslations();
        $please = base_path('please');
        file_put_contents($please, '<?php');

        try {
            [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--concurrency' => 3]);
        } finally {
            unlink($please);
        }

        $run = app(RunRepository::class)->recent(1)[0];
        expect($exitCode)->toBe(0)
            ->and($run->status)->toBe(RunStatus::Completed)
            ->and($output)->not->toContain('No worker process could be started')
            ->and($this->hello->fresh()->in('el')->get('title'))->toBe('[T] Hello');

        foreach ([1, 2, 3] as $lane) {
            Process::assertRan(fn (PendingProcess $process) => $process->command === [PHP_BINARY, $please, 'tolk:work', $run->id, "--lane={$lane}"]
                && $process->path === base_path()
                && $process->timeout === null);
        }

        Process::assertRanTimes(fn (PendingProcess $process) => true, 3);
        Sleep::assertSlept(fn (Duration $duration) => $duration->totalSeconds === 1.0, 2);
    });

    it('translates in this process when no worker can be started, and says so', function () {
        $this->fakeTranslations();

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--concurrency' => 2]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('No worker process could be started, so this process translates on its own.')
            ->and($this->hello->fresh()->in('el')->get('title'))->toBe('[T] Hello');
    });

    it('reports a worker that stopped with an error', function () {
        Process::fake(['*' => Process::result(exitCode: 255)]);
        $this->fakeTranslations();
        $please = base_path('please');
        file_put_contents($please, '<?php');

        try {
            [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--concurrency' => 2]);
        } finally {
            unlink($please);
        }

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Worker process 1 stopped with exit code 255. Its error is in the log.')
            ->and($output)->toContain('Worker process 2 stopped with exit code 255.')
            ->and($this->world->fresh()->in('el')->get('title'))->toBe('[T] World');
    });

    it('cancels the run when this process fails, so it can be resumed', function () {
        $this->fakeTranslations();
        Event::listen(TargetTranslated::class, fn () => throw new RuntimeException('The disk is full.'));

        expect(fn () => translateCommand(['--collection' => ['articles'], '--site' => ['el']]))->toThrow(RuntimeException::class, 'The disk is full.');

        $run = app(RunRepository::class)->recent(1)[0];
        expect($run->status)->toBe(RunStatus::Cancelled)
            ->and(RunFixtures::statuses($run))->toBe(['done', 'pending']);
    });

    it('stops when the run is deleted while it translates', function () {
        ScriptedEngine::bind(function (EngineCall $call) {
            app(RunRepository::class)->delete(app(RunRepository::class)->recent(1)[0]);

            return ScriptedEngine::translated($call);
        });

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el']]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('The run was deleted while it was translated.')
            ->and($this->hello->fresh()->existsIn('el'))->toBeFalse();
    });

    it('lists the failed targets with their errors and exits 1', function () {
        Sleep::fake(syncWithCarbon: true);
        ScriptedEngine::bind(fn (EngineCall $call) => in_array('World', array_column($call->segments, 'text'), true)
            ? throw new RuntimeException('The model broke down.')
            : ScriptedEngine::translated($call));

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el']]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Hello → Greek: done, created, 2 fields')
            ->and($output)->toContain('World → Greek: failed, The model broke down.')
            ->and($output)->toMatch('/\| 1 +\| 0 +\| 1 +\| 0 +\|/')
            ->and($output)->toContain('These targets failed:')
            ->and($output)->toMatch('/\| World → Greek +\| The model broke down\. +\|/')
            ->and(app(RunRepository::class)->recent(1)[0]->status)->toBe(RunStatus::CompletedWithErrors);
    });

    it('cancels the run on Ctrl-C after the current step, commits what was saved and reports the rest', function () {
        if (! extension_loaded('pcntl') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Needs the pcntl and posix extensions.');
        }

        $commits = RunFixtures::commits();
        ScriptedEngine::bind(function (EngineCall $call, int $number) {
            if ($number === 1) {
                posix_kill(getmypid(), SIGINT);
            }

            return ScriptedEngine::translated($call);
        });

        [$exitCode, $output] = withSignals(fn () => translateCommand(['--collection' => ['articles'], '--site' => ['el']]));

        $run = app(RunRepository::class)->recent(1)[0];
        expect($exitCode)->toBe(1)
            ->and($run->status)->toBe(RunStatus::Cancelled)
            ->and($output)->toContain('Cancelling the run.')
            ->and($output)->toContain('Hello → Greek: done, created, 2 fields')
            ->and($output)->toContain('1 target was not translated. Run the command again to translate it, or resume the run in the Control Panel:')
            ->and($output)->toMatch('/(?<!Scanning) 1\/2 \[/')
            ->and($output)->not->toMatch('/(?<!Scanning) 2\/2 \[/')
            ->and($this->hello->fresh()->in('el')->get('title'))->toBe('[T] Hello')
            ->and($this->world->fresh()->existsIn('el'))->toBeFalse()
            ->and($commits->getArrayCopy())->toBe([ContentWriter::COMMIT_MESSAGE])
            ->and(pcntl_signal_get_handler(SIGINT))->toBe(SIG_DFL);
    });

    it('deletes the run and stops when Ctrl-C comes during the scan', function () {
        if (! extension_loaded('pcntl') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Needs the pcntl and posix extensions.');
        }

        pcntl_signal(SIGINT, fn () => null);
        $this->fakeTranslations();
        $interrupted = false;
        // The scan reads the site profile for the prompt it estimates. The pause uses up the scan's budget, so the scan
        // stops after the first target, as it does on a large site.
        $settings = Mockery::mock(Settings::class)->makePartial();
        $settings->shouldReceive('siteProfile')->andReturnUsing(function () use (&$interrupted) {
            if (! $interrupted) {
                $interrupted = true;
                posix_kill(getmypid(), SIGINT);
                usleep((TranslateCommand::ESTIMATE_BUDGET_MS + 50) * 1000);
            }

            return '';
        });
        app()->instance(Settings::class, $settings);

        try {
            [$exitCode, $output] = withSignals(fn () => translateCommand(['--collection' => ['articles'], '--site' => ['el']]));
        } finally {
            pcntl_signal(SIGINT, SIG_DFL);
        }

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Nothing was translated.')
            ->and($output)->toContain('Scanning 1/2 [')
            ->and($output)->not->toContain('Scanning 2/2 [')
            ->and(app(RunRepository::class)->recent(10))->toBe([]);
        $this->assertNoModelCalls();
    });

    it('leaves the bar of an aborted run where it stopped, and resets the terminal\'s progress indicator', function () {
        ScriptedEngine::bind(fn (EngineCall $call, int $number) => $number === 1
            ? ScriptedEngine::translated($call)
            : throw ProviderRejected::from($call->model, new ProviderError(ProviderErrorKind::Rejected, 'Invalid API key.', 401)));
        $terminal = new BufferedOutput(decorated: true);

        $exitCode = Artisan::call('tolk:translate', ['--yes' => true, '--concurrency' => 1, '--collection' => ['articles'], '--site' => ['el']], $terminal);
        $output = $terminal->fetch();
        // Symfony 7.4 and later also show a bar's progress in the terminal's tab or taskbar (OSC 9;4), until it is reset.
        preg_match_all('/\e\]9;4;\d+;\d+\e\\\\/', $output, $indicators);

        expect($exitCode)->toBe(1)
            ->and($output)->toMatch('/(?<!Scanning) 1\/2 \[/')
            ->and($output)->not->toMatch('/(?<!Scanning) 2\/2 \[/')
            ->and($indicators[0] === [] || end($indicators[0]) === "\e]9;4;0;0\e\\")->toBeTrue();
    });

    it('traps Ctrl-C again after asking for confirmation', function () {
        if (! extension_loaded('pcntl') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Needs the pcntl and posix extensions.');
        }

        // Without the command's own trap the signal would end the test run; this handler only keeps it alive.
        pcntl_signal(SIGINT, fn () => null);
        ScriptedEngine::bind(function (EngineCall $call, int $number) {
            if ($number === 1) {
                posix_kill(getmypid(), SIGINT);
            }

            return ScriptedEngine::translated($call);
        });

        try {
            withSignals(fn () => $this->artisan('tolk:translate', ['--collection' => ['articles'], '--site' => ['el'], '--concurrency' => 1])
                ->expectsConfirmation('Translate and save them?', 'yes')
                ->assertExitCode(1)
                ->run());
        } finally {
            pcntl_signal(SIGINT, SIG_DFL);
        }

        expect(app(RunRepository::class)->recent(1)[0]->status)->toBe(RunStatus::Cancelled)
            ->and($this->world->fresh()->existsIn('el'))->toBeFalse();
    });

    it('deletes the run and translates nothing when Ctrl-C answers the confirmation on a terminal', function () {
        if (! SymfonyProcess::isPtySupported()) {
            $this->markTestSkipped('Needs a pseudo-terminal.');
        }

        $directory = $this->temporaryPath();
        File::ensureDirectoryExists($directory);
        $terminal = new InputStream;
        $child = new SymfonyProcess([PHP_BINARY, TerminalTranslate::SCRIPT, $directory], null, null, $terminal, 120);
        $child->setPty(true);
        $child->start();

        try {
            for ($deadline = microtime(true) + 90; ! str_contains($child->getOutput(), 'Translate and save them?'); usleep(50_000)) {
                expect($child->isRunning() && microtime(true) < $deadline)->toBeTrue("The command did not ask:\n{$child->getOutput()}");
            }

            expect(app(RunRepository::class)->recent(10))->toHaveCount(1);

            // Laravel Prompts reads the terminal in raw mode, which passes Ctrl-C on as this key instead of sending SIGINT.
            $terminal->write("\x03");

            for ($deadline = microtime(true) + 30; $child->isRunning(); usleep(50_000)) {
                expect(microtime(true) < $deadline)->toBeTrue("The command did not end after Ctrl-C:\n{$child->getOutput()}");
            }
        } finally {
            $terminal->close();
            $child->stop(0);
        }

        expect($child->getExitCode())->toBe(1, $child->getOutput())
            ->and($child->getOutput())->toContain('Nothing was translated.')
            ->and(app(RunRepository::class)->recent(10))->toBe([]);
    })->group('slow');

    it('reads an answer piped to the confirmation without a terminal', function () {
        $directory = $this->temporaryPath();
        File::ensureDirectoryExists($directory);
        $child = new SymfonyProcess([PHP_BINARY, TerminalTranslate::SCRIPT, $directory], null, null, "no\n", 120);
        $child->run();

        expect($child->getExitCode())->toBe(0, $child->getOutput().$child->getErrorOutput())
            ->and($child->getOutput())->toContain('Translate and save them?')
            ->and($child->getOutput())->toContain('Nothing was translated.')
            ->and(app(RunRepository::class)->recent(10))->toBe([]);
    })->group('slow');

    it('waits for the workers after Ctrl-C, and stops them at once on a second one', function () {
        if (! extension_loaded('pcntl') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Needs the pcntl and posix extensions.');
        }

        if (! method_exists(FakeInvokedProcess::class, 'stop')) {
            $this->markTestSkipped('The process fake of Laravel 12 cannot stop a process; the real one can.');
        }

        Process::fake(['*' => Process::describe()->runsFor(iterations: 1000)]);
        $this->fakeTranslations();
        $sleeps = [];
        Sleep::fake();
        Sleep::whenFakingSleep(function (Duration $duration) use (&$sleeps) {
            $sleeps[] = $duration->totalMilliseconds;

            // The first pause of the parent, then the first wait for the workers.
            if (count($sleeps) <= 2) {
                posix_kill(getmypid(), SIGINT);
            }
        });
        $please = base_path('please');
        file_put_contents($please, '<?php');

        try {
            [$exitCode, $output] = withSignals(fn () => translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--concurrency' => 2]));
        } finally {
            unlink($please);
        }

        expect($exitCode)->toBe(1)
            ->and(app(RunRepository::class)->recent(1)[0]->status)->toBe(RunStatus::Cancelled)
            ->and($output)->toContain('The worker processes finish their current request first; press Ctrl-C again to stop them at once.')
            ->and($output)->toContain('2 targets were not translated.')
            ->and($sleeps)->toBe([1000.0, 200.0, 200.0]);
        $this->assertNoModelCalls();
    });

    it('advises resuming the run, not running the command again, for a target translated but not saved at Ctrl-C', function () {
        if (! extension_loaded('pcntl') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Needs the pcntl and posix extensions.');
        }

        Process::fake(['*' => Process::describe()->runsFor(iterations: 3)]);
        $this->fakeTranslations();
        $interrupted = false;
        Sleep::fake();
        Sleep::whenFakingSleep(function () use (&$interrupted) {
            if ($interrupted) {
                return;
            }

            // A worker finishes its current request, and Ctrl-C comes before this process saves it.
            $interrupted = true;
            app(RunProcessor::class)->translate(app(RunRepository::class)->recent(1)[0], 'cli-1', CarbonImmutable::now()->addSeconds(30), 300, 1);
            posix_kill(getmypid(), SIGINT);
        });
        $please = base_path('please');
        file_put_contents($please, '<?php');

        try {
            [$exitCode, $output] = withSignals(fn () => translateCommand(['--collection' => ['articles'], '--site' => ['el'], '--concurrency' => 2]));
        } finally {
            unlink($please);
        }

        $run = app(RunRepository::class)->recent(1)[0];
        expect($exitCode)->toBe(1)
            ->and($run->status)->toBe(RunStatus::Cancelled)
            ->and(RunFixtures::statuses($run))->toBe(['translated', 'pending'])
            ->and($output)->toContain('1 target was translated but not saved, and 1 target was not translated. Resume the run in the Control Panel within 2 hours to save it without translating it again, and to translate the rest:')
            ->and($output)->toContain("\n  ".cp_route('tolk.runs.show', $run->id)."\n")
            ->and($output)->not->toContain('Run the command again');
    });
});

/**
 * Runs the callback with Laravel's signal traps on, which it turns off under tests, so the test can send itself one.
 */
function withSignals(Closure $callback): mixed
{
    Signals::resolveAvailabilityUsing(fn () => true);

    try {
        return $callback();
    } finally {
        Signals::resolveAvailabilityUsing(fn () => false);
    }
}
