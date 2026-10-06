<?php

namespace Reach\StatamicTolk\Support;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Reach\StatamicTolk\Ai\FakeEngine;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Providers;
use Reach\StatamicTolk\Content\TranslatableFields;
use Reach\StatamicTolk\Enums\AuditLevel;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\Tier;
use Reach\StatamicTolk\Exceptions\InvalidModelRef;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Jobs\WorkRun;
use Reach\StatamicTolk\Runs\RunDispatcher;
use Reach\StatamicTolk\State\StatusScanner;
use Reach\StatamicTolk\Targets\GlobalsTarget;
use Statamic\Contracts\Globals\GlobalSet as GlobalSetContract;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Path;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Fields;
use Throwable;

/**
 * The setup checks of tolk:doctor and the Audit page. Each check is `['id', 'level', 'title', 'detail', 'items']`, its
 * level the worst of its items'. Each item is `['level', 'text']`; the items of the `localizable` and
 * `technical_fields` checks also name their blueprint (`type`, `container`, `namespace`, `blueprint`, `name`) and list
 * its `fields`.
 */
final class Audit
{
    public const string LOCALIZABLE = 'localizable';

    public const string TECHNICAL_FIELDS = 'technical_fields';

    // Cache drivers whose store lives in one process or one request, so web requests, workers and the CLI never share it.
    private const array UNSHARED_CACHES = ['array', 'null', 'session', 'apc'];

    public function __construct(
        private readonly Providers $providers,
        private readonly ModelCatalog $catalog,
        private readonly Settings $settings,
        private readonly TranslatableFields $translatableFields,
        private readonly StatusScanner $scanner,
    ) {}

    /**
     * @return list<array{id: string, level: string, title: string, detail: string, items: list<array>}>
     */
    public function run(): array
    {
        return [
            $this->check('sites', 'Sites', 'Tolk translates content from one site into its localizations in the others, so it needs multi-site with at least two sites.', $this->siteItems()),
            $this->check('providers', 'Providers and models', 'Translating needs an API key for at least one provider, and every model chosen in the settings must use a provider that has one.', $this->providerItems()),
            $this->check('queue', 'Queue', 'Runs started in the Control Panel run as queue jobs when the queue connection has a worker, and are driven by the open run page otherwise. Each queue job must end before the connection hands it to another worker (retry_after).', $this->queueItems()),
            $this->check('cache', 'Cache', 'Plans, locks and progress live in one cache store, which must support locks and be shared by web requests, queue workers and the command line.', $this->cacheItems()),
            $this->check('state', 'Translation state', 'Tolk keeps what it knows about each translated field in files next to the content, which it must be able to write and which belong in version control with the content.', $this->stateItems()),
            $this->check(self::LOCALIZABLE, 'Localizable fields', 'A text field that is not localizable is shared by every site, so Tolk does not translate it. Run php please tolk:doctor --fix-localizable to make these fields localizable, blueprint by blueprint.', $this->notLocalizable()),
            $this->check(self::TECHNICAL_FIELDS, 'Nested fields that look like data', 'These nested fields are translated, but their handles suggest identifiers, links or code. If one holds data rather than text, switch off "Translate automatically" in its field settings.', $this->technicalFields()),
            $this->check('content_sites', 'Sites of collections, taxonomies and global sets', 'Content is translated only into the sites its collection, taxonomy or global set is enabled for, and a global set translates each site from the origin it names for it.', $this->contentSiteItems()),
            $this->check('information', 'How Tolk works on this site', 'Information only.', $this->informationItems()),
        ];
    }

    /**
     * The blueprints of the collections, taxonomies and global sets Tolk translates that have text fields that are not
     * localizable (§A2 reason `not_localizable`; a text field with another input type, such as url, holds no text). A
     * field from an imported fieldset names it as `fieldset`: makeLocalizable() leaves it alone.
     *
     * @return list<array{level: string, text: string, type: string, container: string, namespace: string, blueprint: string, name: string, fields: list<array{handle: string, label: string, type: string, fieldset: ?string}>}>
     */
    public function notLocalizable(): array
    {
        $items = [];

        foreach ($this->blueprints() as $source) {
            $explained = $this->translatableFields->explain($source['blueprint'], $source['context']);
            $imported = $this->importedFields($source['blueprint']);
            $fields = [];

            foreach ($explained as $field) {
                if ($field['reason'] === 'not_localizable' && $this->translatableFields->holdsText($source['blueprint']->field($field['handle']))) {
                    $fields[] = [
                        'handle' => $field['handle'],
                        'label' => (string) $field['display'],
                        'type' => $field['type'],
                        'fieldset' => $imported[$field['handle']] ?? null,
                    ];
                }
            }

            if ($fields !== []) {
                $items[] = $this->blueprintItem($source, $fields, array_map(fn (array $field) => sprintf(
                    $field['fieldset'] === null ? '%s (%s)' : '%s (%s, from the fieldset %s)',
                    $field['label'],
                    $field['handle'],
                    $field['fieldset'],
                ), $fields));
            }
        }

        return $items;
    }

    /**
     * The blueprints with nested fields that would be translated although their handles look technical
     * (TranslatableFields::nestedTechnicalFields()).
     *
     * @return list<array{level: string, text: string, type: string, container: string, namespace: string, blueprint: string, name: string, fields: list<array{path: string, handle: string, type: string, label: string}>}>
     */
    public function technicalFields(): array
    {
        $items = [];

        foreach ($this->blueprints() as $source) {
            $fields = $this->translatableFields->nestedTechnicalFields($source['blueprint']);

            if ($fields !== []) {
                $items[] = $this->blueprintItem($source, $fields, array_map(fn (array $field) => "{$field['label']} ({$field['path']})", $fields));
            }
        }

        return $items;
    }

    /**
     * Sets `localizable: true` on the named top-level fields of a blueprint and saves it, as the blueprint editor does
     * (through the container, so fields the collection adds itself, such as `title`, are written too). Fields of an
     * imported fieldset are left alone: an import's per-field overrides are dropped the next time the blueprint is
     * saved in the Control Panel, so they must be changed in the fieldset. Returns how many fields changed.
     *
     * @param  string  $blueprintNamespace  `collections.{handle}`, `taxonomies.{handle}` or `globals`
     * @param  list<string>  $fields
     *
     * @throws InvalidArgumentException when there is no such blueprint
     */
    public function makeLocalizable(string $blueprintNamespace, string $handle, array $fields): int
    {
        $blueprint = $this->findBlueprint($blueprintNamespace, $handle)
            ?? throw new InvalidArgumentException("Blueprint [{$blueprintNamespace}.{$handle}] not found.");

        $imported = $this->importedFields($blueprint);
        $changed = array_values(array_filter(
            array_unique($fields),
            fn (string $field) => $blueprint->hasField($field) && ! isset($imported[$field]) && ! $blueprint->field($field)->isLocalizable(),
        ));

        foreach ($changed as $field) {
            $blueprint->ensureFieldHasConfig($field, ['localizable' => true]);
        }

        if ($changed !== []) {
            $blueprint->save();
        }

        return count($changed);
    }

    private function siteItems(): array
    {
        if (! Site::multiEnabled()) {
            return [$this->item(AuditLevel::Error, 'Multi-site is off (statamic.system.multisite), so there is no site to translate into.')];
        }

        $names = Site::all()->map(fn ($site) => $this->siteName($site->handle()))->values();

        if ($names->count() < 2) {
            return [$this->item(AuditLevel::Error, "{$names->first()} is the only site. Add a site for each language under Sites in the Control Panel.")];
        }

        return [$this->item(AuditLevel::Ok, sprintf('%d sites: %s. %s is the default site.', $names->count(), $names->implode(', '), Site::default()->name()))];
    }

    private function providerItems(): array
    {
        $items = [];
        $fake = FakeEngine::enabled();
        $missingKey = $fake ? AuditLevel::Warning : AuditLevel::Error;
        $configured = $this->providers->configured();

        if ($fake) {
            $items[] = $this->item(AuditLevel::Warning, 'The fake engine is on (TOLK_FAKE_ENGINE): nothing is sent to a provider, and every translation is the source text marked with its language.');
        } elseif (filter_var(config('tolk.fake_engine'), FILTER_VALIDATE_BOOL)) {
            $items[] = $this->item(AuditLevel::Warning, 'TOLK_FAKE_ENGINE is set, but the fake engine runs only in the local and testing environments, so it is off here. Remove the setting.');
        }

        $items[] = $configured === []
            ? $this->item($missingKey, $fake
                ? 'No provider has an API key, so only the fake engine can translate.'
                : 'No provider has an API key. Set ANTHROPIC_API_KEY, OPENAI_API_KEY or GEMINI_API_KEY in .env.')
            : $this->item(AuditLevel::Ok, 'API keys: '.implode(', ', $configured).'.');

        foreach (ModelRef::PROVIDERS as $provider) {
            $name = $this->providers->name($provider);

            if (config("ai.providers.{$name}") !== null) {
                $items[] = $this->item(AuditLevel::Error, "config/ai.php configures a provider named {$name}. Tolk builds a provider of that name itself, and the Laravel AI SDK refuses to build one the app configures, so every translation with {$provider} fails. Rename it.");
            }
        }

        foreach ($configured as $provider) {
            try {
                $this->catalog->default($provider, Tier::Quality);
            } catch (MissingDefaultModel $exception) {
                $items[] = $this->item(AuditLevel::Error, $exception->getMessage().' Tolk uses it whenever no model is chosen in the settings. Add one to tolk.models in config/tolk.php.');
            }
        }

        return [...$items, ...$this->modelItems($missingKey)];
    }

    /**
     * The translator, reviewer and fallback models: chosen ones that do not parse or whose provider has no key, and
     * the models that are used.
     */
    private function modelItems(AuditLevel $missingKey): array
    {
        $items = $models = [];
        $readers = [
            'Translator model' => $this->settings->translatorModel(...),
            'Reviewer model' => $this->settings->reviewerModel(...),
            'Fallback model' => $this->settings->fallbackModel(...),
        ];

        foreach ($readers as $label => $read) {
            try {
                $models[] = $read();
            } catch (InvalidModelRef $exception) {
                $models[] = false;
                $items[] = $this->item(AuditLevel::Error, "The setting \"{$label}\" is not a model: {$exception->getMessage()} Choose a model in Tolk's settings.");
            }
        }

        [$chosen, $reviewerChosen, $fallback] = $models;

        if ($chosen && ! $this->providers->isConfigured($chosen->provider)) {
            $items[] = $this->item($missingKey, "The translator model {$chosen} uses {$chosen->provider}, which has no API key, so every translation fails.");
        }

        try {
            $translator = $chosen === false ? null : $this->catalog->resolveTranslator($this->settings);
        } catch (MissingDefaultModel|NoProviderConfigured) {
            // Reported with the providers.
            $translator = null;
        }

        if ($translator === null) {
            return $items;
        }

        $items[] = $this->item(AuditLevel::Ok, 'Translator: '.$this->modelName($translator).($chosen ? '.' : ', the default, because no translator model is chosen.'));

        if ($reviewerChosen !== false) {
            $review = $this->settings->reviewEnabled();

            if ($reviewerChosen && ! $this->providers->isConfigured($reviewerChosen->provider)) {
                $items[] = $this->item($review ? $missingKey : AuditLevel::Warning, "The reviewer model {$reviewerChosen} uses {$reviewerChosen->provider}, which has no API key, so every review fails.".($review ? '' : ' Review is off by default, but an operation that asks for one fails.'));
            }

            try {
                $items[] = $this->item(AuditLevel::Ok, 'Reviewer: '.$this->modelName($this->catalog->resolveReviewer($this->settings, $translator)).'. Review is '.($review ? 'on' : 'off').' by default.');
            } catch (MissingDefaultModel) {
                // Reported with the providers.
            }
        }

        if ($fallback) {
            $items[] = match (true) {
                ! $this->providers->isConfigured($fallback->provider) => $this->item(AuditLevel::Warning, "The fallback model {$fallback} uses {$fallback->provider}, which has no API key, so Tolk ignores it."),
                (string) $fallback === (string) $translator => $this->item(AuditLevel::Warning, "The fallback model {$fallback} is the translator model, so Tolk ignores it."),
                default => $this->item(AuditLevel::Ok, 'Fallback: '.$this->modelName($fallback).'.'),
            };
        }

        return $items;
    }

    private function queueItems(): array
    {
        $connection = RunDispatcher::connection();
        $config = RunDispatcher::connectionConfig();
        $driver = $config['driver'] ?? null;

        if (! is_string($driver)) {
            return [$this->item(AuditLevel::Error, "The queue connection [{$connection}] is not configured in config/queue.php.")];
        }

        $items = [$this->item(AuditLevel::Ok, 'Queue connection: '.$this->named($connection, $driver).'.')];

        try {
            $runDriver = RunDispatcher::resolveDriver();
        } catch (InvalidArgumentException $exception) {
            $runDriver = null;
            $items[] = $this->item(AuditLevel::Error, $exception->getMessage());
        }

        $items[] = match (true) {
            $runDriver === RunDriver::Queue && ! RunDispatcher::hasWorker() => $this->item(AuditLevel::Error, "tolk.runs.execution is queue, but the connection [{$connection}] has no worker of its own, so a run would be worked through inside the request that starts it. Use a queue with a worker, or set tolk.runs.execution to auto or browser."),
            $runDriver === RunDriver::Queue => $this->item(AuditLevel::Ok, "Runs started in the Control Panel run as queue jobs on {$connection}. Keep a queue worker running."),
            $runDriver === RunDriver::Browser => $this->item(AuditLevel::Ok, 'Runs started in the Control Panel are driven by their run page, which must stay open until the run ends.'),
            default => null,
        };

        if (RunDispatcher::hasWorker()) {
            $retryAfter = WorkRun::retryAfter();
            $timeout = (int) config('tolk.http.timeout.web');

            if ($driver === 'sqs' && ! isset($config['retry_after'])) {
                $items[] = $this->item(AuditLevel::Warning, 'The SQS connection sets no retry_after, so Tolk assumes '.WorkRun::RETRY_AFTER." seconds. Set retry_after on the connection to the queue's visibility timeout.");
            }

            $items[] = match (true) {
                $retryAfter <= $timeout + 20 => $this->item(AuditLevel::Error, sprintf('retry_after is %d seconds, which leaves no room for a provider call of up to %d seconds (tolk.http.timeout.web): the queue would hand a run\'s job to a second worker while the first still works on it. Raise retry_after to at least %d seconds.', $retryAfter, $timeout, $timeout + 40)),
                $retryAfter < $timeout + 40 => $this->item(AuditLevel::Warning, sprintf('retry_after is %d seconds, so each job of a run starts provider calls for only %d seconds and the queue gets more jobs. Raise retry_after to at least %d seconds.', $retryAfter, WorkRun::deadlineSeconds(), $timeout + 40)),
                default => $this->item(AuditLevel::Ok, sprintf('retry_after is %d seconds, enough for a provider call of up to %d seconds (tolk.http.timeout.web).', $retryAfter, $timeout)),
            };
        }

        return array_values(array_filter($items));
    }

    private function cacheItems(): array
    {
        $name = (string) (config('tolk.cache_store') ?: config('cache.default'));
        $config = config("cache.stores.{$name}");

        if (! is_array($config)) {
            return [$this->item(AuditLevel::Error, "The cache store [{$name}] is not configured in config/cache.php.")];
        }

        $label = $this->named($name, is_string($config['driver'] ?? null) ? $config['driver'] : 'no driver');

        if (in_array($config['driver'] ?? null, self::UNSHARED_CACHES, true)) {
            return [$this->item(AuditLevel::Error, "The cache store {$label} is not shared between web requests, queue workers and the command line, so they lose each other's plans and do not keep each other from saving at once. Use a store such as file, database or redis (tolk.cache_store).")];
        }

        try {
            $store = Cache::store($name)->getStore();
        } catch (Throwable $exception) {
            return [$this->item(AuditLevel::Error, "The cache store {$label} cannot be used: ".Sanitizer::message($exception->getMessage()))];
        }

        if (! $store instanceof LockProvider) {
            return [$this->item(AuditLevel::Error, "The cache store {$label} does not support locks, which keep two processes from saving content at once. Use a store such as file, database or redis (tolk.cache_store).")];
        }

        return [$this->item(AuditLevel::Ok, "Cache store: {$label}, with locks.".(($config['driver'] ?? null) === 'file' ? ' A file cache is shared only by the processes of one server.' : ''))];
    }

    private function stateItems(): array
    {
        $items = [];
        $driver = config('tolk.state.driver');
        $path = rtrim((string) config('tolk.state.path'), '/\\');

        if ($driver !== 'file') {
            $items[] = $this->item(AuditLevel::Error, sprintf('The state driver [%s] does not exist. Tolk keeps state with the file driver (tolk.state.driver).', is_scalar($driver) ? $driver : get_debug_type($driver)));
        }

        if ($path === '') {
            return [...$items, $this->item(AuditLevel::Error, 'No folder is set for the state files (tolk.state.path).')];
        }

        $existing = $path;

        while (! file_exists($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }

        $items[] = is_dir($existing) && is_writable($existing)
            ? $this->item(AuditLevel::Ok, 'State files: '.$this->relativePath($path).'.')
            : $this->item(AuditLevel::Error, 'Tolk cannot write the state files to '.$this->relativePath($path).'. Make the folder writable, or set another one (tolk.state.path).');

        if (config('statamic.git.enabled')) {
            $tracked = collect(config('statamic.git.paths'))
                ->filter(fn ($tracked) => is_string($tracked) && $tracked !== '')
                ->contains(fn (string $tracked) => str_starts_with($path.'/', rtrim($this->absolutePath($tracked), '/\\').'/'));

            $items[] = $tracked
                ? $this->item(AuditLevel::Ok, "Statamic's git integration tracks the state files, so they are committed with the content.")
                : $this->item(AuditLevel::Warning, "Statamic's git integration does not track ".$this->relativePath($path).', so the state is not committed with the content it describes. Add the folder to statamic.git.paths.');
        }

        return $items;
    }

    private function contentSiteItems(): array
    {
        $items = [];

        $containers = [
            'Collection' => Collection::all()->reject(fn ($collection) => in_array($collection->handle(), $this->settings->excludedCollections(), true)),
            'Taxonomy' => Taxonomy::all()->reject(fn ($taxonomy) => in_array($taxonomy->handle(), $this->settings->excludedTaxonomies(), true)),
            'Global set' => GlobalSet::all(),
        ];

        foreach ($containers as $type => $all) {
            foreach ($all as $container) {
                $missing = Site::all()->keys()->diff($container->sites())->values()->all();

                if ($missing !== []) {
                    $items[] = $this->item(AuditLevel::Warning, sprintf('%s %s is not enabled for %s, so it is not translated into %s.', $type, $container->title(), $this->siteNames($missing), count($missing) === 1 ? 'it' : 'them'));
                }

                if ($type === 'Global set' && $container->sites()->count() > 1) {
                    array_push($items, ...$this->globalSetItems($container));
                }
            }
        }

        return $items;
    }

    /**
     * A global set without a blueprint, and its sites that name no origin and are not the source of another
     * (GlobalsTarget::untranslatableReasonFor(), as RunPlanner reads it).
     */
    private function globalSetItems(GlobalSetContract $set): array
    {
        if ($set->blueprint() === null) {
            return [$this->item(AuditLevel::Warning, "Global set {$set->title()} has no blueprint, so Statamic treats its values as text fields that are not localizable and Tolk translates none of them. Create a blueprint with localizable fields for it.")];
        }

        $orphans = $set->sites()
            ->filter(fn (string $site) => GlobalsTarget::needsOrigin($set, $site))
            ->values()
            ->all();

        return $orphans === [] ? [] : [$this->item(AuditLevel::Warning, sprintf(
            'Global set %s names no origin for %s, so Tolk does not translate %s. Choose the site to translate from as the origin in the set\'s sites.',
            $set->title(),
            $this->siteNames($orphans),
            count($orphans) === 1 ? 'it' : 'them',
        ))];
    }

    private function informationItems(): array
    {
        $revisions = collect($this->scanner->containers(ContentType::Entry))
            ->map(fn (string $handle) => Collection::find($handle))
            ->filter->revisionsEnabled()
            ->map->title();

        $strategy = config('statamic.static_caching.strategy');
        $lanes = RunDispatcher::browserLanes();

        return [
            $this->item(AuditLevel::Ok, $revisions->isEmpty()
                ? 'Revisions: off for the collections Tolk translates, so translations are saved directly.'
                : 'Revisions: on for '.$revisions->implode(', ').'. A new localization gets a revision, translations of a published localization go to its working copy, and "publish changes" (--publish-changes) publishes them only when it had no working copy before.'),
            $this->item(AuditLevel::Ok, match (true) {
                ! config('statamic.git.enabled') => 'Git integration: off. Commit the state files together with the content.',
                (bool) config('statamic.git.automatic') => 'Git integration: on, with automatic commits. Tolk saves without Statamic\'s commit listener and dispatches one commit ("'.ContentWriter::COMMIT_MESSAGE.'") once a run or command has saved content.',
                default => 'Git integration: on, without automatic commits. Commit translated content and the state files yourself.',
            }),
            $this->item(AuditLevel::Ok, is_string($strategy) && $strategy !== '' && $strategy !== 'null'
                ? "Static caching: {$strategy}. Tolk saves content with Statamic's events, so your invalidation rules clear cached pages as after a save in the Control Panel."
                : 'Static caching: off.'),
            $this->item(AuditLevel::Ok, sprintf('A run driven by the browser keeps %d PHP workers busy while its page is open: %d %s (tolk.concurrency.browser) and the apply loop.', $lanes + 1, $lanes, $lanes === 1 ? 'lane' : 'lanes')),
        ];
    }

    /**
     * The blueprints of the collections and taxonomies Tolk translates (StatusScanner::containers()), and of the
     * global sets among them that have one, with the context TranslatableFields::explain() needs (§A2).
     *
     * @return list<array{type: string, name: string, container: string, blueprint: Blueprint, context: array}>
     */
    private function blueprints(): array
    {
        $excludedFields = $this->settings->excludedFields();
        $context = fn (bool $autoTitle = false) => ['auto_title' => $autoTitle, 'excluded_fields' => $excludedFields];
        $blueprints = [];

        foreach ($this->scanner->containers(ContentType::Entry) as $handle) {
            $collection = Collection::find($handle);

            foreach ($collection->entryBlueprints() as $blueprint) {
                $blueprints[] = ['type' => 'collection', 'name' => "Collection {$collection->title()} › {$blueprint->title()}", 'container' => $handle, 'blueprint' => $blueprint, 'context' => $context($collection->autoGeneratesTitles())];
            }
        }

        foreach ($this->scanner->containers(ContentType::Term) as $handle) {
            $taxonomy = Taxonomy::find($handle);

            foreach ($taxonomy->termBlueprints() as $blueprint) {
                $blueprints[] = ['type' => 'taxonomy', 'name' => "Taxonomy {$taxonomy->title()} › {$blueprint->title()}", 'container' => $handle, 'blueprint' => $blueprint, 'context' => $context()];
            }
        }

        foreach ($this->scanner->containers(ContentType::GlobalSet) as $handle) {
            $set = GlobalSet::find($handle);

            if ($blueprint = $set->blueprint()) {
                $blueprints[] = ['type' => 'globals', 'name' => "Global set {$set->title()}", 'container' => $handle, 'blueprint' => $blueprint, 'context' => $context()];
            }
        }

        return $blueprints;
    }

    private function findBlueprint(string $namespace, string $handle): ?Blueprint
    {
        [$type, $container] = array_pad(explode('.', $namespace, 2), 2, '');

        $blueprints = match (true) {
            $type === 'collections' && $container !== '' => Collection::find($container)?->entryBlueprints(),
            $type === 'taxonomies' && $container !== '' => Taxonomy::find($container)?->termBlueprints(),
            $type === 'globals' && $container === '' => collect([GlobalSet::find($handle)?->blueprint()]),
            default => null,
        };

        return $blueprints?->filter()->first(fn (Blueprint $blueprint) => $blueprint->handle() === $handle);
    }

    /**
     * The top-level fields that come from an imported fieldset, as handle => fieldset handle.
     *
     * @return array<string, string>
     */
    private function importedFields(Blueprint $blueprint): array
    {
        $imported = [];

        foreach ($blueprint->contents()['tabs'] ?? [] as $tab) {
            foreach ($tab['sections'] ?? [] as $section) {
                foreach ($section['fields'] ?? [] as $field) {
                    if (isset($field['import'])) {
                        foreach ((new Fields([$field]))->all()->keys() as $handle) {
                            $imported[$handle] = $field['import'];
                        }
                    }
                }
            }
        }

        return $imported;
    }

    /**
     * @param  list<string>  $names  how the item names each field
     */
    private function blueprintItem(array $source, array $fields, array $names): array
    {
        return [
            ...$this->item(AuditLevel::Warning, $source['name'].': '.implode(', ', $names).'.'),
            'type' => $source['type'],
            'container' => $source['container'],
            'namespace' => (string) $source['blueprint']->namespace(),
            'blueprint' => (string) $source['blueprint']->handle(),
            'name' => $source['name'],
            'fields' => $fields,
        ];
    }

    private function check(string $id, string $title, string $detail, array $items): array
    {
        return [
            'id' => $id,
            'level' => AuditLevel::worst(...array_map(fn (array $item) => AuditLevel::from($item['level']), $items))->value,
            'title' => $title,
            'detail' => $detail,
            'items' => $items,
        ];
    }

    private function item(AuditLevel $level, string $text): array
    {
        return ['level' => $level->value, 'text' => $text];
    }

    // A queue connection or cache store with its driver, when its name is not the driver's.
    private function named(string $name, string $driver): string
    {
        return $name === $driver ? $name : "{$name} ({$driver})";
    }

    private function modelName(ModelRef $model): string
    {
        return "{$this->catalog->label($model)} ({$model})";
    }

    private function siteName(string $handle): string
    {
        return (Site::get($handle)?->name() ?? $handle)." ({$handle})";
    }

    /**
     * @param  list<string>  $handles
     */
    private function siteNames(array $handles): string
    {
        return implode(', ', array_map($this->siteName(...), $handles));
    }

    private function absolutePath(string $path): string
    {
        $path = Path::tidy($path);

        return Path::isAbsolute($path) ? $path : base_path($path);
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), '/\\').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
