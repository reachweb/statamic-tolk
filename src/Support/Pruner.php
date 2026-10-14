<?php

namespace Reach\StatamicTolk\Support;

use Carbon\CarbonInterval;
use Reach\StatamicTolk\Contracts\RunRepository;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Exceptions\InvalidLangFile;
use Reach\StatamicTolk\Exceptions\InvalidTarget;
use Reach\StatamicTolk\Exceptions\NotALocalization;
use Reach\StatamicTolk\Exceptions\RunNotFound;
use Reach\StatamicTolk\Exceptions\TargetNotFound;
use Reach\StatamicTolk\Exceptions\UnknownSite;
use Reach\StatamicTolk\Runs\Run;
use Reach\StatamicTolk\Runs\RunProcessor;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Targets\TargetRef;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Term;

/**
 * The upkeep of tolk:prune. It deletes the state of targets that no longer exist or are no longer localizations, which
 * no event reports when an origin's deletion detaches its localizations, and confirms the pending entries of the
 * others (§A5), dropping those older than FieldState::PENDING_DAYS, and records an entry's collection and site in a
 * state that lacks them. It stops the runs that made no progress for `runs.claim_ttl`, which commits what they saved,
 * and deletes the runs without activity for `runs.retention_days`. State changes are committed once.
 */
final readonly class Pruner
{
    public function __construct(
        private StateRepository $state,
        private StateWriter $writer,
        private TargetFactory $factory,
        private RunRepository $runs,
        private RunProcessor $processor,
    ) {}

    public function prune(bool $dryRun = false): PruneReport
    {
        [$orphans, $resolved, $expired, $located] = $this->pruneState($dryRun);
        $stopped = $this->stopStalledRuns($dryRun);
        $days = self::retentionDays();

        // A run stopped just now had activity now, so it is kept like any other run that ended.
        $deleted = $dryRun
            ? count(array_filter($this->runs->inactive($days), fn (Run $run) => ! in_array($run->id, $stopped, true)))
            : $this->runs->prune($days);

        $report = new PruneReport($dryRun, $orphans, $resolved, $expired, $stopped, $deleted, $located);

        if (! $dryRun && $report->changesState()) {
            GitListeners::commit(StateWriter::COMMIT_MESSAGE);
        }

        return $report;
    }

    /**
     * The days without activity after which a run is deleted.
     */
    public static function retentionDays(): int
    {
        return max(1, (int) config('tolk.runs.retention_days'));
    }

    /**
     * @return array{0: list<array{reference: string, reason: string}>, 1: int, 2: int, 3: int}
     */
    private function pruneState(bool $dryRun): array
    {
        $orphans = [];
        $resolved = $expired = $located = 0;

        // Every reference is read before any is deleted: the file driver lists the directories it deletes from.
        foreach (iterator_to_array($this->state->references(), false) as $ref) {
            $reason = null;

            try {
                $target = $this->factory->fromReference($ref);
                $confirmed = $this->writer->confirm($target, dryRun: $dryRun);
                $resolved += count($confirmed->promoted) + count($confirmed->baselined);
                $expired += count($confirmed->expired);

                if ($target->type() === ContentType::Entry) {
                    $located += (int) $this->writer->locate($ref, $target->container(), $target->targetSite(), $dryRun);
                }
            } catch (NotALocalization) {
                $reason = PruneReport::NOT_A_LOCALIZATION;
            } catch (TargetNotFound) {
                $entry = $ref->type === ContentType::Entry ? Entry::find($ref->id) : null;

                if ($entry !== null) {
                    $located += (int) $this->writer->locate($ref, $entry->collectionHandle(), $entry->locale(), $dryRun);
                } elseif (! $this->inSiteLeftOut($ref)) {
                    $reason = PruneReport::DELETED;
                }
            } catch (InvalidTarget|UnknownSite) {
                // The content exists in a site that the configuration leaves out now; its state is kept for its return.
            } catch (InvalidLangFile) {
                // A translation file that cannot be read now still holds the translations its state describes.
            }

            if ($reason !== null) {
                $orphans[] = ['reference' => (string) $ref, 'reason' => $reason];

                if (! $dryRun) {
                    $this->writer->forget($ref);
                }
            }
        }

        return [$orphans, $resolved, $expired, $located];
    }

    /**
     * A term whose taxonomy no longer has the site keeps its data there, and so does an entry in a site its collection no
     * longer has, so neither is gone, though TargetFactory finds neither: core still finds the term, and the entry until
     * the Stache is rebuilt (pruneState() keeps its state then, and records its collection and site there). Core indexes
     * no such entry after that, so then the collection and site its state records tell (§A5); a state that records none
     * is deleted.
     */
    private function inSiteLeftOut(TargetRef $ref): bool
    {
        return match ($ref->type) {
            ContentType::Term => Term::find($ref->id) !== null,
            ContentType::Entry => $this->entryInSiteLeftOut($this->state->get($ref)),
            default => false,
        };
    }

    private function entryInSiteLeftOut(ItemState $state): bool
    {
        $collection = $state->collection === null ? null : Collection::findByHandle($state->collection);

        return $collection !== null && $state->site !== null && ! $collection->sites()->contains($state->site);
    }

    /**
     * @return list<string> the ids of the runs stopped, or that a dry run would stop
     */
    private function stopStalledRuns(bool $dryRun): array
    {
        $seconds = max(1, (int) config('tolk.runs.claim_ttl'));
        $message = __('The run made no progress for more than :duration and was stopped. Resume continues it.', [
            'duration' => CarbonInterval::seconds($seconds)->cascade()->forHumans(),
        ]);
        $stopped = [];

        foreach ($this->runs->stale($seconds) as $run) {
            try {
                if ($dryRun || $this->processor->stopStalled($run, $seconds, $message)) {
                    $stopped[] = $run->id;
                }
            } catch (RunNotFound) {
                // Deleted meanwhile.
            }
        }

        return $stopped;
    }
}
