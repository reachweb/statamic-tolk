<?php

use Illuminate\Filesystem\Filesystem;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Runs\RunPlanner;
use Reach\StatamicTolk\Runs\RunRequest;
use Reach\StatamicTolk\Support\Audit;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Site;

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('pages', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true],
    ]);
});

function auditChecks(): array
{
    return app(Audit::class)->run();
}

function auditCheck(string $id): array
{
    return collect(auditChecks())->firstWhere('id', $id) ?? throw new RuntimeException("No check [{$id}].");
}

/**
 * @return list<string> "level: text" per item
 */
function auditItems(string $id): array
{
    return array_map(fn (array $item) => "{$item['level']}: {$item['text']}", auditCheck($id)['items']);
}

it('passes every check on a site that is set up, in the order of the checks', function () {
    expect(array_column(auditChecks(), 'level', 'id'))->toBe([
        'sites' => 'ok',
        'providers' => 'ok',
        'queue' => 'ok',
        'cache' => 'ok',
        'state' => 'ok',
        'localizable' => 'ok',
        'technical_fields' => 'ok',
        'content_sites' => 'ok',
        'information' => 'ok',
    ]);

    foreach (auditChecks() as $check) {
        expect($check)->toHaveKeys(['id', 'level', 'title', 'detail', 'items'])
            ->and($check['title'])->not->toBe('')
            ->and($check['detail'])->not->toBe('');
    }
});

it('takes the worst level of its items as a check\'s level', function () {
    config(['ai.providers.tolk_gemini' => ['driver' => 'gemini']]);
    $this->withSettings(['fallback_model' => 'anthropic:claude-sonnet-5-5']);

    expect(auditCheck('providers'))
        ->level->toBe('error')
        ->and(array_column(auditCheck('providers')['items'], 'level'))->toContain('ok', 'warning', 'error');
});

describe('sites', function () {
    it('lists the sites', function () {
        expect(auditItems('sites'))->toBe(['ok: 3 sites: English (en), Greek (el), German (de). English is the default site.']);
    });

    it('fails without multi-site', function () {
        config(['statamic.system.multisite' => false]);

        expect(auditCheck('sites')['level'])->toBe('error')
            ->and(auditItems('sites')[0])->toContain('Multi-site is off');
    });

    it('fails with a single site', function () {
        Site::setSites(['en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US']]);

        expect(auditItems('sites'))->toBe(['error: English (en) is the only site. Add a site for each language under Sites in the Control Panel.']);
    });
});

describe('providers and models', function () {
    it('lists the keys and the models that are used', function () {
        expect(auditItems('providers'))->toBe([
            'ok: API keys: anthropic, openai, gemini.',
            'ok: Translator: Claude Sonnet 5.5 (anthropic:claude-sonnet-5-5), the default, because no translator model is chosen.',
            'ok: Reviewer: GPT-6.1 Sol (openai:gpt-6.1-sol). Review is off by default.',
        ]);
    });

    it('fails when no provider has a key', function () {
        $this->withProviderKeys();

        expect(auditItems('providers'))->toBe(['error: No provider has an API key. Set ANTHROPIC_API_KEY, OPENAI_API_KEY or GEMINI_API_KEY in .env.']);
    });

    it('warns instead while the fake engine is on', function () {
        $this->withProviderKeys(['tolk.fake_engine' => true]);

        expect(auditCheck('providers')['level'])->toBe('warning')
            ->and(auditItems('providers'))->toBe([
                'warning: The fake engine is on (TOLK_FAKE_ENGINE): nothing is sent to a provider, and every translation is the source text marked with its language.',
                'warning: No provider has an API key, so only the fake engine can translate.',
                'ok: Translator: Claude Sonnet 5.5 (anthropic:claude-sonnet-5-5), the default, because no translator model is chosen.',
                'ok: Reviewer: Claude Sonnet 5.5 (anthropic:claude-sonnet-5-5). Review is off by default.',
            ]);
    });

    it('warns that the fake engine setting is ignored outside the local and testing environments', function () {
        config(['tolk.fake_engine' => true]);
        app()->detectEnvironment(fn () => 'production');

        expect(auditItems('providers')[0])->toBe('warning: TOLK_FAKE_ENGINE is set, but the fake engine runs only in the local and testing environments, so it is off here. Remove the setting.');
    });

    it('fails when the app configures a provider under one of Tolk\'s names', function () {
        config(['ai.providers.tolk_openai' => ['driver' => 'openai', 'key' => 'host-key']]);

        expect(auditCheck('providers')['level'])->toBe('error')
            ->and(auditItems('providers'))->toContain('error: config/ai.php configures a provider named tolk_openai. Tolk builds a provider of that name itself, and the Laravel AI SDK refuses to build one the app configures, so every translation with openai fails. Rename it.');
    });

    it('fails on a model setting that does not parse, naming the setting', function () {
        $this->withSettings(['translator_model' => 'claude', 'fallback_model' => 'mistral:large']);

        expect(auditItems('providers'))->toBe([
            'ok: API keys: anthropic, openai, gemini.',
            'error: The setting "Translator model" is not a model: Invalid model reference [claude]. Use provider:model, where the provider is anthropic, openai or gemini. Choose a model in Tolk\'s settings.',
            'error: The setting "Fallback model" is not a model: Invalid model reference [mistral:large]. Use provider:model, where the provider is anthropic, openai or gemini. Choose a model in Tolk\'s settings.',
        ]);
    });

    it('fails on a translator or reviewer whose provider has no key, and warns about such a fallback', function () {
        $this->withProviderKeys(['ai.providers.openai.key' => 'test-key']);
        $this->withSettings([
            'translator_model' => 'anthropic:claude-sonnet-5-5',
            'review_enabled' => true,
            'reviewer_model' => 'gemini:gemini-3.8-flash',
            'fallback_model' => 'anthropic:claude-haiku-4-5',
        ]);

        expect(auditItems('providers'))->toBe([
            'ok: API keys: openai.',
            'error: The translator model anthropic:claude-sonnet-5-5 uses anthropic, which has no API key, so every translation fails.',
            'ok: Translator: Claude Sonnet 5.5 (anthropic:claude-sonnet-5-5).',
            'error: The reviewer model gemini:gemini-3.8-flash uses gemini, which has no API key, so every review fails.',
            'ok: Reviewer: Gemini 3.8 Flash (gemini:gemini-3.8-flash). Review is on by default.',
            'warning: The fallback model anthropic:claude-haiku-4-5 uses anthropic, which has no API key, so Tolk ignores it.',
        ]);
    });

    it('only warns about a reviewer without a key while review is off', function () {
        $this->withProviderKeys(['ai.providers.openai.key' => 'test-key']);
        $this->withSettings(['reviewer_model' => 'gemini:gemini-3.8-flash']);

        expect(auditCheck('providers')['level'])->toBe('warning')
            ->and(auditItems('providers'))->toContain('warning: The reviewer model gemini:gemini-3.8-flash uses gemini, which has no API key, so every review fails. Review is off by default, but an operation that asks for one fails.');
    });

    it('fails when the catalog has no quality model for a provider with a key', function () {
        config(['tolk.models.openai' => [['id' => 'gpt-6-luna', 'tier' => 'fast']]]);

        expect(auditItems('providers'))->toContain('error: The model catalog (tolk.models) has no quality model for [openai]. Tolk uses it whenever no model is chosen in the settings. Add one to tolk.models in config/tolk.php.');
    });
});

describe('queue', function () {
    it('names the connection and the browser driver when the queue has no worker', function () {
        expect(auditItems('queue'))->toBe([
            'ok: Queue connection: sync.',
            'ok: Runs started in the Control Panel are driven by their run page, which must stay open until the run ends.',
        ]);
    });

    it('checks that retry_after leaves room for a provider call on a queue with a worker', function (?int $retryAfter, string $level, string $text) {
        config([
            'queue.connections.jobs' => array_filter(['driver' => 'database', 'table' => 'jobs', 'retry_after' => $retryAfter]),
            'tolk.runs.queue.connection' => 'jobs',
        ]);

        expect(auditCheck('queue')['level'])->toBe($level)
            ->and(auditItems('queue'))->toBe([
                'ok: Queue connection: jobs (database).',
                'ok: Runs started in the Control Panel run as queue jobs on jobs. Keep a queue worker running.',
                "{$level}: {$text}",
            ]);
    })->with([
        'too short' => [65, 'error', 'retry_after is 65 seconds, which leaves no room for a provider call of up to 45 seconds (tolk.http.timeout.web): the queue would hand a run\'s job to a second worker while the first still works on it. Raise retry_after to at least 85 seconds.'],
        'short' => [84, 'warning', 'retry_after is 84 seconds, so each job of a run starts provider calls for only 19 seconds and the queue gets more jobs. Raise retry_after to at least 85 seconds.'],
        'enough' => [85, 'ok', 'retry_after is 85 seconds, enough for a provider call of up to 45 seconds (tolk.http.timeout.web).'],
        'the default' => [null, 'ok', 'retry_after is 90 seconds, enough for a provider call of up to 45 seconds (tolk.http.timeout.web).'],
    ]);

    it('warns that an SQS connection needs retry_after set to its visibility timeout', function () {
        config([
            'queue.connections.sqs' => ['driver' => 'sqs', 'queue' => 'default'],
            'tolk.runs.queue.connection' => 'sqs',
        ]);

        expect(auditItems('queue'))->toContain("warning: The SQS connection sets no retry_after, so Tolk assumes 90 seconds. Set retry_after on the connection to the queue's visibility timeout.");
    });

    it('fails when runs must use a queue that has no worker', function () {
        config(['tolk.runs.execution' => 'queue']);

        expect(auditItems('queue'))->toBe([
            'ok: Queue connection: sync.',
            'error: tolk.runs.execution is queue, but the connection [sync] has no worker of its own, so a run would be worked through inside the request that starts it. Use a queue with a worker, or set tolk.runs.execution to auto or browser.',
        ]);
    });

    it('fails on an execution setting it does not know and on a connection that is not configured', function () {
        config(['tolk.runs.execution' => 'cron']);

        expect(auditItems('queue'))->toBe([
            'ok: Queue connection: sync.',
            'error: The setting tolk.runs.execution must be auto, queue or browser, not [cron].',
        ]);

        config(['tolk.runs.queue.connection' => 'missing']);

        expect(auditItems('queue'))->toBe(['error: The queue connection [missing] is not configured in config/queue.php.']);
    });
});

describe('cache', function () {
    it('accepts a shared store with locks', function () {
        expect(auditItems('cache'))->toBe(['ok: Cache store: tolk-test-file (file), with locks. A file cache is shared only by the processes of one server.']);
    });

    it('fails on a store that processes do not share', function (string $driver) {
        config(['cache.stores.local' => ['driver' => $driver], 'tolk.cache_store' => 'local']);

        expect(auditItems('cache'))->toBe(["error: The cache store local ({$driver}) is not shared between web requests, queue workers and the command line, so they lose each other's plans and do not keep each other from saving at once. Use a store such as file, database or redis (tolk.cache_store)."]);
    })->with(['array', 'null', 'apc']);

    it('fails on a store that is not configured', function () {
        config(['tolk.cache_store' => 'missing']);

        expect(auditItems('cache'))->toBe(['error: The cache store [missing] is not configured in config/cache.php.']);
    });
});

describe('translation state', function () {
    it('fails on a driver that does not exist', function () {
        config(['tolk.state.driver' => 'database']);

        expect(auditItems('state')[0])->toBe('error: The state driver [database] does not exist. Tolk keeps state with the file driver (tolk.state.driver).');
    });

    it('fails when the folder cannot be written', function () {
        $readOnly = $this->temporaryPath('read-only');
        (new Filesystem)->ensureDirectoryExists($readOnly);
        chmod($readOnly, 0555);
        config(['tolk.state.path' => $readOnly.'/tolk']);

        try {
            expect(auditCheck('state')['level'])->toBe('error')
                ->and(auditItems('state')[0])->toStartWith('error: Tolk cannot write the state files to');
        } finally {
            chmod($readOnly, 0755);
        }
    })->skip(fn () => function_exists('posix_getuid') && posix_getuid() === 0, 'root writes everywhere');

    it('checks that Statamic\'s git integration tracks the folder', function () {
        config([
            'statamic.git.enabled' => true,
            'tolk.state.path' => base_path('content/tolk'),
            'statamic.git.paths' => [base_path('content'), 'users'],
        ]);

        expect(auditItems('state'))->toBe([
            'ok: State files: content/tolk.',
            "ok: Statamic's git integration tracks the state files, so they are committed with the content.",
        ]);

        config(['statamic.git.paths' => ['content-other', 'users']]);

        expect(auditItems('state')[1])->toBe("warning: Statamic's git integration does not track content/tolk, so the state is not committed with the content it describes. Add the folder to statamic.git.paths.");
    });
});

describe('localizable fields', function () {
    beforeEach(function () {
        Fieldset::make('seo')->setContents(['fields' => [
            ['handle' => 'description', 'field' => ['type' => 'textarea']],
        ]])->save();
        Fieldset::make('common')->setContents(['fields' => [
            ['handle' => 'subtitle', 'field' => ['type' => 'text']],
        ]])->save();

        // No title: the collection adds one, which is not localizable.
        $this->makeCollection('articles', ['en', 'el'], [
            'teaser' => ['type' => 'textarea', 'localizable' => true],
            'internal_note' => ['type' => 'text', 'display' => 'Internal note'],
            'website' => ['type' => 'text', 'input_type' => 'url'],
            'image' => ['type' => 'assets'],
            'excluded_note' => ['type' => 'text'],
            ['handle' => 'subtitle', 'field' => 'common.subtitle'],
            ['import' => 'seo', 'prefix' => 'seo_'],
        ]);
        $this->makeBlueprint('collections.articles', 'review', ['verdict' => ['type' => 'text']]);
        $this->makeCollection('notes', ['en'], ['body' => ['type' => 'text']]);
        $this->makeCollection('drafts', ['en', 'el'], ['body' => ['type' => 'text']]);
        $this->makeTaxonomy('topics', ['en', 'el'], ['title' => ['type' => 'text', 'localizable' => true], 'summary' => ['type' => 'textarea']]);
        $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en'], ['phone' => ['type' => 'text']], []);

        $this->withSettings(['excluded_fields' => ['excluded_note'], 'excluded_collections' => ['drafts']]);
    });

    it('lists the text fields that are not localizable per blueprint of what Tolk translates', function () {
        expect(auditItems('localizable'))->toBe([
            'warning: Collection Articles › Article: Title (title), Internal note (internal_note), Subtitle (subtitle), Seo Description (seo_description, from the fieldset seo).',
            'warning: Collection Articles › Review: Title (title), Verdict (verdict).',
            'warning: Taxonomy Topics › Topic: Summary (summary).',
            'warning: Global set Site Settings: Phone (phone).',
        ]);

        expect(auditCheck('localizable')['items'][0])->toMatchArray([
            'type' => 'collection',
            'container' => 'articles',
            'namespace' => 'collections.articles',
            'blueprint' => 'article',
            'name' => 'Collection Articles › Article',
            'fields' => [
                ['handle' => 'title', 'label' => 'Title', 'type' => 'text', 'fieldset' => null],
                ['handle' => 'internal_note', 'label' => 'Internal note', 'type' => 'text', 'fieldset' => null],
                ['handle' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text', 'fieldset' => null],
                ['handle' => 'seo_description', 'label' => 'Seo Description', 'type' => 'textarea', 'fieldset' => 'seo'],
            ],
        ]);
    });

    it('leaves out the title of a collection that generates titles', function () {
        Collection::find('articles')->titleFormats('{{ teaser }}')->save();

        expect(auditCheck('localizable')['items'][0]['text'])
            ->toBe('Collection Articles › Article: Internal note (internal_note), Subtitle (subtitle), Seo Description (seo_description, from the fieldset seo).');
    });

    it('makes only the named fields localizable, and the next audit no longer lists them', function () {
        $count = app(Audit::class)->makeLocalizable('collections.articles', 'article', ['title', 'subtitle', 'seo_description', 'teaser', 'unknown']);

        expect($count)->toBe(2)
            ->and(auditItems('localizable'))->toBe([
                'warning: Collection Articles › Article: Internal note (internal_note), Seo Description (seo_description, from the fieldset seo).',
                'warning: Collection Articles › Review: Title (title), Verdict (verdict).',
                'warning: Taxonomy Topics › Topic: Summary (summary).',
                'warning: Global set Site Settings: Phone (phone).',
            ]);

        $fields = collect(Blueprint::find('collections.articles.article')->contents()['tabs'])
            ->flatMap(fn (array $tab) => collect($tab['sections'])->flatMap(fn (array $section) => $section['fields']))
            ->keyBy(fn (array $field) => $field['handle'] ?? 'import:'.$field['import'])
            ->all();

        expect($fields['title']['field'])->toMatchArray(['type' => 'text', 'localizable' => true])
            ->and($fields['subtitle'])->toBe(['handle' => 'subtitle', 'field' => 'common.subtitle', 'config' => ['localizable' => true]])
            ->and($fields['internal_note']['field'])->toBe(['type' => 'text', 'display' => 'Internal note'])
            ->and($fields['import:seo'])->toBe(['import' => 'seo', 'prefix' => 'seo_'])
            ->and(Fieldset::find('common')->fields()->get('subtitle')->isLocalizable())->toBeFalse();
    });

    it('makes the fields of a taxonomy or global set blueprint localizable', function () {
        expect(app(Audit::class)->makeLocalizable('taxonomies.topics', 'topic', ['summary']))->toBe(1)
            ->and(app(Audit::class)->makeLocalizable('globals', 'site_settings', ['phone']))->toBe(1)
            ->and(auditItems('localizable'))->toBe([
                'warning: Collection Articles › Article: Title (title), Internal note (internal_note), Subtitle (subtitle), Seo Description (seo_description, from the fieldset seo).',
                'warning: Collection Articles › Review: Title (title), Verdict (verdict).',
            ]);
    });

    it('saves nothing when no named field changes', function () {
        $path = Blueprint::find('collections.articles.article')->path();
        $before = file_get_contents($path);

        expect(app(Audit::class)->makeLocalizable('collections.articles', 'article', ['teaser', 'seo_description']))->toBe(0)
            ->and(file_get_contents($path))->toBe($before);
    });

    it('refuses a blueprint it does not know', function (string $namespace, string $handle) {
        app(Audit::class)->makeLocalizable($namespace, $handle, ['title']);
    })->with([
        ['collections.missing', 'article'],
        ['collections.articles', 'missing'],
        ['globals.site_settings', 'site_settings'],
        ['forms', 'contact'],
    ])->throws(InvalidArgumentException::class);
});

describe('nested fields that look like data', function () {
    it('lists them per blueprint', function () {
        $this->makeCollection('articles', ['en', 'el'], [
            'title' => ['type' => 'text', 'localizable' => true],
            'body' => ['type' => 'replicator', 'localizable' => true, 'sets' => ['main' => ['sets' => ['text' => [
                'display' => 'Text',
                'fields' => [
                    ['handle' => 'text', 'field' => ['type' => 'textarea']],
                    ['handle' => 'anchor', 'field' => ['type' => 'text', 'display' => 'Anchor']],
                ],
            ]]]]],
        ]);

        expect(auditItems('technical_fields'))->toBe(['warning: Collection Articles › Article: Body › Text › Anchor (body.text.anchor).'])
            ->and(auditCheck('technical_fields')['items'][0]['fields'])->toBe([
                ['path' => 'body.text.anchor', 'handle' => 'anchor', 'type' => 'text', 'label' => 'Body › Text › Anchor'],
            ]);
    });
});

describe('sites of collections, taxonomies and global sets', function () {
    it('lists containers that miss a site, global set sites without an origin and global sets without a blueprint', function () {
        $this->makeCollection('notes', ['en'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeCollection('drafts', ['en'], ['title' => ['type' => 'text', 'localizable' => true]]);
        $this->makeTaxonomy('topics', ['en', 'el']);
        $this->makeGlobalSet('site_settings', ['en' => null, 'el' => 'en', 'de' => null], ['phone' => ['type' => 'text', 'localizable' => true]], []);
        $this->makeGlobalSet('footer', ['en' => null, 'el' => 'en'], null, []);
        $this->makeGlobalSet('header', ['en' => null, 'el' => 'en', 'de' => 'en'], ['logo_text' => ['type' => 'text', 'localizable' => true]], []);
        $this->withSettings(['excluded_collections' => ['drafts']]);

        expect(auditItems('content_sites'))->toBe([
            'warning: Collection Notes is not enabled for Greek (el), German (de), so it is not translated into them.',
            'warning: Taxonomy Topics is not enabled for German (de), so it is not translated into it.',
            "warning: Global set Site Settings names no origin for German (de), so Tolk does not translate it. Choose the site to translate from as the origin in the set's sites.",
            'warning: Global set Footer is not enabled for German (de), so it is not translated into it.',
            'warning: Global set Footer has no blueprint, so Statamic treats its values as text fields that are not localizable and Tolk translates none of them. Create a blueprint with localizable fields for it.',
        ]);
    });
    it('lists a global set site whose origin the set no longer has, as runs skip it, though another site is translated from it', function () {
        $this->makeGlobalSet('site_settings', ['de' => 'fr', 'el' => 'de'], ['phone' => ['type' => 'text', 'localizable' => true]], []);
        $this->makeGlobalSet('footer', ['de' => null, 'en' => 'de', 'el' => 'en'], ['phone' => ['type' => 'text', 'localizable' => true]], []);

        expect(auditItems('content_sites'))->toBe([
            'warning: Global set Site Settings is not enabled for English (en), so it is not translated into it.',
            "warning: Global set Site Settings names no origin for German (de), so Tolk does not translate it. Choose the site to translate from as the origin in the set's sites.",
        ])
            ->and(app(RunPlanner::class)->build(new RunRequest(['el', 'de'], RunDriver::Browser, globals: ['site_settings', 'footer']))->skipped)->toBe(['no_origin' => 1]);
    });
});

describe('information', function () {
    it('describes revisions, git, static caching and the workers of browser runs', function () {
        expect(auditItems('information'))->toBe([
            'ok: Revisions: off for the collections Tolk translates, so translations are saved directly.',
            'ok: Git integration: off. Commit the state files together with the content.',
            'ok: Static caching: off.',
            'ok: A run driven by the browser keeps 3 PHP workers busy while its page is open: 2 lanes (tolk.concurrency.browser) and the apply loop.',
        ]);

        config([
            'statamic.revisions.enabled' => true,
            'statamic.git.enabled' => true,
            'statamic.git.automatic' => true,
            'statamic.static_caching.strategy' => 'half',
            'tolk.concurrency.browser' => 1,
        ]);
        Collection::find('pages')->revisionsEnabled(true)->save();

        expect(auditItems('information'))->toBe([
            'ok: Revisions: on for Pages. A new localization gets a revision, translations of a published localization go to its working copy, and "publish changes" (--publish-changes) publishes them only when it had no working copy before.',
            'ok: Git integration: on, with automatic commits. Tolk saves without Statamic\'s commit listener and dispatches one commit ("Content translated") once a run or command has saved content.',
            "ok: Static caching: half. Tolk saves content with Statamic's events, so your invalidation rules clear cached pages as after a save in the Control Panel.",
            'ok: A run driven by the browser keeps 2 PHP workers busy while its page is open: 1 lane (tolk.concurrency.browser) and the apply loop.',
        ]);
    });
});
