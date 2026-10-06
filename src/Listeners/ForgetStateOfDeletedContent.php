<?php

namespace Reach\StatamicTolk\Listeners;

use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\Support\GitListeners;
use Reach\StatamicTolk\Targets\TargetRef;
use Statamic\Events\EntryDeleted;
use Statamic\Events\GlobalVariablesDeleted;
use Statamic\Events\NavDeleted;
use Statamic\Events\NavTreeDeleted;
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
        $this->forget($this->inEverySite(ContentType::Term, (string) $event->term->id()));
    }

    /**
     * Deleting a set deletes its variables site by site, and so does removing a site from the set.
     */
    public function handleGlobalVariablesDeleted(GlobalVariablesDeleted $event): void
    {
        $this->forget([new TargetRef(ContentType::GlobalSet, (string) $event->variables->handle(), (string) $event->variables->locale())]);
    }

    public function handleNavTreeDeleted(NavTreeDeleted $event): void
    {
        $this->forget([new TargetRef(ContentType::Nav, (string) $event->tree->handle(), (string) $event->tree->locale())]);
    }

    /**
     * Deleting a navigation leaves its trees' files behind, without an event for them.
     */
    public function handleNavDeleted(NavDeleted $event): void
    {
        $this->forget($this->inEverySite(ContentType::Nav, (string) $event->nav->handle()));
    }

    /**
     * @return list<TargetRef>
     */
    private function inEverySite(ContentType $type, string $id): array
    {
        return Site::all()->keys()->map(fn (string $site) => new TargetRef($type, $id, $site))->all();
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
