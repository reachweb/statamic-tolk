<?php

namespace Reach\StatamicTolk\Listeners;

use Reach\StatamicTolk\Jobs\UpdateTranslations;
use Reach\StatamicTolk\Runs\AutoUpdater;
use Statamic\Events\EntrySaved;
use Throwable;

/**
 * Updates the translations of an entry saved published in its origin, when the settings ask for it (T11.1). Only the
 * save itself counts: the localizations core saves again after their origin are not initial. With revisions, a
 * published entry fires EntrySaved when it is published. Nothing it does can fail the save.
 */
final readonly class QueueTranslationUpdates
{
    public function __construct(private AutoUpdater $updater) {}

    public function handle(EntrySaved $event): void
    {
        if (! $event->isInitial()) {
            return;
        }

        try {
            if ($this->updater->applies($event->entry)) {
                UpdateTranslations::dispatchFor($event->entry, $event->authenticatedUser);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
