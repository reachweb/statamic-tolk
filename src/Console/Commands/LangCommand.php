<?php

namespace Reach\StatamicTolk\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\Providers;
use Reach\StatamicTolk\Console\Concerns\ReportsTranslations;
use Reach\StatamicTolk\Console\Concerns\ResolvesTranslationOptions;
use Reach\StatamicTolk\Console\Concerns\TranslatesLanguages;
use Reach\StatamicTolk\Exceptions\InvalidLangFile;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Exceptions\ProviderNotConfigured;
use Reach\StatamicTolk\Runs\Estimator;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\LangFiles;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\LangTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Console\RunsInPlease;

/**
 * Translates the app's Laravel translation files into the languages of other sites (§A16), one file at a time in this
 * process (TranslatesLanguages).
 */
class LangCommand extends Command
{
    use ReportsTranslations;
    use ResolvesTranslationOptions;
    use RunsInPlease;
    use TranslatesLanguages;

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
            $sourceLang = $this->sourceLang();
            $langs = $this->languages($sourceLang);
            $groups = $this->groups($files, $sourceLang);

            if ($groups === [] || $langs === []) {
                $this->components->info($groups === []
                    ? "There are no translation files in lang/{$sourceLang}."
                    : "Every site uses the source language [{$sourceLang}], so there is nothing to translate into.");

                return self::SUCCESS;
            }

            $options = $this->languageOptions($settings, $catalog);
            $this->checkProviders($options, $providers);
            $targets = $this->targets($factory, $files, $groups, $langs, $sourceLang);
        } catch (InvalidArgumentException|InvalidLangFile|MissingDefaultModel|NoProviderConfigured|ProviderNotConfigured $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->startReport($options->review);
        $this->languagesHeader($targets, $options, $catalog);

        if ($this->option('dry-run')) {
            $this->dryRunLanguages($targets, $this->planLanguages($targets, $options, $planner), $options, $settings, $resolver, $estimator);
            $this->pluralNotes($targets, $sourceLang);

            return self::SUCCESS;
        }

        if (! $this->rewritesConfirmed($targets, $options, $planner, $files)) {
            $this->components->warn('Nothing was translated.');

            return self::SUCCESS;
        }

        $exitCode = $this->translateLanguages($targets, $options, $translator);
        $this->summarize();
        $this->pluralNotes($targets, $sourceLang);

        return $exitCode;
    }

    /**
     * A PHP file is rewritten whole, so a file this command did not write loses its comments and layout. Asks once for
     * every such file that has something to do.
     *
     * @param  list<LangTarget>  $targets
     */
    private function rewritesConfirmed(array $targets, PlanOptions $options, Planner $planner, LangFiles $files): bool
    {
        if ($this->option('yes')) {
            return true;
        }

        $rewritten = [];

        foreach ($targets as $target) {
            if (! $files->keepsLayout($target->lang(), $target->group()) && ! $this->planLanguage($target, $options, $planner)->isEmpty()) {
                $rewritten[] = $target->name();
            }
        }

        if ($rewritten === []) {
            return true;
        }

        $this->newLine();
        $this->line('These files are written anew, so their comments and layout are lost: '.implode(', ', $rewritten).'.');

        return $this->confirm('Translate and rewrite them?', false);
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
