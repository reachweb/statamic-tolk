<?php

namespace Reach\StatamicTolk\Translation;

use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\Markup\Renumber;
use Reach\StatamicTolk\State\FieldState;
use Statamic\Fields\Field;

/**
 * The earlier translations of one field that an update can reuse (§A7 "Reuse on update").
 */
final readonly class TranslationMemory
{
    public function __construct(private ContentWalker $walker) {}

    /**
     * Pairs the target's leaves with the source leaf fingerprints of the state, which the pipeline stored only when they
     * line up one to one. Each target leaf is expressed in the numbering of the current source leaf with its fingerprint.
     *
     * @param  mixed  $targetValue  the target's own value, untouched machine output of the state
     * @param  list<Leaf>  $sourceLeaves  the current source value's leaves
     * @return array<string, string> source leaf fingerprint → target leaf text
     */
    public function forField(Field $field, FieldState $state, mixed $targetValue, array $sourceLeaves): array
    {
        if ($state->segments === []) {
            return [];
        }

        $targetLeaves = $this->walker->leaves($field, $targetValue, target: true);

        if (count($targetLeaves) !== count($state->segments)) {
            return [];
        }

        $sources = [];

        foreach ($sourceLeaves as $leaf) {
            $sources[Fingerprint::ofText($leaf->text)] ??= $leaf;
        }

        $memory = [];

        foreach (array_values($state->segments) as $index => $hash) {
            if ($hash === null || isset($memory[$hash]) || ! isset($sources[$hash])) {
                continue;
            }

            if (($text = Renumber::like($targetLeaves[$index], $sources[$hash])) !== null) {
                $memory[$hash] = $text;
            }
        }

        return $memory;
    }
}
