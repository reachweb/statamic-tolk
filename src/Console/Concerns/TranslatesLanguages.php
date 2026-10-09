<?php

namespace Reach\StatamicTolk\Console\Concerns;

use InvalidArgumentException;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\Prompts\PromptContext;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Enums\TargetResultStatus;
use Reach\StatamicTolk\Exceptions\ProviderRejected;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\Runs\Estimate;
use Reach\StatamicTolk\Runs\Estimator;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\GitListeners;
use Reach\StatamicTolk\Support\Languages;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\FormsTarget;
use Reach\StatamicTolk\Targets\LangTarget;
use Reach\StatamicTolk\Translation\Plan;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\TargetResult;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Facades\Site;

/**
 * The flow tolk:lang and tolk:forms share (§A16): site handles mapped to languages, a plan per target, the dry run with
 * the keys by status and the estimate, and the translation of one target after another in this process, committed
 * once. These targets are small, so the run engine is not used. The using class also uses ReportsTranslations and
 * ResolvesTranslationOptions.
 */
trait TranslatesLanguages
{
    // The order in which a dry run counts the keys of a file by status.
    private const array STATUSES = [FieldStatus::Untranslated, FieldStatus::Outdated, FieldStatus::Manual, FieldStatus::Current, FieldStatus::NotApplicable];

    /**
     * What a dry run and the plan lines call one string of a target.
     */
    private function keyNoun(): string
    {
        return 'key';
    }

    /**
     * The language of the --from site, else of the default site.
     */
    private function sourceLang(): string
    {
        return (string) Site::get($this->sourceSite() ?? Site::default()->handle())->lang();
    }

    /**
     * The languages to translate into, each once: sites that share a `lang()` share its files. Without site handles,
     * every language of a site other than the source language.
     *
     * @return list<string>
     */
    private function languages(string $sourceLang): array
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
     * @param  ?list<string>  $fields  the keys to plan; null for every key
     */
    private function languageOptions(Settings $settings, ModelCatalog $catalog, ?array $fields = null): PlanOptions
    {
        $mode = $this->mode();

        return PlanOptions::fromSettings($settings, $catalog, [
            'translator' => $this->option('translator') ?: null,
            'mode' => $mode,
            'overwriteManual' => (bool) $this->option('force'),
            'fields' => $fields,
            'review' => $this->review($mode),
            'reviewer' => $this->option('reviewer') ?: null,
            'profile' => 'background',
        ]);
    }

    /**
     * @param  list<LangTarget|FormsTarget>  $targets
     * @return list<Plan>
     */
    private function planLanguages(array $targets, PlanOptions $options, Planner $planner): array
    {
        return array_map(fn (LangTarget|FormsTarget $target) => $this->planLanguage($target, $options, $planner), $targets);
    }

    private function planLanguage(LangTarget|FormsTarget $target, PlanOptions $options, Planner $planner): Plan
    {
        return $options->mode === Mode::Review ? $planner->planReview($target, $options) : $planner->plan($target, $options);
    }

    /**
     * One target after another; a provider that refuses the account or the model stops the command, because every other
     * target would meet it too: that target fails, and the targets after it are named. The state the translations wrote
     * is committed once.
     *
     * @param  list<LangTarget|FormsTarget>  $targets
     */
    private function translateLanguages(array $targets, PlanOptions $options, TargetTranslator $translator): int
    {
        $apply = new ApplyOptions(overwriteManual: $options->overwriteManual);
        $saved = false;
        $this->newLine();

        try {
            foreach ($targets as $index => $target) {
                $saved = $this->adoptMovedKeys($target) || $saved;
                $this->startTarget($target->name());
                $result = $options->mode === Mode::Review ? $translator->review($target, $options, $apply) : $translator->translate($target, $options, $apply);
                $this->reportResult($target->name(), $result);
                $saved = $saved || $result->status === TargetResultStatus::Done;
            }
        } catch (ProviderRejected $exception) {
            $this->reportResult($target->name(), TargetResult::failed(TargetResult::PROVIDER_REJECTED, $exception->getMessage()));
            $left = array_map(fn (LangTarget|FormsTarget $target) => $target->name(), array_slice($targets, $index + 1));

            if ($left !== []) {
                $this->newLine();
                $this->components->warn(sprintf('%s not translated: %s.', count($left) === 1 ? '1 file was' : number_format(count($left)).' files were', implode(', ', $left)));
            }

            return self::FAILURE;
        } finally {
            if ($saved) {
                GitListeners::commit(ContentWriter::COMMIT_MESSAGE);
            }
        }

        return $this->resultCounts()['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A key of `lang/{lang}.json` that moved between the source language's JSON file and the forms takes its record
     * along, so its translation does not read as edited by hand. Returns whether a record moved.
     */
    private function adoptMovedKeys(LangTarget|FormsTarget $target): bool
    {
        $from = $target->sharesFileWith();

        return $from !== null && app(StateWriter::class)->adopt($target, $from) !== [];
    }

    /**
     * A line per target: its keys by status and what its plan sends; with -v the keys it translates. Then the estimate.
     * With options that name the keys to plan, only those are counted.
     *
     * @param  list<LangTarget|FormsTarget>  $targets
     * @param  list<Plan>  $plans
     */
    private function dryRunLanguages(array $targets, array $plans, PlanOptions $options, Settings $settings, StatusResolver $resolver, Estimator $estimator): void
    {
        $estimate = new Estimate;
        $files = 0;
        $this->newLine();

        foreach ($targets as $index => $target) {
            $plan = $plans[$index];
            $fields = $resolver->resolve($target)->fields;
            $fields = $options->fields === null ? $fields : array_intersect_key($fields, array_flip($options->fields));
            $counts = array_count_values(array_map(fn ($field) => $field->status->value, $fields));
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
            $this->outcomeVerb($options),
            $this->count($estimate->segments, 'segment'),
            $this->count($estimate->chars, 'character'),
        ));
    }

    private function planLine(Plan $plan, PlanOptions $options): string
    {
        if ($plan->isEmpty()) {
            return '<comment>nothing to do</comment>';
        }

        return $this->count(count($plan->fieldHandles()), $this->keyNoun()).' to '.($options->mode === Mode::Review ? 'review' : 'translate')
            .$this->planSizeNote($plan);
    }

    /**
     * @param  list<LangTarget|FormsTarget>  $targets
     */
    private function languagesHeader(array $targets, PlanOptions $options, ModelCatalog $catalog): void
    {
        $languages = app(Languages::class);
        $names = array_unique(array_map(fn (LangTarget|FormsTarget $target) => $languages->forSite($target->targetSite())->name, $targets));

        $this->line(sprintf('Files: %s (%s). %sMode: %s.', number_format(count($targets)), implode(', ', $names), $this->describeModels($options, $catalog), $options->mode->value));
        $this->noteFakeEngine();
    }
}
