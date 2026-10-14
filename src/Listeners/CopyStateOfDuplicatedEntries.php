<?php

namespace Reach\StatamicTolk\Listeners;

use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Statamic\Events\EntryCreated;
use Statamic\Facades\Entry;
use Throwable;

/**
 * Core's Duplicate action copies an entry's localizations, translations included, into new entries that name the
 * localization they were copied from in `duplicated_from`. State is kept by id, so without the records of their
 * originals the copies' translations would read as edited by hand and never be updated. Core's commit for the save
 * follows EntryCreated and takes the state with it. Nothing it does can fail the duplicate.
 */
final readonly class CopyStateOfDuplicatedEntries
{
    public function __construct(
        private TargetFactory $targets,
        private StateWriter $writer,
    ) {}

    public function handle(EntryCreated $event): void
    {
        $entry = $event->entry;
        $from = $entry->get('duplicated_from');

        if (! $entry->hasOrigin() || ! (is_string($from) || is_int($from)) || (string) $from === '') {
            return;
        }

        try {
            $original = Entry::find((string) $from);

            if ($original === null || ! $original->hasOrigin()) {
                return;
            }

            $this->writer->copy(
                $this->targets->fromReference(new TargetRef(ContentType::Entry, (string) $entry->id())),
                $this->targets->fromReference(new TargetRef(ContentType::Entry, (string) $original->id())),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
