<?php

namespace Reach\StatamicTolk\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Reach\StatamicTolk\Ai\FakeEngine;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Providers;
use Reach\StatamicTolk\Console\Concerns\ReportsTranslations;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Exceptions\ProviderNotConfigured;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\Exceptions\RootMissing;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\GitListeners;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\EntryFamilies;
use Reach\StatamicTolk\Targets\EntryTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Console\RunsInPlease;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class TranslateCommand extends Command
{
    use ReportsTranslations;
    use RunsInPlease;

    protected $signature = 'tolk:translate
        {--entry=* : Translate the family of this entry (any of its localizations names it)}
        {--collection=* : Translate every entry of this collection}
        {--site=* : The site to translate into (required)}
        {--mode=missing : missing (only what is not translated yet), update (also outdated translations), all (everything again) or review (only review the existing machine translations)}
        {--force : Overwrite fields that were edited by hand (in mode review: review them too; they stay manual)}
        {--publish : Publish a new localization when its origin is published, instead of saving it as a draft}
        {--translator= : The model to translate with, as provider:model}
        {--review : Review the translations with a second model and apply its corrections (default from the settings)}
        {--no-review : Do not review the translations, whatever the settings say}
        {--reviewer= : The model to review with, as provider:model (implies --review)}
        {--dry-run : Show what would be translated, without calling the model or saving anything}
        {--yes : Translate without asking for confirmation}';

    protected $description = 'Translate entries into other sites';

    public function handle(Settings $settings, ModelCatalog $catalog, Providers $providers, EntryFamilies $families, TargetFactory $factory): int
    {
        try {
            $sites = $this->sites();
            $mode = $this->mode();
            $review = $this->review($mode);
            $collections = $this->collections($settings);
            $entryIds = $this->entryIds($settings);
            $options = PlanOptions::fromSettings($settings, $catalog, [
                'translator' => $this->option('translator') ?: null,
                'mode' => $mode,
                'overwriteManual' => (bool) $this->option('force'),
                'review' => $review,
                'reviewer' => $this->option('reviewer') ?: null,
                'profile' => 'background',
            ]);

            foreach ($this->models($options) as $model) {
                if (! FakeEngine::enabled() && ! $providers->isConfigured($model->provider)) {
                    throw ProviderNotConfigured::for($model->provider);
                }
            }
        } catch (InvalidArgumentException|MissingDefaultModel|NoProviderConfigured|ProviderNotConfigured|TargetNotFound $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->startReport($options->review);
        $reviewOnly = $options->mode === Mode::Review;

        $roots = $families->roots($collections, $entryIds);
        $this->warnAboutMissingSites($roots, $sites);
        $targets = $families->targets($roots, $sites);

        if ($targets === []) {
            $this->components->info($reviewOnly ? 'Nothing to review.' : 'Nothing to translate.');

            return self::SUCCESS;
        }

        $models = '';

        foreach ($this->models($options) as $role => $model) {
            $models .= "{$role}: {$catalog->label($model)} ({$model}). ";
        }

        $this->line(sprintf(
            'Targets: %s (%s). %sMode: %s.',
            number_format(count($targets)),
            implode(', ', array_map(fn (string $site) => Site::get($site)->name(), $sites)),
            $models,
            $options->mode->value,
        ));

        if (FakeEngine::enabled()) {
            $this->line('<comment>The fake engine is on: nothing is sent to a provider.</comment>');
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($targets, $factory, $options);
        }

        if (! $this->option('yes') && ! $this->confirm($reviewOnly ? 'Review them and save the corrections?' : 'Translate and save them?', true)) {
            $this->components->warn($reviewOnly ? 'Nothing was reviewed.' : 'Nothing was translated.');

            return self::SUCCESS;
        }

        $apply = new ApplyOptions(
            newStatus: $this->option('publish') ? 'mirror' : $settings->newLocalizationStatus(),
            overwriteManual: $options->overwriteManual,
        );

        return $this->translate($targets, $factory, $options, $apply);
    }

    /**
     * @param  list<array{type: string, source_id: string, site: string}>  $targets
     */
    private function translate(array $targets, TargetFactory $factory, PlanOptions $options, ApplyOptions $apply): int
    {
        $translator = app(TargetTranslator::class);
        $this->newLine();

        try {
            foreach ($targets as $description) {
                // Built for each target, after the previous one was saved, so it sees its parent's localization.
                $target = $factory->fromDescription($description);
                $label = $this->targetLabel($target);
                $this->noteOutOfScope($target);
                $this->startTarget($label);

                try {
                    $this->reportResult($label, $options->mode === Mode::Review
                        ? $translator->review($target, $options, $apply)
                        : $translator->translate($target, $options, $apply));
                } catch (ProviderRejected $rejected) {
                    $this->newLine();
                    $this->components->error($rejected->getMessage());
                    $this->summarize();

                    return self::FAILURE;
                }
            }
        } finally {
            // Content saved inside ContentWriter::write() has no git listener (§A11), so it is committed once here.
            if ($this->savedCount() > 0) {
                GitListeners::commit(ContentWriter::COMMIT_MESSAGE);
            }
        }

        $this->summarize();

        return $this->resultCounts()['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Plans every target without calling the model or saving anything, not even state.
     *
     * @param  list<array{type: string, source_id: string, site: string}>  $targets
     */
    private function dryRun(array $targets, TargetFactory $factory, PlanOptions $options): int
    {
        $planner = app(Planner::class);
        $planned = [];
        $totals = ['targets' => 0, 'segments' => 0, 'characters' => 0];
        $this->newLine();

        foreach ($targets as $description) {
            $target = $factory->fromDescription($description);
            $this->noteOutOfScope($target);
            $this->startTarget($this->targetLabel($target));

            if ($options->mode === Mode::Review) {
                // A review never creates a localization, so a missing root page does not stop it.
                $plan = $planner->planReview($target, $options);
            } elseif (($failure = $this->rootFailure($target, $planned)) !== null) {
                $this->output->writeln("<fg=red>would fail</>, {$failure}");

                continue;
            } else {
                $planned[$description['source_id'].'|'.$description['site']] = true;
                $plan = $planner->plan($target, $options);
            }

            $this->reportPlan($plan);

            if (! $plan->isEmpty()) {
                $size = $this->planSize($plan);
                $totals['targets']++;
                $totals['segments'] += $size['segments'];
                $totals['characters'] += $size['characters'];
            }
        }

        $this->newLine();
        $this->line(sprintf(
            'Dry run: %s of %s would be %s (%s, %s). Nothing was sent or saved.',
            number_format($totals['targets']),
            $this->count(count($targets), 'target'),
            match (true) {
                $options->mode === Mode::Review => 'reviewed',
                $options->review => 'translated and reviewed',
                default => 'translated',
            },
            $this->count($totals['segments'], 'segment'),
            $this->count($totals['characters'], 'character'),
        ));
        $this->summarizeOutOfScope();

        return self::SUCCESS;
    }

    /**
     * Why a real run would fail the target with RootMissing: the root page has no localization in the target site,
     * and no target before this one would create it.
     *
     * @param  array<string, true>  $planned  source id|site of the targets planned so far
     */
    private function rootFailure(Target $target, array $planned): ?string
    {
        if (! $target instanceof EntryTarget) {
            return null;
        }

        try {
            $target->ensureRootExists();
        } catch (RootMissing $missing) {
            return isset($planned[$missing->rootId.'|'.$target->targetSite()]) ? null : $missing->getMessage();
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function sites(): array
    {
        $sites = array_values(array_unique($this->option('site')));

        if ($sites === []) {
            throw new InvalidArgumentException('Name the sites to translate into with --site.');
        }

        foreach ($sites as $site) {
            if (Site::get($site) === null) {
                throw UnknownSite::for($site);
            }
        }

        return $sites;
    }

    private function mode(): Mode
    {
        return Mode::tryFrom((string) $this->option('mode'))
            ?? throw new InvalidArgumentException("Invalid mode [{$this->option('mode')}]. Use missing, update, all or review.");
    }

    /**
     * @return ?bool null when the settings decide
     */
    private function review(Mode $mode): ?bool
    {
        if (! $this->option('no-review')) {
            // Naming a reviewer asks for a review.
            return $this->option('review') || $this->option('reviewer') ? true : null;
        }

        if ($this->option('review') || $this->option('reviewer') || $mode === Mode::Review) {
            throw new InvalidArgumentException('--no-review cannot be combined with --review, --reviewer or --mode=review.');
        }

        return false;
    }

    /**
     * @return array<string, ModelRef> the models the operation calls, by role
     */
    private function models(PlanOptions $options): array
    {
        return array_filter([
            'Translator' => $options->mode === Mode::Review ? null : $options->translator,
            'Reviewer' => $options->reviewer,
        ]);
    }

    /**
     * @return list<string>
     */
    private function collections(Settings $settings): array
    {
        $handles = array_values(array_unique($this->option('collection')));

        if ($handles === [] && $this->option('entry') === []) {
            throw new InvalidArgumentException('Name what to translate with --collection or --entry.');
        }

        foreach ($handles as $handle) {
            if (Collection::find($handle) === null) {
                throw new InvalidArgumentException("Collection [{$handle}] not found.");
            }
        }

        return array_values(array_filter($handles, fn (string $handle) => ! $this->excluded($settings, $handle)));
    }

    /**
     * @return list<string>
     */
    private function entryIds(Settings $settings): array
    {
        $ids = [];

        foreach (array_unique($this->option('entry')) as $id) {
            $entry = Entry::find($id) ?? throw TargetNotFound::entry($id);

            if (! $this->excluded($settings, $entry->collectionHandle())) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function excluded(Settings $settings, string $collection): bool
    {
        if (! in_array($collection, $settings->excludedCollections(), true)) {
            return false;
        }

        $this->components->warn("The collection [{$collection}] is excluded from translation in the settings.");

        return true;
    }

    /**
     * @param  list<EntryContract>  $roots
     * @param  list<string>  $sites
     */
    private function warnAboutMissingSites(array $roots, array $sites): void
    {
        $collections = collect($roots)->map(fn (EntryContract $root) => $root->collection())->unique(fn ($collection) => $collection->handle());

        foreach ($collections as $collection) {
            foreach (array_diff($sites, $collection->sites()->all()) as $site) {
                $this->components->warn("The collection [{$collection->handle()}] is not available in the site [{$site}].");
            }
        }
    }
}
