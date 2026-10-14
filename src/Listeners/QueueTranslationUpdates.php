<?php

namespace Reach\StatamicTolk\Listeners;

use Reach\StatamicTolk\Jobs\UpdateTranslations;
use Reach\StatamicTolk\Runs\AutoUpdater;
use Reach\StatamicTolk\Runs\RunSaves;
use Statamic\Events\EntrySaved;
use Throwable;

/**
 * Updates the translations of an entry saved published in its origin, when the settings ask for it (T11.1). Only the
 * save itself counts: the localizations core saves again after their origin are not initial. With revisions, a
 * published entry fires EntrySaved when it is published. A new entry of a collection that propagates gets its
 * localizations only after EntrySaved, so its update is dispatched before they exist. A save a run makes is the run
 * creator's, also in a queue worker, so a chain of localizations is updated as the user who saved its origin. Nothing it
 * does can fail the save.
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
            $user = RunSaves::user($event->authenticatedUser);

            if ($this->updater->applies($event->entry, $user) || $this->updater->appliesOncePropagated($event->entry, $user)) {
                UpdateTranslations::dispatchFor($event->entry, $user);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
