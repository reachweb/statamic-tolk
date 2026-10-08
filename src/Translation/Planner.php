<?php

namespace Reach\StatamicTolk\Translation;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\Markup\Placeholders;
use Reach\StatamicTolk\Content\Markup\Renumber;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Enums\SegmentKind;
use Reach\StatamicTolk\Enums\UnitKind;
use Reach\StatamicTolk\State\FieldStatusInfo;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\State\StatusResolver;

/**
 * Decides what one target needs (§A8 step 1). Planning reads content and state and has no side effects.
 */
final readonly class Planner
{
    /**
     * The longest neighbouring text a segment carries as context (§A7).
     */
    public const int NEIGHBOUR_LENGTH = 300;

    /**
     * Why a review leaves out a field whose source and target leaves do not pair up (§A7).
     */
    public const string STRUCTURE_DIFFERS = 'structure_differs';

    public function __construct(
        private StatusResolver $resolver,
        private ContentWalker $walker,
        private BatchPlanner $batchPlanner,
        private TranslationMemory $memory,
    ) {}

    public function plan(Target $target, PlanOptions $options): Plan
    {
        if ($options->mode === Mode::Review) {
            throw new InvalidArgumentException('Mode review is planned by Planner::planReview().');
        }

        $resolved = $this->resolver->resolve($target);
        // Mode all translates afresh, and records of another format version are not reused (§A5, §A7).
        $reuse = $options->mode !== Mode::All && $resolved->stateVersion === ItemState::VERSION;
        $fields = [];
        $segments = [];
        $skipped = [];
        $notes = [];

        foreach ($target->fields() as $handle => $field) {
            $status = $resolved->fields[$handle];

            if (($reason = $this->skipReason($target, $options, $handle, $status->status)) !== null) {
                $skipped[$handle] = $reason;

                // A source the handlers leave untouched, such as Bard data in the format of Statamic 2, has no text to
                // plan; what they noticed about it is passed on with the result.
                if ($status->status === FieldStatus::NotApplicable && $target->sourceHasValue($handle)) {
                    $this->walker->leaves($field, $target->sourceValue($handle));
                    $notes[$handle] = $this->walker->warnings();
                }

                continue;
            }

            $leaves = $this->walker->leaves($field, $target->sourceValue($handle));
            $known = $reuse && $this->reusable($status) ? $this->memory->forField($field, $status->state, $target->targetValue($handle), $leaves) : [];

            $fields[$handle] = $this->field($handle, $status, $leaves, $known, $segments);
        }

        return $this->make(UnitKind::Translate, $target, $options, $fields, $segments, $segments, $skipped, array_filter($notes));
    }

    /**
     * Plans a review of the target's existing translations (§A7 mode `review`, T7.4): one review segment per pair of a
     * source leaf and the target leaf at the same position, which carries the target's text in the source's numbering
     * as its `translation`. Every unit is a review unit. `$currentValues` (raw, stored shape) replace the target's own
     * values, as the open form holds them; a field they do not hold is not reviewed.
     *
     * @param  ?array<string, mixed>  $currentValues
     */
    public function planReview(Target $target, PlanOptions $options, ?array $currentValues = null): Plan
    {
        $resolved = $this->resolver->resolve($target, $currentValues);
        $fields = [];
        $segments = [];
        $skipped = [];

        foreach ($target->fields() as $handle => $field) {
            $status = $resolved->fields[$handle];

            if (($reason = $this->reviewSkipReason($target, $options, $handle, $status, $currentValues)) !== null) {
                $skipped[$handle] = $reason;

                continue;
            }

            $value = $currentValues === null ? $target->targetValue($handle) : $currentValues[$handle];
            $sourceLeaves = $this->walker->leaves($field, $target->sourceValue($handle));

            if ($sourceLeaves === []) {
                $skipped[$handle] = FieldStatus::NotApplicable->value;

                continue;
            }

            $translations = $this->pairs($sourceLeaves, $this->walker->leaves($field, $value, target: true));

            if ($translations === []) {
                $skipped[$handle] = self::STRUCTURE_DIFFERS;

                continue;
            }

            // The target's fingerprint when the plan was made is the stored one, also when the form holds other values (§A5).
            $targetBefore = $currentValues === null ? $status->targetHash : $this->resolver->currentTargetHash($target, $field);
            $fields[$handle] = $this->reviewField($handle, $status, $value, $targetBefore, $sourceLeaves, $translations, $segments);
        }

        // Every review segment carries a translation, which batching would read as reused.
        $batched = array_map(fn (array $segment) => ['text' => $segment['text']], $segments);

        return $this->make(UnitKind::Review, $target, $options, $fields, $segments, $batched, $skipped);
    }

    /**
     * @param  array<string, array>  $segments  the plan's segments
     * @param  array<string, array{text: string, translation?: ?string}>  $batched  the same segments as batching sees them
     */
    private function make(UnitKind $kind, Target $target, PlanOptions $options, array $fields, array $segments, array $batched, array $skipped, array $notes = []): Plan
    {
        $batches = $this->batchPlanner->plan($batched, $this->budget($options, $batched), (int) config('tolk.batch.max_segments'));

        return new Plan(
            id: (string) Str::ulid(),
            kind: $kind,
            reference: $target->ref()?->__toString(),
            target: $target->describe(),
            sourceSite: $target->sourceSite(),
            targetSite: $target->targetSite(),
            options: $options,
            document: $target->document(),
            fields: $fields,
            segments: $this->withNeighbours($fields, $segments, $batches),
            batches: $batches,
            skipped: $skipped,
            notes: $notes,
        );
    }

    /**
     * Source characters per batch. The editor sends its units in parallel, so a small entry is still split over them and
     * its fields fill one after another (T6.11). A run lane sends an item's units one after another, where a split would
     * only add calls. Reused segments are never batched and do not count.
     */
    private function budget(PlanOptions $options, array $segments): int
    {
        $max = (int) config("tolk.batch.max_chars.{$options->profile}");

        if ($options->profile !== 'web' || ! $options->parallel) {
            return $max;
        }

        $chars = array_sum(array_map(fn (array $segment) => ($segment['translation'] ?? null) === null ? mb_strlen($segment['text']) : 0, $segments));
        $share = (int) ceil($chars / max(1, (int) config('tolk.concurrency.editor')));

        return min($max, max((int) config('tolk.batch.min_chars'), $share));
    }

    /**
     * The first reason that rules a field out, or null when it is planned (§A2, §A7).
     */
    private function skipReason(Target $target, PlanOptions $options, string $handle, FieldStatus $status): ?string
    {
        if (($reason = $this->ruledOut($target, $options, $handle)) !== null) {
            return $reason;
        }

        $selected = match ($status) {
            FieldStatus::Untranslated => true,
            FieldStatus::Outdated => $options->mode !== Mode::Missing,
            FieldStatus::Current => $options->mode === Mode::All,
            FieldStatus::Manual => $options->overwriteManual,
            FieldStatus::NotApplicable => false,
        };

        return $selected ? null : $status->value;
    }

    /**
     * A review takes machine output that is current, and manual text with `overwrite_manual` (§A7). Only text the target
     * holds is reviewed, so a translation it holds only by inheriting is not.
     *
     * @param  ?array<string, mixed>  $currentValues
     */
    private function reviewSkipReason(Target $target, PlanOptions $options, string $handle, FieldStatusInfo $status, ?array $currentValues): ?string
    {
        if ($currentValues !== null && ! array_key_exists($handle, $currentValues)) {
            return 'not_requested';
        }

        if (($reason = $this->ruledOut($target, $options, $handle)) !== null) {
            return $reason;
        }

        return match (true) {
            $status->status === FieldStatus::Current => $status->targetHash !== null ? null : FieldStatus::Untranslated->value,
            $status->status === FieldStatus::Manual && $options->overwriteManual => null,
            default => $status->status->value,
        };
    }

    /**
     * The reasons of the operation and the chain rule, which come before the field's status.
     */
    private function ruledOut(Target $target, PlanOptions $options, string $handle): ?string
    {
        if ($options->fields !== null && ! in_array($handle, $options->fields, true)) {
            return 'not_requested';
        }

        if (isset($options->skip[$handle])) {
            return $options->skip[$handle];
        }

        // In a chain, an inherited source value is in another language than the prompt names (§A2).
        if ($target->sourceIsLocalization() && ! $target->sourceHasValue($handle)) {
            return 'origin_untranslated';
        }

        return null;
    }

    /**
     * Each target leaf in the numbering of the source leaf at its position, by index. Empty when the two walks differ in
     * length or kinds; a target leaf whose placeholders have no counterpart in its source leaf is left out.
     *
     * @param  list<Leaf>  $sourceLeaves
     * @param  list<Leaf>  $targetLeaves  walked as a target (§A3)
     * @return array<int, string>
     */
    private function pairs(array $sourceLeaves, array $targetLeaves): array
    {
        $kinds = fn (array $leaves) => array_map(fn (Leaf $leaf) => $leaf->kind, $leaves);

        if ($kinds($sourceLeaves) !== $kinds($targetLeaves)) {
            return [];
        }

        $translations = [];

        foreach ($sourceLeaves as $index => $leaf) {
            if (($text = Renumber::like($targetLeaves[$index], $leaf)) !== null) {
                $translations[$index] = $text;
            }
        }

        return $translations;
    }

    /**
     * Whether the target holds the untouched machine output of the state, whose leaves line up with its segments (§A7).
     */
    private function reusable(FieldStatusInfo $status): bool
    {
        return $status->status === FieldStatus::Outdated
            && $status->targetHash !== null
            && $status->targetHash === $status->state->targetHash;
    }

    /**
     * Makes the source leaves segments, numbered on from the segments planned so far. A leaf the memory knows keeps its
     * earlier translation and is not sent.
     *
     * @param  list<Leaf>  $leaves
     * @param  array<string, string>  $known  source leaf fingerprint → earlier translation
     */
    private function field(string $handle, FieldStatusInfo $status, array $leaves, array $known, array &$segments): array
    {
        $ids = [];
        $reused = 0;

        foreach ($leaves as $index => $leaf) {
            $id = 's'.(count($segments) + 1);
            $translation = $known[Fingerprint::ofText($leaf->text)] ?? null;
            $segments[$id] = $this->segment($handle, $index, $leaf, $translation);
            $ids[] = $id;
            $reused += (int) ($translation !== null);
        }

        return [
            'source_hash' => $status->sourceHash,
            'target_before' => $status->targetHash,
            'leaf_count' => count($ids),
            'status_before' => $status->status->value,
            'segment_ids' => $ids,
            'reused' => $reused,
            'reused_translator' => $reused > 0 ? $status->state->translator : null,
            // The source lost its text: applying the field removes the override, or copies a value without text (§A7).
            'clear' => $ids === [],
        ];
    }

    /**
     * A field to review. Besides the keys of a translated field it holds the value under review and the state record its
     * text belongs to, whose `segments` and `translator` a machine field keeps (T7.4).
     *
     * @param  list<Leaf>  $sourceLeaves
     * @param  array<int, string>  $translations  leaf index → the target's text in the source's numbering
     */
    private function reviewField(string $handle, FieldStatusInfo $status, mixed $value, ?string $targetBefore, array $sourceLeaves, array $translations, array &$segments): array
    {
        $ids = [];

        foreach ($translations as $index => $translation) {
            $id = 's'.(count($segments) + 1);
            $segments[$id] = $this->segment($handle, $index, $sourceLeaves[$index], $translation);
            $ids[] = $id;
        }

        return [
            'source_hash' => $status->sourceHash,
            'target_before' => $targetBefore,
            'leaf_count' => count($sourceLeaves),
            'status_before' => $status->status->value,
            'segment_ids' => $ids,
            'reused' => 0,
            'reused_translator' => null,
            'clear' => false,
            'value' => $value,
            'record' => ['segments' => $status->state->segments, 'translator' => $status->state->translator],
        ];
    }

    private function segment(string $handle, int $index, Leaf $leaf, ?string $translation): array
    {
        return [
            'field' => $handle,
            'index' => $index,
            'kind' => $leaf->kind->value,
            'text' => $leaf->text,
            'label' => $leaf->label !== '' ? $leaf->label : $handle,
            'hint' => $leaf->hint,
            'max_length' => $leaf->maxLength,
            'before' => null,
            'after' => null,
            'translation' => $translation,
        ];
    }

    /**
     * Gives each segment to translate the neighbouring source texts of its field that it is not sent with (§A7).
     *
     * @param  list<array{id: string, segment_ids: list<string>, chars: int}>  $batches
     */
    private function withNeighbours(array $fields, array $segments, array $batches): array
    {
        $batchOf = [];

        foreach ($batches as $batch) {
            $batchOf += array_fill_keys($batch['segment_ids'], $batch['id']);
        }

        foreach ($fields as $field) {
            $ids = $field['segment_ids'];

            foreach ($ids as $position => $id) {
                if (! isset($batchOf[$id])) {
                    continue;
                }

                foreach (['before' => $ids[$position - 1] ?? null, 'after' => $ids[$position + 1] ?? null] as $key => $neighbour) {
                    if ($neighbour !== null && ($batchOf[$neighbour] ?? null) !== $batchOf[$id]) {
                        $segments[$id][$key] = $this->neighbour($segments[$neighbour], $key === 'before');
                    }
                }
            }
        }

        return $segments;
    }

    /**
     * A neighbour's text as a reader sees it, cut to the end next to the segment.
     */
    private function neighbour(array $segment, bool $before): string
    {
        $text = Str::squish(Placeholders::readable($segment['text'], SegmentKind::from($segment['kind'])));

        if (mb_strlen($text) <= self::NEIGHBOUR_LENGTH) {
            return $text;
        }

        return $before
            ? '…'.mb_substr($text, -(self::NEIGHBOUR_LENGTH - 1))
            : mb_substr($text, 0, self::NEIGHBOUR_LENGTH - 1).'…';
    }
}
