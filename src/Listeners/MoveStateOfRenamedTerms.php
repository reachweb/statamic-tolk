<?php

namespace Reach\StatamicTolk\Listeners;

use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\State\OverviewCache;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\Support\GitListeners;
use Reach\StatamicTolk\Targets\TargetRef;
use Statamic\Events\TermSaved;
use Statamic\Facades\Site;
use Throwable;

/**
 * A term's reference names its slug in the taxonomy's default site. Statamic saves a term whose default slug changed
 * as a new term and removes the old one without TermDeleted (R4 §3.2), so its state moves to the new slug here; left
 * under the old one, every translation of the term would read edited by hand. Nothing it does can fail the save.
 */
final readonly class MoveStateOfRenamedTerms
{
    public function __construct(private StateWriter $writer, private OverviewCache $overview) {}

    public function handle(TermSaved $event): void
    {
        $term = $event->term;
        $original = $term->getOriginal('slug');

        if (! is_string($original) || $original === '' || $original === $term->slug()) {
            return;
        }

        $taxonomy = (string) $term->taxonomyHandle();
        $moved = false;

        // The term is saved by now, so a state that cannot be moved, such as one that does not parse, is reported and the
        // other sites still move.
        foreach (Site::all()->keys() as $site) {
            try {
                $moved = $this->writer->move(
                    new TargetRef(ContentType::Term, $taxonomy.'::'.$original, $site),
                    new TargetRef(ContentType::Term, $taxonomy.'::'.$term->slug(), $site),
                ) || $moved;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        // The Overview may have counted the taxonomy again between the term's save and this move.
        if ($moved) {
            $this->overview->bump(ContentType::Term, $taxonomy);
            GitListeners::commit(StateWriter::COMMIT_MESSAGE);
        }
    }
}
