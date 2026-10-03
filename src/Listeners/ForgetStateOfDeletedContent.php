<?php

namespace Reach\StatamicTolk\Listeners;

use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\Support\GitListeners;
use Reach\StatamicTolk\Targets\TargetRef;
use Statamic\Events\EntryDeleted;
use Statamic\Events\TermDeleted;
use Statamic\Facades\Site;

final readonly class ForgetStateOfDeletedContent
{
    public function __construct(private StateWriter $writer) {}

    public function handleEntryDeleted(EntryDeleted $event): void
    {
        $this->forget([new TargetRef(ContentType::Entry, (string) $event->entry->id())]);
    }

    /**
     * A term is deleted in every site at once.
     */
    public function handleTermDeleted(TermDeleted $event): void
    {
        $id = (string) $event->term->id();

        $this->forget(Site::all()->keys()->map(fn (string $site) => new TargetRef(ContentType::Term, $id, $site))->all());
    }

    /**
     * @param  list<TargetRef>  $refs
     */
    private function forget(array $refs): void
    {
        $forgotten = false;

        foreach ($refs as $ref) {
            $forgotten = $this->writer->forget($ref) || $forgotten;
        }

        // Statamic's commit for the deletion may have run already: on a sync queue it runs inside its own listener.
        if ($forgotten) {
            GitListeners::commit(StateWriter::COMMIT_MESSAGE);
        }
    }
}
