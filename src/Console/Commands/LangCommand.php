<?php

namespace Reach\StatamicTolk\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Reach\StatamicTolk\Ai\FakeEngine;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\Prompts\PromptContext;
use Reach\StatamicTolk\Ai\Providers;
use Reach\StatamicTolk\Console\Concerns\ReportsTranslations;
use Reach\StatamicTolk\Console\Concerns\ResolvesTranslationOptions;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Enums\TargetResultStatus;
use Reach\StatamicTolk\Exceptions\InvalidLangFile;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Exceptions\ProviderNotConfigured;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\Runs\Estimate;
use Reach\StatamicTolk\Runs\Estimator;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\GitListeners;
use Reach\StatamicTolk\Support\LangFiles;
use Reach\StatamicTolk\Support\Languages;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\LangTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Translation\Plan;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Site;

/**
 * Translates the app's Laravel translation files into the languages of other sites (§A16), one file at a time in this
 * process: they are small, so the run engine is not used.
 */
class LangCommand extends Command
{
    use ReportsTranslations;
    use ResolvesTranslationOptions;
    use RunsInPlease;

    // The order in which a dry run counts the keys of a file by status.
    private const array STATUSES = [FieldStatus::Untranslated, FieldStatus::Outdated, FieldStatus::Manual, FieldStatus::Current, FieldStatus::NotApplicable];

    protected $signature = 'tolk:lang
        {sites?* : The sites to translate into; sites with the same language share its files (default: every site whose language differs from the source language)}
        {--from= : The site whose language the files are translated from (default: the default site)}
        {--group=* : Translate only this file: the name of a PHP file without .php, or _json for the JSON file}
        {--mode=missing : missing (only strings that are not translated yet), update (also outdated translations), all (everything again) or review (only review the existing machine translations)}
        {--force : Overwrite strings that were edited by hand (in mode review: review them too; they stay manual)}
        {--review : Review the translations with a second model and apply its corrections (default from the settings)}
        {--no-review : Do not review the translations, whatever the settings say}
        {--translator= : The model to translate with, as provider:model}
        {--reviewer= : The model to review with, as provider:model (implies --review)}
        {--dry-run : Show what would be translated, without calling the model or writing anything}
        {--yes : Rewrite PHP files that were not written by this command without asking, although their comments and layout are lost}';

    protected $description = "Translate the app's Laravel translation files (lang/) into the languages of other sites";

    public function handle(
        Settings $settings,
        ModelCatalog $catalog,
        Providers $providers,
        TargetFactory $factory,
        LangFiles $files,
        Planner $planner,
        TargetTranslator $translator,
        StatusResolver $resolver,
        Estimator $estimator,
    ): int {
        try {
            $sourceSite = $this->sourceSite() ?? Site::default()->handle();
            $sourceLang = (string) Site::get($sourceSite)->lang();
            $langs = $this->langs($sourceLang);
            $groups = $this->groups($files, $sourceLang);

            if ($groups === [] || $langs === []) {
                $this->components->info($groups === []
                    ? "There are no translation files in lang/{$sourceLang}."
                    : "Every site uses the source language [{$sourceLang}], so there is nothing to translate into.");

                return self::SUCCESS;
            }

            $mode = $this->mode();
            $review = $this->review($mode);
            $options = PlanOptions::fromSettings($settings, $catalog, [
                'translator' => $this->option('translator') ?: null,
                'mode' => $mode,
                'overwriteManual' => (bool) $this->option('force'),
                'review' => $review,
                'reviewer' => $this->option('reviewer') ?: null,
                'profile' => 'background',
            ]);

            $this->checkProviders($options, $providers);
            $targets = $this->targets($factory, $files, $groups, $langs, $sourceLang);
        } catch (InvalidArgumentException|InvalidLangFile|MissingDefaultModel|NoProviderConfigured|ProviderNotConfigured $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->startReport($options->review);
        $this->header($targets, $options, $catalog);
        $plans = array_map(fn (LangTarget $target) => $options->mode === Mode::Review ? $planner->planReview($target, $options) : $planner->plan($target, $options), $targets);

        if ($this->option('dry-run')) {
            $this->dryRun($targets, $plans, $options, $settings, $resolver, $estimator);
            $this->pluralNotes($targets, $sourceLang);

            return self::SUCCESS;
        }

        if (! $this->rewritesConfirmed($targets, $plans, $files)) {
            $this->components->warn('Nothing was translated.');

            return self::SUCCESS;
        }

        $exitCode = $this->translate($targets, $options, $translator);
        $this->summarize();
        $this->pluralNotes($targets, $sourceLang);

        return $exitCode;
    }

    /**
     * One file after another; a provider that refuses the account or the model stops the command, because every other
     * file would meet it too. The state the files' translations wrote is committed once.
     *
     * @param  list<LangTarget>  $targets
     */
    private function translate(array $targets, PlanOptions $options, TargetTranslator $translator): int
    {
        $apply = new ApplyOptions(overwriteManual: $options->overwriteManual);
        $saved = 0;
        $this->newLine();

        try {
            foreach ($targets as $target) {
                $this->startTarget($target->name());
                $result = $options->mode === Mode::Review ? $translator->review($target, $options, $apply) : $translator->translate($target, $options, $apply);
                $this->reportResult($target->name(), $result);
                $saved += $result->status === TargetResultStatus::Done ? 1 : 0;
            }
        } catch (ProviderRejected $exception) {
            $this->newLine();
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($saved > 0) {
                GitListeners::commit(ContentWriter::COMMIT_MESSAGE);
            }
        }

        return $this->resultCounts()['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A PHP file is rewritten whole, so a file this command did not write loses its comments and layout. Asks once for
     * every such file that has something to do.
     *
     * @param  list<LangTarget>  $targets
     * @param  list<Plan>  $plans
     */
    private function rewritesConfirmed(array $targets, array $plans, LangFiles $files): bool
    {
        $rewritten = [];

        foreach ($targets as $index => $target) {
            if (! $plans[$index]->isEmpty() && ! $files->keepsLayout($target->lang(), $target->group())) {
                $rewritten[] = $target->name();
            }
        }

        if ($rewritten === [] || $this->option('yes')) {
            return true;
        }

        $this->newLine();
        $this->line('These files are written anew, so their comments and layout are lost: '.implode(', ', $rewritten).'.');

        return $this->confirm('Translate and rewrite them?', false);
    }

    /**
     * A line per file: its keys by status and what its plan sends; with -v the keys it translates. Then the estimate.
     *
     * @param  list<LangTarget>  $targets
     * @param  list<Plan>  $plans
     */
    private function dryRun(array $targets, array $plans, PlanOptions $options, Settings $settings, StatusResolver $resolver, Estimator $estimator): void
    {
        $estimate = new Estimate;
        $files = 0;
        $this->newLine();

        foreach ($targets as $index => $target) {
            $plan = $plans[$index];
            $counts = array_count_values(array_map(fn ($field) => $field->status->value, $resolver->resolve($target)->fields));
            $statuses = implode(', ', array_filter(array_map(
                fn (FieldStatus $status) => isset($counts[$status->value]) ? $counts[$status->value].' '.str_replace('_', ' ', $status->value) : null,
                self::STATUSES,
            )));

            $this->output->writeln("  {$target->name()}: ".($statuses === '' ? 'no strings' : $statuses).' → '.$this->planLine($plan, $options));

            if ($this->output->isVerbose() && ! $plan->isEmpty()) {
                $this->output->writeln('    '.implode(', ', $plan->fieldHandles()));
            }

            if (! $plan->isEmpty()) {
                $files++;
                $estimate = $estimate->add($estimator->forPlan($plan, PromptContext::fromSettings($settings, $target->targetSite())));
            }
        }

        $this->newLine();
        $this->printEstimate($estimate);
        $this->newLine();
        $this->line(sprintf(
            'Dry run: %s of %s would be %s (%s, %s). Nothing was sent or written.',
            number_format($files),
            $this->count(count($targets), 'file'),
            match (true) {
                $options->mode === Mode::Review => 'reviewed',
                $options->review => 'translated and reviewed',
                default => 'translated',
            },
            $this->count($estimate->segments, 'segment'),
            $this->count($estimate->chars, 'character'),
        ));
    }

    private function planLine(Plan $plan, PlanOptions $options): string
    {
        if ($plan->isEmpty()) {
            return '<comment>nothing to do</comment>';
        }

        $size = $this->planSize($plan);

        return $this->count(count($plan->fieldHandles()), 'key').' to '.($options->mode === Mode::Review ? 'review' : 'translate')
            .' ('.$this->count($size['segments'], 'segment').', '.$this->count($size['characters'], 'character')
            .($size['reused'] > 0 ? ', '.$this->count($size['reused'], 'segment').' reused' : '').')';
    }

    /**
     * The pluralised strings of the files whose language Laravel gives more plural forms than the source language: their
     * translations have as many variants as the source, which may be too few (§A16).
     *
     * @param  list<LangTarget>  $targets
     */
    private function pluralNotes(array $targets, string $sourceLang): void
    {
        $sourceForms = LangTarget::pluralForms($sourceLang);
        $rows = [];

        foreach ($targets as $target) {
            $forms = LangTarget::pluralForms($target->lang());

            if ($forms > $sourceForms) {
                foreach ($target->pluralKeys() as $key) {
                    $rows[] = [$target->name(), $key, $forms];
                }
            }
        }

        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->line("These pluralised strings keep the {$sourceForms} forms of the source language in their translations, but their language has more. Add the missing forms by hand:");
        $this->table(['File', 'Key', 'Plural forms'], $rows);
    }

    /**
     * @param  list<LangTarget>  $targets
     */
    private function header(array $targets, PlanOptions $options, ModelCatalog $catalog): void
    {
        $languages = app(Languages::class);
        $names = array_unique(array_map(fn (LangTarget $target) => $languages->forSite($target->targetSite())->name, $targets));
        $models = '';

        foreach ($this->models($options) as $role => $model) {
            $models .= "{$role}: {$catalog->label($model)} ({$model}). ";
        }

        $this->line(sprintf('Files: %s (%s). %sMode: %s.', number_format(count($targets)), implode(', ', $names), $models, $options->mode->value));

        if (FakeEngine::enabled()) {
            $this->line('<comment>The fake engine is on: nothing is sent to a provider.</comment>');
        }
    }

    /**
     * The languages to translate into, each once: sites that share a `lang()` share its files.
     *
     * @return list<string>
     */
    private function langs(string $sourceLang): array
    {
        $handles = array_values(array_unique($this->argument('sites')));
        $langs = [];

        if ($handles === []) {
            return Site::all()->map(fn ($site) => (string) $site->lang())->reject(fn (string $lang) => $lang === $sourceLang)->unique()->values()->all();
        }

        foreach ($handles as $handle) {
            $site = Site::get($handle) ?? throw UnknownSite::for($handle);

            if ($site->lang() === $sourceLang) {
                throw new InvalidArgumentException("The site [{$handle}] uses the source language [{$sourceLang}], so there is nothing to translate into it.");
            }

            $langs[] = (string) $site->lang();
        }

        return array_values(array_unique($langs));
    }

    /**
     * @return list<string> the source language's groups, or those --group names
     */
    private function groups(LangFiles $files, string $sourceLang): array
    {
        $groups = $files->groups($sourceLang);
        $named = array_values(array_unique($this->option('group')));

        foreach ($named as $group) {
            if (! in_array($group, $groups, true)) {
                throw new InvalidArgumentException('The translation file ['.LangFiles::name($sourceLang, $group).'] was not found.');
            }
        }

        return $named === [] ? $groups : array_values(array_intersect($groups, $named));
    }

    private function mode(): Mode
    {
        return Mode::tryFrom((string) $this->option('mode'))
            ?? throw new InvalidArgumentException("Invalid mode [{$this->option('mode')}]. Use missing, update, all or review.");
    }

    /**
     * A target per language and group. Every file is read here, so one that cannot be read stops the command before
     * anything is sent.
     *
     * @param  list<string>  $groups
     * @param  list<string>  $langs
     * @return list<LangTarget>
     *
     * @throws InvalidLangFile
     */
    private function targets(TargetFactory $factory, LangFiles $files, array $groups, array $langs, string $sourceLang): array
    {
        $targets = [];

        foreach ($groups as $group) {
            $files->read($sourceLang, $group);
        }

        foreach ($langs as $lang) {
            foreach ($groups as $group) {
                $files->read($lang, $group);
                $targets[] = $factory->forLang($group, $lang, $sourceLang);
            }
        }

        return $targets;
    }
}
