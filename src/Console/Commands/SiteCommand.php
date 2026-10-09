<?php

namespace Reach\StatamicTolk\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\Providers;
use Reach\StatamicTolk\Console\Concerns\OffersLocalizableFields;
use Reach\StatamicTolk\Console\Concerns\ResolvesTranslationOptions;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Exceptions\ProviderNotConfigured;
use Reach\StatamicTolk\Support\Audit;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Support\SiteSetup;
use Reach\StatamicTolk\Translation\PlanOptions;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;

/**
 * Translates the whole website into one site: a preflight that enables the site where content cannot reach it and
 * offers to make text fields localizable, each step with its own question, then tolk:translate with everything selected
 * in mode missing.
 */
class SiteCommand extends Command
{
    use OffersLocalizableFields;
    use ResolvesTranslationOptions;
    use RunsInPlease;

    protected $signature = 'tolk:site
        {site : The site to translate the website into}
        {--from= : The site to translate a missing localization from, where the entry has one there, and a navigation from, where it has a tree there; also the origin of the global sets this command enables for the site (default: the origin, and the default site)}
        {--publish : Publish a new localization when its origin is published, instead of saving it as a draft}
        {--translator= : The model to translate with, as provider:model}
        {--review : Review the translations with a second model and apply its corrections (default from the settings)}
        {--no-review : Do not review the translations, whatever the settings say}
        {--reviewer= : The model to review with, as provider:model (implies --review)}
        {--concurrency= : The worker processes that translate in parallel (default from tolk.concurrency.cli; 1 translates in this process)}
        {--queue : Hand the run to the queue and print its address in the Control Panel, instead of running it here}
        {--dry-run : Show what would be changed and translated, without changing the configuration, calling the model or saving anything}
        {--yes : Answer every question with its default: enable the site where it is missing, leave blueprints as they are, and translate}';

    protected $description = 'Translate the whole website into one site';

    /** @var array<string, ?string> `{type}:{handle}` of the containers a dry run would enable for the site, with a global set's origin (RunRequest::$assumedSites) */
    private array $wouldEnable = [];

    public function handle(Settings $settings, ModelCatalog $catalog, Providers $providers, SiteSetup $setup, Audit $audit): int
    {
        $this->wouldEnable = [];
        $site = (string) $this->argument('site');

        if (Site::get($site) === null) {
            $this->components->error("There is no site [{$site}]. Add it under Sites in the Control Panel, then run this command again.");

            return self::FAILURE;
        }

        try {
            $from = $this->sourceSite();

            if ($site === ($from ?? Site::default()->handle())) {
                throw new InvalidArgumentException("{$this->siteLabel($site)} is the site the others are translated from. Name the site to translate into.");
            }

            $review = $this->review(Mode::Missing);
            $this->concurrency();
            $this->checkQueue();
            $this->checkProviders(PlanOptions::fromSettings($settings, $catalog, [
                'translator' => $this->option('translator') ?: null,
                'mode' => Mode::Missing,
                'review' => $review,
                'reviewer' => $this->option('reviewer') ?: null,
                'profile' => 'background',
            ]), $providers);
        } catch (InvalidArgumentException|MissingDefaultModel|NoProviderConfigured|ProviderNotConfigured $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line("Translating the whole website into {$this->siteLabel($site)}.");

        $this->enableContainers($setup, $site);
        $this->enableGlobalSets($setup, $site, $from);
        $this->warnAboutRoutes($setup, $site);
        $this->offerLocalizableFields($audit, $site);

        if (! $this->acceptTechnicalFields($audit, $site)) {
            $this->components->warn('Nothing was translated. Switch off "Translate automatically" for the fields that hold data, then run this command again.');

            return self::SUCCESS;
        }

        $this->newLine();

        $exitCode = $this->call($this->laravel->make(TranslateCommand::class)->assumingSites($this->wouldEnable), array_filter([
            '--all' => true,
            '--site' => [$site],
            '--mode' => Mode::Missing->value,
            '--from' => $from,
            '--publish' => $this->option('publish'),
            '--translator' => $this->option('translator'),
            '--review' => $this->option('review'),
            '--no-review' => $this->option('no-review'),
            '--reviewer' => $this->option('reviewer'),
            '--concurrency' => $this->option('concurrency'),
            '--queue' => $this->option('queue'),
            '--dry-run' => $this->option('dry-run'),
            '--yes' => $this->option('yes'),
        ], fn ($value) => $value !== null && $value !== false && $value !== ''));

        $this->notCovered($site);

        return $exitCode;
    }

    /**
     * Step 2: the collections and taxonomies that are not enabled for the site.
     */
    private function enableContainers(SiteSetup $setup, string $site): void
    {
        $containers = $setup->containersWithout($site);

        if ($containers === []) {
            return;
        }

        $this->newLine();
        $this->components->warn("These collections and taxonomies are not enabled for {$this->siteLabel($site)}, so their content is not translated into it:");
        $this->components->bulletList(array_map(fn (array $container) => "{$container['name']} ({$container['handle']})", $containers));

        if ($this->option('dry-run')) {
            foreach ($containers as $container) {
                $this->wouldEnable["{$container['type']}:{$container['handle']}"] = null;
            }

            $this->line("  Dry run: nothing is changed. Without --dry-run, this command offers to enable {$this->siteLabel($site)} for them.");

            return;
        }

        if (! $this->option('yes') && ! $this->confirm("Enable {$this->siteLabel($site)} for them?", true)) {
            $this->line('  They are left as they are, so their content is not translated.');

            return;
        }

        foreach ($containers as $container) {
            $setup->enable($container['type'], $container['handle'], $site);
        }

        $this->components->info("{$this->siteLabel($site)} is now enabled for them.");
    }

    /**
     * Step 3: the global sets that are not enabled for the site, or name no origin for it.
     */
    private function enableGlobalSets(SiteSetup $setup, string $site, ?string $from): void
    {
        $sets = $setup->globalSetsWithout($site, $from);

        if ($sets === []) {
            return;
        }

        $this->newLine();
        $this->components->warn("These global sets do not translate {$this->siteLabel($site)}:");
        $this->components->bulletList(array_map(fn (array $set) => sprintf(
            '%s (%s): %s',
            $set['name'],
            $set['handle'],
            match (true) {
                $set['origin'] === null => 'it has no other site to translate from',
                $set['enabled'] => "it names no origin for the site; it would be translated from {$this->siteLabel($set['origin'])}",
                default => "not enabled for the site; it would be translated from {$this->siteLabel($set['origin'])}",
            },
        ), $sets));

        $fixable = array_values(array_filter($sets, fn (array $set) => $set['origin'] !== null));

        if ($fixable === []) {
            return;
        }

        if ($this->option('dry-run')) {
            foreach ($fixable as $set) {
                $this->wouldEnable["globals:{$set['handle']}"] = $set['origin'];
            }

            $this->line("  Dry run: nothing is changed. Without --dry-run, this command offers to enable {$this->siteLabel($site)} for them, with these origins.");

            return;
        }

        if (! $this->option('yes') && ! $this->confirm("Enable {$this->siteLabel($site)} for them, with these origins?", true)) {
            $this->line('  They are left as they are, so they are not translated.');

            return;
        }

        foreach ($fixable as $set) {
            $setup->enableGlobalSet($set['handle'], $site, $set['origin']);
        }

        $this->components->info("{$this->siteLabel($site)} is now enabled for them, with these origins.");
    }

    /**
     * Step 4: collections with a route per site but none for the site.
     */
    private function warnAboutRoutes(SiteSetup $setup, string $site): void
    {
        foreach ($setup->collectionsWithoutRoute($site) as $collection) {
            $this->newLine();
            $this->components->warn(sprintf(
                '%s has a route for each site but none for %s, so its entries there have no URL. Add a route for the site in the collection\'s settings.',
                $collection['name'],
                $this->siteLabel($site),
            ));
        }
    }

    /**
     * Step 5: text fields that are not localizable, which every site shares, so they are not translated; asked per
     * blueprint, and left alone by --yes.
     */
    private function offerLocalizableFields(Audit $audit, string $site): void
    {
        $items = $this->forSite($audit->notLocalizable($this->wouldEnable), $site);

        if ($items === []) {
            return;
        }

        $this->newLine();
        $this->components->warn('These text fields are not localizable, so every site shares the origin\'s text and they are not translated:');
        $this->components->bulletList(array_column($items, 'text'));

        if ($this->option('dry-run') || $this->option('yes')) {
            $this->line(sprintf(
                '  %s: they are left as they are. php please tolk:doctor --fix-localizable makes them localizable, blueprint by blueprint.',
                $this->option('dry-run') ? 'Dry run' : 'With --yes',
            ));

            return;
        }

        $this->offerLocalizable($audit, $items);
    }

    /**
     * Step 6: nested fields that look like data; changing them is left to the developer.
     */
    private function acceptTechnicalFields(Audit $audit, string $site): bool
    {
        $items = $this->forSite($audit->technicalFields($this->wouldEnable), $site);

        if ($items === []) {
            return true;
        }

        $this->newLine();
        $this->components->warn('These nested fields are translated, but their handles suggest identifiers, links or code. If one holds data rather than text, switch off "Translate automatically" in its field settings:');
        $this->components->bulletList(array_column($items, 'text'));

        return $this->option('dry-run') || $this->option('yes') || $this->confirm('Continue, and translate them as text?', true);
    }

    /**
     * The audit's items of the collections, taxonomies and global sets that are enabled for the site, or that a dry
     * run would enable.
     *
     * @param  list<array>  $items
     * @return list<array>
     */
    private function forSite(array $items, string $site): array
    {
        return array_values(array_filter($items, function (array $item) use ($site) {
            if (array_key_exists("{$item['type']}:{$item['container']}", $this->wouldEnable)) {
                return true;
            }

            $container = match ($item['type']) {
                'collection' => Collection::find($item['container']),
                'taxonomy' => Taxonomy::find($item['container']),
                default => GlobalSet::find($item['container']),
            };

            return (bool) $container?->sites()->contains($site);
        }));
    }

    private function notCovered(string $site): void
    {
        $this->newLine();
        $this->line('Not translated by this command:');
        $this->components->bulletList([
            'The alt text of assets, which a later version of Tolk translates.',
            "The app's translation files in lang/: php please tolk:lang {$site}",
            "The texts of forms: php please tolk:forms {$site}",
            'Fields of third-party fieldtypes, such as those of SEO addons, until a Tolk handler is registered for their fieldtype.',
        ]);
    }

    private function siteLabel(string $site): string
    {
        return (Site::get($site)?->name() ?? $site)." ({$site})";
    }
}
