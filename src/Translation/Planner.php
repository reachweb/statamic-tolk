<?php

namespace Reach\StatamicTolk\Translation;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\Markup\Placeholders;
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

        foreach ($target->fields() as $handle => $field) {
            $status = $resolved->fields[$handle];

            if (($reason = $this->skipReason($target, $options, $handle, $status->status)) !== null) {
                $skipped[$handle] = $reason;

                continue;
            }

            $leaves = $this->walker->leaves($field, $target->sourceValue($handle));
            $known = $reuse && $this->reusable($status) ? $this->memory->forField($field, $status->state, $target->targetValue($handle), $leaves) : [];

            $fields[$handle] = $this->field($handle, $status, $leaves, $known, $segments);
        }

        $batches = $this->batchPlanner->plan($segments, $this->budget($options->profile, $segments), (int) config('tolk.batch.max_segments'));

        return new Plan(
            id: (string) Str::ulid(),
            kind: UnitKind::Translate,
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
        );
    }

    /**
     * Source characters per batch. The editor sends its units in parallel, so a small entry is still split over them and
     * its fields fill one after another (T6.11). Reused segments are never batched and do not count.
     */
    private function budget(string $profile, array $segments): int
    {
        $max = (int) config("tolk.batch.max_chars.{$profile}");

        if ($profile !== 'web') {
            return $max;
        }

        $chars = array_sum(array_map(fn (array $segment) => $segment['translation'] === null ? mb_strlen($segment['text']) : 0, $segments));
        $share = (int) ceil($chars / max(1, (int) config('tolk.concurrency.editor')));

        return min($max, max((int) config('tolk.batch.min_chars'), $share));
    }

    /**
     * The first reason that rules a field out, or null when it is planned (§A2, §A7).
     */
    private function skipReason(Target $target, PlanOptions $options, string $handle, FieldStatus $status): ?string
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
        $text = Str::squish(Placeholders::visible(str_replace('<br/>', ' ', $segment['text']), SegmentKind::from($segment['kind'])));

        if (mb_strlen($text) <= self::NEIGHBOUR_LENGTH) {
            return $text;
        }

        return $before
            ? '…'.mb_substr($text, -(self::NEIGHBOUR_LENGTH - 1))
            : mb_substr($text, 0, self::NEIGHBOUR_LENGTH - 1).'…';
    }
}
