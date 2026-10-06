<?php

namespace Reach\StatamicTolk\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\Providers;
use Reach\StatamicTolk\Console\Concerns\ReportsTranslations;
use Reach\StatamicTolk\Console\Concerns\ResolvesTranslationOptions;
use Reach\StatamicTolk\Console\Concerns\TranslatesLanguages;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Exceptions\InvalidLangFile;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Exceptions\ProviderNotConfigured;
use Reach\StatamicTolk\Runs\Estimator;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\LangFiles;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\FormsTarget;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Form;
use Statamic\Facades\Site;

/**
 * Translates the texts of the site's forms into the languages of other sites (§A16), written to `lang/{lang}.json`
 * under the source text, one language at a time in this process (TranslatesLanguages).
 */
class FormsCommand extends Command
{
    use ReportsTranslations;
    use ResolvesTranslationOptions;
    use RunsInPlease;
    use TranslatesLanguages;

    protected $signature = 'tolk:forms
        {sites?* : The sites to translate into; sites with the same language share its file (default: every site whose language differs from the source language)}
        {--from= : The site whose language the form texts are in (default: the default site)}
        {--form=* : Translate only the texts of this form, by handle}
        {--mode=missing : missing (only texts that are not translated yet), update (also outdated translations), all (everything again) or review (only review the existing machine translations)}
        {--force : Overwrite translations that were edited by hand (in mode review: review them too; they stay manual)}
        {--review : Review the translations with a second model and apply its corrections (default from the settings)}
        {--no-review : Do not review the translations, whatever the settings say}
        {--translator= : The model to translate with, as provider:model}
        {--reviewer= : The model to review with, as provider:model (implies --review)}
        {--dry-run : Show what would be translated, without calling the model or writing anything}';

    protected $description = "Translate the texts of the site's forms into the languages of other sites, as JSON translation strings";

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
        StateRepository $state,
    ): int {
        try {
            $sourceSite = $this->sourceSite() ?? Site::default()->handle();
            $sourceLang = (string) Site::get($sourceSite)->lang();
            $langs = $this->languages($sourceLang);
            $forms = $this->forms();

            if (Form::all()->filter()->isEmpty() || $langs === []) {
                $this->components->info($langs === []
                    ? "Every site uses the source language [{$sourceLang}], so there is nothing to translate into."
                    : 'There are no forms.');

                return self::SUCCESS;
            }

            $targets = $this->targets($factory, $files, $langs, $sourceLang);
            $options = $this->languageOptions($settings, $catalog, $forms === [] ? null : $targets[0]->textsOf($forms));
            $this->checkProviders($options, $providers);
        } catch (InvalidArgumentException|InvalidLangFile|MissingDefaultModel|NoProviderConfigured|ProviderNotConfigured $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->startReport($options->review);
        $this->languagesHeader($targets, $options, $catalog);
        $this->leftOut($targets[0]);
        $plans = $this->planLanguages($targets, $options, $planner);

        if ($this->option('dry-run')) {
            $this->dryRunLanguages($targets, $plans, $options, $settings, $resolver, $estimator);
            $this->unusedKeys($targets, $state);
            $this->templateHint();

            return self::SUCCESS;
        }

        $exitCode = $this->translateLanguages($targets, $options, $translator);
        $this->summarize();
        $this->unusedKeys($targets, $state);
        $this->templateHint();

        return $exitCode;
    }

    private function keyNoun(): string
    {
        return 'text';
    }

    /**
     * @return list<string> the handles --form names; none for every form
     */
    private function forms(): array
    {
        $handles = array_values(array_unique($this->option('form')));

        foreach ($handles as $handle) {
            if (Form::find($handle) === null) {
                throw new InvalidArgumentException("The form [{$handle}] was not found.");
            }
        }

        return $handles;
    }

    /**
     * A target per language. The JSON files are read here, so one that cannot be read stops the command before
     * anything is sent.
     *
     * @param  list<string>  $langs
     * @return non-empty-list<FormsTarget>
     *
     * @throws InvalidLangFile
     */
    private function targets(TargetFactory $factory, LangFiles $files, array $langs, string $sourceLang): array
    {
        $files->read($sourceLang, LangFiles::JSON);

        return array_map(function (string $lang) use ($factory, $files, $sourceLang) {
            $files->read($lang, LangFiles::JSON);

            return $factory->forForms($lang, $sourceLang);
        }, $langs);
    }

    /**
     * The texts this command leaves out, which are the same in every language.
     */
    private function leftOut(FormsTarget $target): void
    {
        $lang = $target->leftToLang();
        $subjects = $target->renderedSubjects();

        if ($lang !== []) {
            $this->line(sprintf(
                '%s %s of %s, so tolk:lang translates %s.',
                $this->count(count($lang), 'text'),
                count($lang) === 1 ? 'is a key' : 'are keys',
                LangFiles::name($target->sourceLang(), LangFiles::JSON),
                count($lang) === 1 ? 'it' : 'them',
            ));
        }

        if ($subjects !== []) {
            $this->line('<comment>Statamic renders the Antlers of an email subject before it looks for its translation, so these subjects are not translated: '.implode(', ', $subjects).'.</comment>');
        }
    }

    /**
     * The keys that hold a translation this command wrote for a text no form uses any more. The command leaves them in
     * the file, because a template may still use them.
     *
     * @param  list<FormsTarget>  $targets
     */
    private function unusedKeys(array $targets, StateRepository $state): void
    {
        $rows = [];

        foreach ($targets as $target) {
            $target = $target->fresh();
            $ref = $target->ref();

            foreach ($ref === null ? [] : $target->unusedKeys($state->get($ref)) as $key) {
                $rows[] = [$target->name(), $key];
            }
        }

        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->line(sprintf('No form uses %s any more: the text was changed or removed. Delete %s from the file once no template uses %s:', $this->count(count($rows), 'key'), count($rows) === 1 ? 'it' : 'them', count($rows) === 1 ? 'it' : 'them'));
        $this->table(['File', 'Unused key'], $rows);
    }

    private function templateHint(): void
    {
        $this->newLine();
        $this->line('Statamic passes only the email subjects through the translator. In the form templates, pass the other texts through trans: {{ display | trans }}, {{ instructions | trans }}, {{ placeholder | trans }}, and {{ label | trans }} for options; also {{ title | trans }} where a template shows the form\'s title.');
    }
}
