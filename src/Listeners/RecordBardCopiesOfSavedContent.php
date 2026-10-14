<?php

namespace Reach\StatamicTolk\Listeners;

use Closure;
use Illuminate\Support\Str;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Exceptions\InvalidTarget;
use Reach\StatamicTolk\Exceptions\NotALocalization;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Support\EntryMemo;
use Reach\StatamicTolk\Targets\TargetFactory;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Events\EntrySaved;
use Statamic\Events\GlobalVariablesSaved;
use Statamic\Events\RevisionSaved;
use Statamic\Events\TermSaved;
use Statamic\Facades\Entry;
use Throwable;

/**
 * Records a localization's Bard copy of a source without text as soon as the localization is saved, by whoever saves
 * it (StateWriter::recordCopies(), §A5): the CP saves one when an inherited Bard field desyncs itself on load, also for
 * a user without the editor's banner, and once the source has text the copy can no longer be told apart from a field
 * emptied by hand. Saves made through ContentWriter confirm their target themselves. Statamic's commit for the save may
 * have run already, so a recorded copy is committed on its own. Nothing it does can fail the save, and it forgets what
 * core memoized for the entries it read (EntryMemo).
 */
final readonly class RecordBardCopiesOfSavedContent
{
    public function __construct(
        private TargetFactory $targets,
        private StateWriter $writer,
    ) {}

    public function handleEntrySaved(EntrySaved $event): void
    {
        $this->record(fn () => $this->localization($event->entry));
    }

    /**
     * On collections with revisions, the CP saves a published entry's changes into its working copy, which is what the
     * status reads (§A6).
     */
    public function handleRevisionSaved(RevisionSaved $event): void
    {
        $key = (string) $event->revision->key();

        if (str_starts_with($key, 'collections/')) {
            $this->record(fn () => $this->localization(Entry::find(Str::afterLast($key, '/'))));
        }
    }

    /**
     * A term is saved in every site of its taxonomy at once.
     */
    public function handleTermSaved(TermSaved $event): void
    {
        $term = $event->term;

        $this->record(fn () => $term->taxonomy()->sites()
            ->reject(fn (string $site) => $site === $term->inDefaultLocale()->locale())
            ->map(fn (string $site) => $this->targets->forTerm($term, $site))
            ->values()
            ->all());
    }

    /**
     * Variables without an origin give a target without fields, which records nothing.
     */
    public function handleGlobalVariablesSaved(GlobalVariablesSaved $event): void
    {
        $variables = $event->variables;

        $this->record(fn () => [$this->targets->forGlobalSet($variables->globalSet(), (string) $variables->locale())]);
    }

    /**
     * @return list<Target>
     */
    private function localization(?EntryContract $entry): array
    {
        $origin = $entry?->origin();

        return $origin === null ? [] : [$this->targets->forEntry($origin, (string) $entry->locale())];
    }

    /**
     * @param  Closure(): list<Target>  $targets
     */
    private function record(Closure $targets): void
    {
        if (ContentWriter::active()) {
            return;
        }

        // Core keeps what it memoizes for the entries read here, such as the origin's localizations, for the rest of the
        // process, which in one that saves many localizations would hold every one of them.
        $memo = EntryMemo::mark();

        try {
            // One site's failure leaves the other sites of a term recorded.
            foreach ($this->attempt($targets) ?? [] as $target) {
                $this->attempt(fn () => $this->writer->recordCopies($target, commit: true));
            }
        } finally {
            $memo->forget();
        }
    }

    private function attempt(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (InvalidTarget|NotALocalization|TargetNotFound|UnknownSite) {
            // Content that is no translation target, such as a localization in a site its collection no longer has.
            return null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
