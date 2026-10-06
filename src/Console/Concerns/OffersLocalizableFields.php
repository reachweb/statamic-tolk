<?php

namespace Reach\StatamicTolk\Console\Concerns;

use Reach\StatamicTolk\Support\Audit;

/**
 * Asks, blueprint by blueprint, whether to make the fields of Audit::notLocalizable() localizable (default no), and
 * names the fields of imported fieldsets, which are changed in the fieldset.
 */
trait OffersLocalizableFields
{
    /**
     * @param  list<array>  $items  Audit::notLocalizable()'s items
     */
    private function offerLocalizable(Audit $audit, array $items): void
    {
        foreach ($items as $item) {
            [$fixable, $imported] = collect($item['fields'])->partition(fn (array $field) => $field['fieldset'] === null);

            foreach ($imported as $field) {
                $this->components->warn(sprintf(
                    '%s: %s comes from the fieldset %s, which this command does not change. Make it localizable in the fieldset, which changes every blueprint that imports it.',
                    $item['name'],
                    $field['label'],
                    $field['fieldset'],
                ));
            }

            if ($fixable->isEmpty() || ! $this->confirm(sprintf('Make %s localizable in %s?', $fixable->implode('label', ', '), $item['name']), false)) {
                continue;
            }

            $count = $audit->makeLocalizable($item['namespace'], $item['blueprint'], $fixable->pluck('handle')->all());

            $this->components->info(sprintf(
                '%s: %s localizable. Each localization inherits the origin\'s text until it is translated.',
                $item['name'],
                $count === 1 ? '1 field is now' : "{$count} fields are now",
            ));
        }
    }
}
