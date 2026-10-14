<?php

namespace Reach\StatamicTolk\Listeners;

use Statamic\Events\TermCreating;
use Throwable;

/**
 * Core's term create form, with a site selected other than the taxonomy's default site, writes the form's values into
 * both sites, an empty value for each field left empty included. The default site's file drops empty values, but the
 * other site keeps them as values of its own, so such a field does not inherit there: once the default site has text
 * it reads as emptied by hand and is never translated (D5). Before a new term is saved, the empty values that another
 * site holds with the same value in the default site are dropped, so those fields inherit. Empty is what core's file
 * of the default site drops: null, '' and []. Nothing it does can fail the save.
 */
final class DropCopiedEmptyValuesOfNewTerms
{
    // TermCreating halts on the first listener that returns something, so this one returns nothing.
    public function handle(TermCreating $event): void
    {
        try {
            $term = $event->term;
            $default = $term->inDefaultLocale()->data();

            foreach ($term->localizations() as $site => $localized) {
                $data = $localized->data();
                $copied = $site === $term->defaultLocale() ? [] : $data
                    ->filter(fn (mixed $value, int|string $key) => in_array($value, [null, '', []], true) && $default->has($key) && $default->get($key) === $value)
                    ->keys()
                    ->all();

                // A LocalizedTerm has no remove(): the site's data is set again without those keys, everything else kept.
                if ($copied !== []) {
                    $localized->data($data->except($copied));
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
