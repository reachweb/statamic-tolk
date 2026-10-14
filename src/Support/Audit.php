<?php

namespace Reach\StatamicTolk\Support;

use Illuminate\Console\Signals;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Reach\StatamicTolk\Ai\FakeEngine;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Providers;
use Reach\StatamicTolk\Console\Commands\WorkCommand;
use Reach\StatamicTolk\Content\TranslatableFields;
use Reach\StatamicTolk\Enums\AuditLevel;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\RunDriver;
use Reach\StatamicTolk\Enums\Tier;
use Reach\StatamicTolk\Exceptions\InvalidModelRef;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Jobs\UpdateTranslations;
use Reach\StatamicTolk\Jobs\WorkRun;
use Reach\StatamicTolk\Runs\AutoUpdater;
use Reach\StatamicTolk\Runs\RunDispatcher;
use Reach\StatamicTolk\Runs\RunRequest;
use Reach\StatamicTolk\State\StatusScanner;
use Reach\StatamicTolk\Targets\GlobalsTarget;
use Statamic\Contracts\Globals\GlobalSet as GlobalSetContract;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Fields;
use Symfony\Component\Console\SignalRegistry\SignalRegistry;
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
     * @param  bool  $commandLine  also check the PHP of this process as the one the commands run on (tolk:doctor); the
     *                             Control Panel's PHP may be another
     * @return list<array{id: string, level: string, title: string, detail: string, items: list<array>}>
     */
    public function run(bool $commandLine = false): array
    {
        return array_values(array_filter([
            $this->check('sites', __('Sites'), __('Tolk translates content from one site into its localizations in the others, so it needs multi-site with at least two sites.'), $this->siteItems()),
            $this->check('providers', __('Providers and models'), __('Translating needs an API key for at least one provider, and every model chosen in the settings must use a provider that has one.'), $this->providerItems()),
            $this->check('queue', __('Queue'), __('Runs started in the Control Panel run as queue jobs when the queue connection has a worker, and are driven by the open run page otherwise. Each queue job must end before the connection hands it to another worker (retry_after).'), $this->queueItems()),
            $this->check('cache', __('Cache'), __('Plans, locks and progress live in one cache store, which must support locks and be shared by web requests, queue workers and the command line.'), $this->cacheItems()),
            $this->check('state', __('Translation state'), __('Tolk keeps what it knows about each translated field in files, which it must be able to write and which must stay with the content: in version control with content in files, in a folder that every deploy keeps with content in a database.'), $this->stateItems()),
            $this->check(self::LOCALIZABLE, __('Localizable fields'), __('A text field that is not localizable is shared by every site, so Tolk does not translate it. Run php please tolk:doctor --fix-localizable to make these fields localizable, blueprint by blueprint.'), $this->notLocalizable()),
            $this->check(self::TECHNICAL_FIELDS, __('Nested fields that look like data'), __('These nested fields are translated, but their handles suggest identifiers, links or code. If one holds data rather than text, switch off "Translate automatically" in its field settings.'), $this->technicalFields()),
            $this->check('content_sites', __('Sites of collections, taxonomies and global sets'), __('Content is translated only into the sites its collection, taxonomy or global set is enabled for, and a global set translates each site from the origin it names for it.'), $this->contentSiteItems()),
            $commandLine ? $this->check('command_line', __('Command line'), __('tolk:translate translates in worker processes and saves in its own. With PHP\'s pcntl extension, Ctrl-C cancels its run after the current requests and keeps what was saved; with its posix extension, the workers of a command that was killed cancel its run.'), $this->commandLineItems()) : null,
            $this->check('information', __('How Tolk works on this site'), __('Information only.'), $this->informationItems()),
        ]));
    }

    /**
     * The blueprints of the collections, taxonomies and global sets Tolk translates that have text fields that are not
     * localizable (§A2 reason `not_localizable`; a text field with another input type, such as url, holds no text). A
     * field from an imported fieldset names it as `fieldset`: makeLocalizable() leaves it alone.
     *
     * @param  array<string, ?string>  $assumedSites  containers to cover although they have one site (RunRequest::$assumedSites)
     * @return list<array{level: string, text: string, type: string, container: string, namespace: string, blueprint: string, name: string, fields: list<array{handle: string, label: string, type: string, fieldset: ?string}>}>
     */
    public function notLocalizable(array $assumedSites = []): array
    {
        $items = [];

        foreach ($this->blueprints($assumedSites) as $source) {
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
                $items[] = $this->blueprintItem($source, $fields, array_map(fn (array $field) => $field['fieldset'] === null
                    ? "{$field['label']} ({$field['handle']})"
                    : __(':label (:handle, from the fieldset :fieldset)', ['label' => $field['label'], 'handle' => $field['handle'], 'fieldset' => $field['fieldset']]), $fields));
            }
        }

        return $items;
    }

    /**
     * The blueprints with nested fields that would be translated although their handles look technical
     * (TranslatableFields::nestedTechnicalFields()).
     *
     * @param  array<string, ?string>  $assumedSites  containers to cover although they have one site (RunRequest::$assumedSites)
     * @return list<array{level: string, text: string, type: string, container: string, namespace: string, blueprint: string, name: string, fields: list<array{path: string, handle: string, type: string, label: string}>}>
     */
    public function technicalFields(array $assumedSites = []): array
    {
        $items = [];

        foreach ($this->blueprints($assumedSites) as $source) {
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
            return [$this->item(AuditLevel::Error, __('Multi-site is off (statamic.system.multisite), so there is no site to translate into.'))];
        }

        $names = Site::all()->map(fn ($site) => $this->siteName($site->handle()))->values();

        if ($names->count() < 2) {
            return [$this->item(AuditLevel::Error, __(':site is the only site. Add a site for each language under Sites in the Control Panel.', ['site' => $names->first()]))];
        }

        return [$this->item(AuditLevel::Ok, __(':count sites: :sites. :default is the default site.', ['count' => $names->count(), 'sites' => $names->implode(', '), 'default' => Site::default()->name()]))];
    }

    private function providerItems(): array
    {
        $items = [];
        $fake = FakeEngine::enabled();
        $missingKey = $fake ? AuditLevel::Warning : AuditLevel::Error;
        $configured = $this->providers->configured();

        if ($fake) {
            $items[] = $this->item(AuditLevel::Warning, __('The fake engine is on (TOLK_FAKE_ENGINE): nothing is sent to a provider, and every translation is the source text marked with its language.'));
        } elseif (filter_var(config('tolk.fake_engine'), FILTER_VALIDATE_BOOL)) {
            $items[] = $this->item(AuditLevel::Warning, __('TOLK_FAKE_ENGINE is set, but the fake engine runs only in the local and testing environments, so it is off here. Remove the setting.'));
        }

        $items[] = $configured === []
            ? $this->item($missingKey, $fake
                ? __('No provider has an API key, so only the fake engine can translate.')
                : __('No provider has an API key. Set ANTHROPIC_API_KEY, OPENAI_API_KEY or GEMINI_API_KEY in .env.'))
            : $this->item(AuditLevel::Ok, __('API keys: :providers.', ['providers' => implode(', ', array_map($this->keyName(...), $configured))]));

        foreach (ModelRef::PROVIDERS as $provider) {
            $name = $this->providers->name($provider);

            if (config("ai.providers.{$name}") !== null) {
                $items[] = $this->item(AuditLevel::Error, __('config/ai.php configures a provider named :name. Tolk builds a provider of that name itself, and the Laravel AI SDK refuses to build one the app configures, so every translation with :provider fails. Rename it.', ['name' => $name, 'provider' => $provider]));
            }
        }

        foreach ($configured as $provider) {
            try {
                $this->catalog->default($provider, Tier::Quality);
            } catch (MissingDefaultModel $exception) {
                $items[] = $this->item(AuditLevel::Error, __(':message Tolk uses it whenever no model is chosen in the settings. Add one to tolk.models in config/tolk.php.', ['message' => $exception->getMessage()]));
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
            [__('Translator model'), $this->settings->translatorModel(...)],
            [__('Reviewer model'), $this->settings->reviewerModel(...)],
            [__('Fallback model'), $this->settings->fallbackModel(...)],
        ];

        foreach ($readers as [$label, $read]) {
            try {
                $models[] = $read();
            } catch (InvalidModelRef $exception) {
                $models[] = false;
                $items[] = $this->item(AuditLevel::Error, __('The setting ":setting" is not a model: :message Choose a model in Tolk\'s settings.', ['setting' => $label, 'message' => $exception->getMessage()]));
            }
        }

        [$chosen, $reviewerChosen, $fallback] = $models;

        if ($chosen && ! $this->providers->isConfigured($chosen->provider)) {
            $items[] = $this->item($missingKey, __('The translator model :model uses :provider, which has no API key, so every translation fails.', ['model' => (string) $chosen, 'provider' => $chosen->provider]));
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

        $items[] = $this->item(AuditLevel::Ok, $chosen
            ? __('Translator: :model.', ['model' => $this->modelName($translator)])
            : __('Translator: :model, the default, because no translator model is chosen.', ['model' => $this->modelName($translator)]));

        if ($reviewerChosen !== false) {
            $review = $this->settings->reviewEnabled();

            if ($reviewerChosen && ! $this->providers->isConfigured($reviewerChosen->provider)) {
                $replace = ['model' => (string) $reviewerChosen, 'provider' => $reviewerChosen->provider];
                $items[] = $review
                    ? $this->item($missingKey, __('The reviewer model :model uses :provider, which has no API key, so every review fails.', $replace))
                    : $this->item(AuditLevel::Warning, __('The reviewer model :model uses :provider, which has no API key, so every review fails. Review is off by default, but an operation that asks for one fails.', $replace));
            }

            try {
                $replace = ['model' => $this->modelName($this->catalog->resolveReviewer($this->settings, $translator))];
                $items[] = $this->item(AuditLevel::Ok, $review
                    ? __('Reviewer: :model. Review is on by default.', $replace)
                    : __('Reviewer: :model. Review is off by default.', $replace));
            } catch (MissingDefaultModel) {
                // Reported with the providers.
            }
        }

        if ($fallback) {
            $items[] = match (true) {
                ! $this->providers->isConfigured($fallback->provider) => $this->item(AuditLevel::Warning, __('The fallback model :model uses :provider, which has no API key, so Tolk ignores it.', ['model' => (string) $fallback, 'provider' => $fallback->provider])),
                (string) $fallback === (string) $translator => $this->item(AuditLevel::Warning, __('The fallback model :model is the translator model, so Tolk ignores it.', ['model' => (string) $fallback])),
                default => $this->item(AuditLevel::Ok, __('Fallback: :model.', ['model' => $this->modelName($fallback)])),
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
            return [$this->item(AuditLevel::Error, __('The queue connection [:connection] is not configured in config/queue.php.', ['connection' => $connection]))];
        }

        $items = [$this->item(AuditLevel::Ok, __('Queue connection: :connection.', ['connection' => $this->named($connection, $driver)]))];

        try {
            $runDriver = RunDispatcher::resolveDriver();
        } catch (InvalidArgumentException $exception) {
            $runDriver = null;
            $items[] = $this->item(AuditLevel::Error, $exception->getMessage());
        }

        $items[] = match (true) {
            $runDriver === RunDriver::Queue && ! RunDispatcher::hasWorker() => $this->item(AuditLevel::Error, __('tolk.runs.execution is queue, but the connection [:connection] has no worker of its own, so a run would be worked through inside the request that starts it. Use a queue with a worker, or set tolk.runs.execution to auto or browser.', ['connection' => $connection])),
            $runDriver === RunDriver::Queue => $this->item(AuditLevel::Ok, __('Runs started in the Control Panel run as queue jobs on :connection. Keep a queue worker running.', ['connection' => $connection])),
            $runDriver === RunDriver::Browser => $this->item(AuditLevel::Ok, __('Runs started in the Control Panel are driven by their run page, which must stay open until the run ends.')),
            default => null,
        };

        if ($runDriver !== null && $this->settings->autoUpdate()) {
            $items[] = AutoUpdater::queued()
                ? $this->item(AuditLevel::Ok, __('Automatic updates are on: saving a published entry starts a run of it on :connection after :seconds seconds.', ['connection' => $connection, 'seconds' => UpdateTranslations::DELAY_SECONDS]))
                : $this->item(AuditLevel::Ok, __('Automatic updates are on: saving a published entry runs its update in the PHP process that saved it, after the response. A queue worker keeps that process free.'));
        }

        if (RunDispatcher::hasWorker()) {
            $retryAfter = WorkRun::retryAfter();
            $timeout = (int) config('tolk.http.timeout.web');

            if ($driver === 'sqs' && ! isset($config['retry_after'])) {
                $items[] = $this->item(AuditLevel::Warning, __('The SQS connection sets no retry_after, so Tolk assumes :seconds seconds. Set retry_after on the connection to the queue\'s visibility timeout.', ['seconds' => WorkRun::RETRY_AFTER]));
            }

            $items[] = match (true) {
                $retryAfter <= $timeout + 20 => $this->item(AuditLevel::Error, __('retry_after is :seconds seconds, which leaves no room for a provider call of up to :timeout seconds (tolk.http.timeout.web): the queue would hand a run\'s job to a second worker while the first still works on it. Raise retry_after to at least :minimum seconds.', ['seconds' => $retryAfter, 'timeout' => $timeout, 'minimum' => $timeout + 40])),
                $retryAfter < $timeout + 40 => $this->item(AuditLevel::Warning, __('retry_after is :seconds seconds, so each job of a run starts provider calls for only :deadline seconds and the queue gets more jobs. Raise retry_after to at least :minimum seconds.', ['seconds' => $retryAfter, 'deadline' => WorkRun::deadlineSeconds(), 'minimum' => $timeout + 40])),
                default => $this->item(AuditLevel::Ok, __('retry_after is :seconds seconds, enough for a provider call of up to :timeout seconds (tolk.http.timeout.web).', ['seconds' => $retryAfter, 'timeout' => $timeout])),
            };
        }

        return array_values(array_filter($items));
    }

    private function cacheItems(): array
    {
        $name = (string) (config('tolk.cache_store') ?: config('cache.default'));
        $config = config("cache.stores.{$name}");

        if (! is_array($config)) {
            return [$this->item(AuditLevel::Error, __('The cache store [:store] is not configured in config/cache.php.', ['store' => $name]))];
        }

        $label = $this->named($name, is_string($config['driver'] ?? null) ? $config['driver'] : __('no driver'));

        if (in_array($config['driver'] ?? null, self::UNSHARED_CACHES, true)) {
            return [$this->item(AuditLevel::Error, __('The cache store :store is not shared between web requests, queue workers and the command line, so they lose each other\'s plans and do not keep each other from saving at once. Use a store such as file, database or redis (tolk.cache_store).', ['store' => $label]))];
        }

        try {
            $store = Cache::store($name)->getStore();
        } catch (Throwable $exception) {
            return [$this->item(AuditLevel::Error, __('The cache store :store cannot be used: :message', ['store' => $label, 'message' => Sanitizer::message($exception->getMessage())]))];
        }

        if (! $store instanceof LockProvider) {
            return [$this->item(AuditLevel::Error, __('The cache store :store does not support locks, which keep two processes from saving content at once. Use a store such as file, database or redis (tolk.cache_store).', ['store' => $label]))];
        }

        return [$this->item(AuditLevel::Ok, ($config['driver'] ?? null) === 'file'
            ? __('Cache store: :store, with locks. A file cache is shared only by the processes of one server.', ['store' => $label])
            : __('Cache store: :store, with locks.', ['store' => $label]))];
    }

    private function stateItems(): array
    {
        $items = [];
        $driver = config('tolk.state.driver');
        $path = rtrim((string) config('tolk.state.path'), '/\\');

        if ($driver !== 'file') {
            $items[] = $this->item(AuditLevel::Error, __('The state driver [:driver] does not exist. Tolk keeps state with the file driver (tolk.state.driver).', ['driver' => is_scalar($driver) ? (string) $driver : get_debug_type($driver)]));
        }

        if ($path === '') {
            return [...$items, $this->item(AuditLevel::Error, __('No folder is set for the state files (tolk.state.path).'))];
        }

        $existing = $path;

        while (! file_exists($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }

        $items[] = is_dir($existing) && is_writable($existing)
            ? $this->item(AuditLevel::Ok, __('State files: :path.', ['path' => $this->relativePath($path)]))
            : $this->item(AuditLevel::Error, __('Tolk cannot write the state files to :path. Make the folder writable, or set another one (tolk.state.path).', ['path' => $this->relativePath($path)]));

        $database = $this->databaseContent();

        if ($database !== []) {
            $replace = ['content' => implode(', ', $database), 'path' => $this->relativePath($path)];

            return [...$items, $this->insideRelease($existing)
                ? $this->item(AuditLevel::Warning, __('Statamic\'s Eloquent driver keeps content Tolk translates in the database (:content), but the state files are in :path, inside the site\'s folder. A deploy that replaces that folder, such as a new release folder, loses them: every translation then reads as edited by hand and is no longer updated. Set tolk.state.path to a folder that every deploy keeps, such as storage_path(\'statamic/tolk/state\'), and back it up with the database.', $replace))
                : $this->item(AuditLevel::Ok, __('Statamic\'s Eloquent driver keeps content Tolk translates in the database (:content), so the state files belong with it, not in git: keep them when you deploy, and back them up with the database.', $replace))];
        }

        if (! config('statamic.git.enabled')) {
            return $items;
        }

        $items[] = GitListeners::tracks($path)
            ? $this->item(AuditLevel::Ok, __('Statamic\'s git integration tracks the state files, so they are committed with the content.'))
            : $this->item(AuditLevel::Warning, __('Statamic\'s git integration does not track :path, so the state is not committed with the content it describes. Add the folder to statamic.git.paths.', ['path' => $this->relativePath($path)]));

        // Statamic's default git paths hold content/, where this state lives, but not lang/.
        if ($this->hasLanguageState($path)) {
            $lang = $this->relativePath(lang_path());
            $items[] = GitListeners::tracks(lang_path())
                ? $this->item(AuditLevel::Ok, __('Statamic\'s git integration tracks :path, so the translation files of tolk:lang and tolk:forms are committed with their state.', ['path' => $lang]))
                : $this->item(AuditLevel::Warning, __('Statamic\'s git integration does not track :path, so the translation files that tolk:lang and tolk:forms wrote are not committed with their state, and a deploy that replaces the site\'s files loses them. Add the folder to statamic.git.paths.', ['path' => $lang]));
        }

        return $items;
    }

    /**
     * Whether tolk:lang or tolk:forms has kept state: the folders of TargetRef::storageKey(), which the file driver
     * removes once they are empty.
     */
    private function hasLanguageState(string $path): bool
    {
        return is_dir($path.'/lang') || is_dir($path.'/forms');
    }

    /**
     * The content Tolk translates that Statamic's Eloquent driver stores in the database, read from the driver's
     * config as its service provider reads it (older installs name global variables and navigation trees by the keys
     * of global sets and navigations).
     *
     * @return list<string>
     */
    private function databaseContent(): array
    {
        $keys = [
            'entries' => __('entries'),
            'terms' => __('terms'),
            config()->has('statamic.eloquent-driver.global_sets.variables_model') ? 'global_sets' : 'global_set_variables' => __('global variables'),
            config()->has('statamic.eloquent-driver.navigations.tree_model') ? 'navigations' : 'navigation_trees' => __('navigation trees'),
        ];

        return array_values(array_filter($keys, fn (string $key) => config("statamic.eloquent-driver.{$key}.driver") === 'eloquent', ARRAY_FILTER_USE_KEY));
    }

    /**
     * Whether a folder lies in the app's folder outside its storage, which a deploy to a new release folder replaces
     * (storage is shared between releases, often through a symlink, which is why the paths are resolved).
     */
    private function insideRelease(string $folder): bool
    {
        $resolve = fn (string $path) => str_replace('\\', '/', rtrim(realpath($path) ?: $path, '/\\')).'/';
        $within = fn (string $root) => str_starts_with($resolve($folder), $resolve($root));

        return $within(base_path()) && ! $within(storage_path());
    }

    private function contentSiteItems(): array
    {
        $items = [];

        $containers = [
            'collection' => Collection::all()->reject(fn ($collection) => in_array($collection->handle(), $this->settings->excludedCollections(), true)),
            'taxonomy' => Taxonomy::all()->reject(fn ($taxonomy) => in_array($taxonomy->handle(), $this->settings->excludedTaxonomies(), true)),
            'globals' => GlobalSet::all(),
        ];

        foreach ($containers as $type => $all) {
            foreach ($all as $container) {
                $missing = Site::all()->keys()->diff($container->sites())->values()->all();

                if ($missing !== []) {
                    $replace = ['container' => $this->containerName($type, $container->title()), 'sites' => $this->siteNames($missing)];
                    $items[] = $this->item(AuditLevel::Warning, count($missing) === 1
                        ? __(':container is not enabled for :sites, so it is not translated into it.', $replace)
                        : __(':container is not enabled for :sites, so it is not translated into them.', $replace));
                }

                if ($type === 'globals' && $container->sites()->count() > 1) {
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
            return [$this->item(AuditLevel::Warning, __('Global set :title has no blueprint, so Statamic treats its values as text fields that are not localizable and Tolk translates none of them. Create a blueprint with localizable fields for it.', ['title' => $set->title()]))];
        }

        $orphans = $set->sites()
            ->filter(fn (string $site) => GlobalsTarget::needsOrigin($set, $site))
            ->values()
            ->all();

        if ($orphans === []) {
            return [];
        }

        $replace = ['title' => $set->title(), 'sites' => $this->siteNames($orphans)];

        return [$this->item(AuditLevel::Warning, count($orphans) === 1
            ? __('Global set :title names no origin for :sites, so Tolk does not translate it. Choose the site to translate from as the origin in the set\'s sites.', $replace)
            : __('Global set :title names no origin for :sites, so Tolk does not translate them. Choose the site to translate from as the origin in the set\'s sites.', $replace))];
    }

    /**
     * Whether tolk:translate can trap Ctrl-C, by the rules its trap() follows: Laravel's (pcntl, on the command line) and
     * Symfony's (pcntl_signal() not disabled, TrapsSignals); and whether its workers can tell that it was killed
     * (WorkCommand::watchesCommand(): posix).
     */
    private function commandLineItems(): array
    {
        $signals = false;
        Signals::whenAvailable(function () use (&$signals) {
            $signals = true;
        });

        return [
            match (true) {
                $signals && SignalRegistry::isSupported() => $this->item(AuditLevel::Ok, __('PHP on the command line has the pcntl extension, so Ctrl-C cancels a run of tolk:translate after the current requests.')),
                $signals => $this->item(AuditLevel::Warning, __('PHP on the command line has the pcntl extension, but disable_functions in php.ini disables pcntl_signal, so Ctrl-C ends tolk:translate and its workers at once, as without pcntl: the requests under way are lost, and the run stays running until you resume it on its run page or tolk:prune stops it. Stop a run with Cancel on its run page instead, or take pcntl_signal out of disable_functions.')),
                default => $this->item(AuditLevel::Warning, __('PHP on the command line has no pcntl extension (Windows never has it), so Ctrl-C ends tolk:translate and its workers at once: the requests under way are lost, and the run stays running until you resume it on its run page or tolk:prune stops it. Stop a run with Cancel on its run page instead, or add pcntl to PHP.')),
            },
            WorkCommand::watchesCommand()
                ? $this->item(AuditLevel::Ok, __('PHP on the command line has the posix extension, so the workers of a tolk:translate that was killed (kill -9, the out-of-memory killer) cancel its run before their next request.'))
                : $this->item(AuditLevel::Warning, __('PHP on the command line has no posix extension (Windows never has it), so the workers of a tolk:translate that was killed (kill -9, the out-of-memory killer) go on translating every item left, which the provider charges for and nothing saves. Press Cancel on the run page then, and Resume to save what they translated; or add posix to PHP.')),
        ];
    }

    private function informationItems(): array
    {
        $revisions = collect($this->scanner->containers(ContentType::Entry))
            ->map(fn (string $handle) => Collection::find($handle))
            ->filter->revisionsEnabled()
            ->map->title();

        $strategy = config('statamic.static_caching.strategy');
        $lanes = RunDispatcher::browserLanes();
        // As the state check says: with content in the database, the state files do not go into git.
        $database = $this->databaseContent() !== [];

        return [
            $this->item(AuditLevel::Ok, $revisions->isEmpty()
                ? __('Revisions: off for the collections Tolk translates, so translations are saved directly.')
                : __('Revisions: on for :collections. A new localization gets a revision, translations of a published localization go to its working copy, and "publish changes" (--publish-changes) publishes them only when it had no working copy before.', ['collections' => $revisions->implode(', ')])),
            $this->item(AuditLevel::Ok, match (true) {
                ! config('statamic.git.enabled') && $database => __('Git integration: off. With content in the database, the state files stay out of git too: keep them when you deploy, and back them up with the database.'),
                ! config('statamic.git.enabled') => __('Git integration: off. Commit the state files together with the content.'),
                (bool) config('statamic.git.automatic') => __('Git integration: on, with automatic commits. Tolk saves without Statamic\'s commit listener and dispatches one commit (":commit") once a run or command has saved content.', ['commit' => ContentWriter::COMMIT_MESSAGE]),
                $database => __('Git integration: on, without automatic commits. Commit translated content yourself, but not the state files, which stay with the content in the database: keep them when you deploy, and back them up with the database.'),
                default => __('Git integration: on, without automatic commits. Commit translated content and the state files yourself.'),
            }),
            $this->item(AuditLevel::Ok, is_string($strategy) && $strategy !== '' && $strategy !== 'null'
                ? __('Static caching: :strategy. Tolk saves content with Statamic\'s events, so your invalidation rules clear cached pages as after a save in the Control Panel.', ['strategy' => $strategy])
                : __('Static caching: off.')),
            $this->item(AuditLevel::Ok, $lanes === 1
                ? __('A run driven by the browser keeps :workers PHP workers busy while its page is open: :lanes lane (tolk.concurrency.browser) and the apply loop.', ['workers' => $lanes + 1, 'lanes' => $lanes])
                : __('A run driven by the browser keeps :workers PHP workers busy while its page is open: :lanes lanes (tolk.concurrency.browser) and the apply loop.', ['workers' => $lanes + 1, 'lanes' => $lanes])),
        ];
    }

    /**
     * The blueprints of the collections and taxonomies Tolk translates (StatusScanner::containers()), and of the
     * global sets among them that have one, with the context TranslatableFields::explain() needs (§A2).
     *
     * @param  array<string, ?string>  $assumedSites  containers to add (RunRequest::$assumedSites)
     * @return list<array{type: string, name: string, container: string, blueprint: Blueprint, context: array}>
     */
    private function blueprints(array $assumedSites = []): array
    {
        $excludedFields = $this->settings->excludedFields();
        $context = fn (bool $autoTitle = false) => ['auto_title' => $autoTitle, 'excluded_fields' => $excludedFields];
        $containers = fn (ContentType $type, string $assumed) => array_values(array_unique([...$this->scanner->containers($type), ...RunRequest::assumed($assumedSites, $assumed)]));
        $blueprints = [];

        foreach ($containers(ContentType::Entry, 'collection') as $handle) {
            $collection = Collection::find($handle);

            foreach ($collection->entryBlueprints() as $blueprint) {
                $blueprints[] = ['type' => 'collection', 'name' => __('Collection :title › :blueprint', ['title' => $collection->title(), 'blueprint' => $blueprint->title()]), 'container' => $handle, 'blueprint' => $blueprint, 'context' => $context($collection->autoGeneratesTitles())];
            }
        }

        foreach ($containers(ContentType::Term, 'taxonomy') as $handle) {
            $taxonomy = Taxonomy::find($handle);

            foreach ($taxonomy->termBlueprints() as $blueprint) {
                $blueprints[] = ['type' => 'taxonomy', 'name' => __('Taxonomy :title › :blueprint', ['title' => $taxonomy->title(), 'blueprint' => $blueprint->title()]), 'container' => $handle, 'blueprint' => $blueprint, 'context' => $context()];
            }
        }

        foreach ($containers(ContentType::GlobalSet, 'globals') as $handle) {
            $set = GlobalSet::find($handle);

            if ($blueprint = $set->blueprint()) {
                $blueprints[] = ['type' => 'globals', 'name' => $this->containerName('globals', $set->title()), 'container' => $handle, 'blueprint' => $blueprint, 'context' => $context()];
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
            ...$this->item(AuditLevel::Warning, __(':blueprint: :fields.', ['blueprint' => $source['name'], 'fields' => implode(', ', $names)])),
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

    private function containerName(string $type, string $title): string
    {
        return match ($type) {
            'collection' => __('Collection :title', ['title' => $title]),
            'taxonomy' => __('Taxonomy :title', ['title' => $title]),
            default => __('Global set :title', ['title' => $title]),
        };
    }

    // A queue connection or cache store with its driver, when its name is not the driver's.
    private function named(string $name, string $driver): string
    {
        return $name === $driver ? $name : "{$name} ({$driver})";
    }

    // A provider with the config its key comes from: Tolk's own (TOLK_*_API_KEY), which wins, or config/ai.php.
    private function keyName(string $provider): string
    {
        $config = $this->providers->status($provider)['source'] === 'addon' ? 'tolk' : 'ai';

        return "{$provider} ({$config}.providers.{$provider}.key)";
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

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), '/\\').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
