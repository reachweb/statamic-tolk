<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Reach\StatamicTolk\Ai\EngineCall;
use Reach\StatamicTolk\Ai\ProviderError;
use Reach\StatamicTolk\Contracts\Engine;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\Finish;
use Reach\StatamicTolk\Enums\ProviderErrorKind;
use Reach\StatamicTolk\Events\TargetTranslated;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Tests\Support\ScriptedEngine;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
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

function commandStatuses(EntryContract $source, string $site = 'el'): array
{
    return array_map(fn ($field) => $field->status, app(StatusResolver::class)->resolve(app(TargetFactory::class)->forEntry($source, $site))->fields);
}

function commandEngine(Closure $script): ScriptedEngine
{
    $engine = new ScriptedEngine($script);

    app()->instance(Engine::class, $engine);

    return $engine;
}

/**
 * A structured `pages` collection whose English tree has the given shape (`['home', 'rooms' => ['suites']]`). The
 * entries are created in the given order, which need not be the tree's.
 *
 * @return array<string, EntryContract>
 */
function commandPages(array $shape, array $creationOrder, bool $root = true): array
{
    test()->makeCollection('pages', ['en', 'el', 'de'], ['title' => ['type' => 'text', 'localizable' => true]], structured: true)
        ->structureContents(['root' => $root, 'max_depth' => 3])
        ->save();

    $entries = [];

    foreach ($creationOrder as $slug) {
        $entries[$slug] = test()->makeEntry('pages', 'en', ['title' => ucfirst($slug)], $slug);
    }

    $branches = function (array $shape) use (&$branches, $entries): array {
        return collect($shape)->map(function ($children, $slug) use (&$branches, $entries) {
            [$slug, $children] = is_int($slug) ? [$children, []] : [$slug, $children];

            return array_filter(['entry' => $entries[$slug]->id(), 'children' => $branches($children) ?: null]);
        })->values()->all();
    };

    Collection::find('pages')->structure()->in('en')->tree($branches($shape))->save();

    return $entries;
}

function commandTreeOfSlugs(string $site): array
{
    $slugs = function (array $branches) use (&$slugs): array {
        return collect($branches)->mapWithKeys(fn (array $branch) => [
            Entry::find($branch['entry'])->slug() => isset($branch['children']) ? $slugs($branch['children']) : [],
        ])->all();
    };

    return $slugs(Collection::find('pages')->structure()->in($site)->tree());
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
        'invalid mode' => [['--collection' => ['articles'], '--site' => ['el'], '--mode' => 'everything'], 'Invalid mode [everything]. Use missing, update or all.'],
        'mode review' => [['--collection' => ['articles'], '--site' => ['el'], '--mode' => 'review'], 'Invalid mode [review]. Use missing, update or all.'],
        'invalid translator' => [['--collection' => ['articles'], '--site' => ['el'], '--translator' => 'mistral:large'], 'Invalid model reference [mistral:large].'],
    ]);

    it('fails with a clear message when no provider is configured and the fake engine is off', function () {
        $this->withProviderKeys();

        [$exitCode, $output] = translateCommand(['--collection' => ['articles'], '--site' => ['el']]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('No AI provider is configured.');
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
        $pages = commandPages(['home', 'rooms' => ['suites']], ['home', 'rooms', 'suites']);

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
            ->and(commandStatuses($this->hello))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current])
            ->and(commandStatuses($this->world))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current])
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
            ->and(commandStatuses($this->hello))->toBe(['title' => FieldStatus::Current, 'intro' => FieldStatus::Current]);
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
            ->and(commandStatuses($this->hello)['title'])->toBe(FieldStatus::Current);
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
        $pages = commandPages(['home', 'rooms' => ['suites' => ['honeymoon']], 'dining'], ['honeymoon', 'dining', 'suites', 'rooms', 'home']);

        [$exitCode, $output] = translateCommand(['--collection' => ['pages'], '--site' => ['el']]);

        expect($exitCode)->toBe(0)
            ->and($output)->toMatch('/Home → Greek.*\n.*Rooms → Greek.*\n.*Suites → Greek.*\n.*Honeymoon → Greek.*\n.*Dining → Greek/')
            ->and($output)->not->toContain('failed')
            ->and(commandTreeOfSlugs('el'))->toBe(commandTreeOfSlugs('en'))
            ->and(commandTreeOfSlugs('el'))->toBe(['home' => [], 'rooms' => ['suites' => ['honeymoon' => []]], 'dining' => []]);
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

describe('failures', function () {
    it('fails a target whose root page is missing before calling the model, and exits 1', function () {
        $this->fakeTranslations();
        $pages = commandPages(['home', 'rooms'], ['home', 'rooms']);

        [$exitCode, $output] = translateCommand(['--entry' => [$pages['rooms']->id()], '--site' => ['el']]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Rooms → Greek: failed, Translate Home into Greek first.')
            ->and($output)->toMatch('/\| 0 +\| 0 +\| 1 +\| 0 +\|/')
            ->and($pages['rooms']->fresh()->existsIn('el'))->toBeFalse();
        $this->assertNoModelCalls();
    });

    it('stops at ProviderRejected with its message and exits 1', function () {
        $engine = commandEngine(fn (EngineCall $call) => throw ProviderRejected::from(
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
        commandEngine(fn (EngineCall $call) => in_array('A short intro.', array_column($call->segments, 'text'), true)
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

    it('commits what was saved before ProviderRejected stopped the loop', function () {
        $commits = commandCommits();
        commandEngine(fn (EngineCall $call, int $number) => $number === 1
            ? ScriptedEngine::translated($call)
            : throw ProviderRejected::from($call->model, new ProviderError(ProviderErrorKind::Rejected, 'Invalid API key.', 401)));

        [$exitCode] = translateCommand(['--collection' => ['articles'], '--site' => ['el']]);

        expect($exitCode)->toBe(1)
            ->and($commits->getArrayCopy())->toBe([ContentWriter::COMMIT_MESSAGE]);
    });
});
