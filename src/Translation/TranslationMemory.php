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
     * line up one to one, and gives each current source leaf the earlier translation of its text in its own numbering.
     * The n-th copy of a text takes the n-th earlier translation of it (D6: an unchanged copy keeps its own, which the
     * model or the reviewer may have made differ from another copy's), else the first that renumbers.
     *
     * @param  mixed  $targetValue  the target's own value, untouched machine output of the state
     * @param  list<Leaf>  $sourceLeaves  the current source value's leaves
     * @return array<int, string> source leaf index → target leaf text
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

        $earlier = [];

        foreach (array_values($state->segments) as $index => $hash) {
            if ($hash !== null) {
                $earlier[$hash][] = $targetLeaves[$index];
            }
        }

        $memory = $copies = [];

        foreach ($sourceLeaves as $index => $leaf) {
            $hash = Fingerprint::ofText($leaf->text);
            $copy = $copies[$hash] = ($copies[$hash] ?? -1) + 1;
            $candidates = $earlier[$hash] ?? [];

            foreach (isset($candidates[$copy]) ? [$candidates[$copy], ...$candidates] : $candidates as $target) {
                if (($text = Renumber::like($target, $leaf)) !== null) {
                    $memory[$index] = $text;
                    break;
                }
            }
        }

        return $memory;
    }
}
