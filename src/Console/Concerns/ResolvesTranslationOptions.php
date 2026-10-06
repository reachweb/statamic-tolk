<?php

namespace Reach\StatamicTolk\Console\Concerns;

use InvalidArgumentException;
use Reach\StatamicTolk\Ai\FakeEngine;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Providers;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Exceptions\ProviderNotConfigured;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\Runs\RunDispatcher;
use Reach\StatamicTolk\Translation\PlanOptions;
use Statamic\Facades\Site;

/**
 * The options tolk:translate and tolk:site share: --from, the review options, --concurrency, --queue, and the check
 * that every model the operation calls has a provider with a key.
 */
trait ResolvesTranslationOptions
{
    private function sourceSite(): ?string
    {
        $site = $this->option('from');

        if ($site === null || $site === '') {
            return null;
        }

        return Site::get($site) === null ? throw UnknownSite::for($site) : $site;
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

    private function concurrency(): int
    {
        $concurrency = $this->option('concurrency');

        if ($concurrency === null) {
            return max(1, (int) config('tolk.concurrency.cli'));
        }

        if ($this->option('queue')) {
            throw new InvalidArgumentException('--concurrency cannot be combined with --queue, which runs tolk.concurrency.queue lanes.');
        }

        if (! ctype_digit((string) $concurrency) || (int) $concurrency < 1) {
            throw new InvalidArgumentException("Invalid concurrency [{$concurrency}]. Use a whole number of at least 1.");
        }

        return (int) $concurrency;
    }

    /**
     * Refuses --queue on a connection that runs jobs in the process that dispatches them, or never: every lane would run
     * inside this command, one after another, sleeping through each wait.
     */
    private function checkQueue(): void
    {
        if ($this->option('queue') && ! RunDispatcher::hasWorker()) {
            throw new InvalidArgumentException(sprintf(
                'The queue connection [%s] has no worker, so --queue would run the whole run inside this command. Leave out --queue to translate with worker processes (--concurrency).',
                RunDispatcher::connection(),
            ));
        }
    }

    /**
     * @throws ProviderNotConfigured for the first model whose provider has no key, unless the fake engine is on
     */
    private function checkProviders(PlanOptions $options, Providers $providers): void
    {
        foreach ($this->models($options) as $model) {
            if (! FakeEngine::enabled() && ! $providers->isConfigured($model->provider)) {
                throw ProviderNotConfigured::for($model->provider);
            }
        }
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
}
