<?php

namespace Reach\StatamicTolk\Console\Commands;

use Illuminate\Console\Command;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\SiteProfileGenerator;
use Reach\StatamicTolk\Enums\UsageSource;
use Reach\StatamicTolk\Exceptions\InvalidModelRef;
use Reach\StatamicTolk\Exceptions\MissingDefaultModel;
use Reach\StatamicTolk\Exceptions\NoProviderConfigured;
use Reach\StatamicTolk\Exceptions\ProfileFailed;
use Reach\StatamicTolk\Exceptions\ProviderNotConfigured;
use Reach\StatamicTolk\Support\Settings;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Site;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Writes a site profile with SiteProfileGenerator and prints it with the names it suggests keeping untranslated. With
 * --save it shows the text and asks before writing it to the settings.
 */
class ProfileCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'tolk:profile
        {--model= : The model to write the profile with, as provider:model (default: the translator of the settings)}
        {--save : Save the profile as the site profile in the Tolk settings, after showing it and asking}';

    protected $description = 'Write a site profile for the translation prompts from the content of the default site';

    public function handle(SiteProfileGenerator $generator, Settings $settings, ModelCatalog $catalog): int
    {
        try {
            $model = $generator->model($this->option('model') ? ModelRef::fromString((string) $this->option('model')) : null);

            $this->components->info(sprintf('Writing a site profile from the content of %s with %s (%s).', Site::default()->name(), $catalog->label($model), $model));

            $result = $generator->generate($model, UsageSource::Cli);
        } catch (InvalidModelRef|MissingDefaultModel|NoProviderConfigured|ProviderNotConfigured|ProfileFailed $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('<options=bold>Site profile</>');
        $this->line(OutputFormatter::escape($result['profile']));
        $this->newLine();
        $this->printTerms($result['terms']);

        if ($this->option('save')) {
            $this->save($settings->fresh(), $result['profile']);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $terms
     */
    private function printTerms(array $terms): void
    {
        if ($terms === []) {
            $this->line('<fg=gray>No names to keep untranslated were suggested.</>');
            $this->newLine();

            return;
        }

        $this->line('<options=bold>Names to keep untranslated</>');

        foreach ($terms as $term) {
            $this->line('  • '.OutputFormatter::escape($term));
        }

        $this->newLine();
        $this->line('<fg=gray>Add them to the glossary in the Tolk settings with "Do not translate" on, so that every translation keeps them.</>');
        $this->newLine();
    }

    private function save(Settings $settings, string $profile): void
    {
        $current = $settings->siteProfile();

        if ($current === $profile) {
            $this->components->info('The settings already hold this site profile.');

            return;
        }

        if ($current !== '') {
            $this->line('<options=bold>Current site profile</>');
            $this->line('<fg=gray>'.OutputFormatter::escape($current).'</>');
            $this->newLine();
        }

        $question = $current === '' ? 'Save this profile as the site profile?' : 'Replace the current site profile with this one?';

        if (! $this->confirm($question, $current === '')) {
            $this->components->info('The site profile was not saved.');

            return;
        }

        $settings->save(['site_profile' => $profile]);

        $this->components->info('Saved the site profile. Every translation prompt now includes it.');
    }
}
