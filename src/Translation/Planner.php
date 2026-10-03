<?php

namespace Reach\StatamicTolk\Translation;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\Mode;
use Reach\StatamicTolk\Enums\UnitKind;
use Reach\StatamicTolk\State\FieldStatusInfo;
use Reach\StatamicTolk\State\StatusResolver;
use Statamic\Fields\Field;

/**
 * Decides what one target needs (§A8 step 1). Planning reads content and state and has no side effects.
 */
final readonly class Planner
{
    public function __construct(
        private StatusResolver $resolver,
        private ContentWalker $walker,
        private BatchPlanner $batchPlanner,
    ) {}

    public function plan(Target $target, PlanOptions $options): Plan
    {
        if ($options->mode === Mode::Review) {
            throw new InvalidArgumentException('Mode review is planned by Planner::planReview().');
        }

        $statuses = $this->resolver->resolve($target)->fields;
        $fields = [];
        $segments = [];
        $skipped = [];

        foreach ($target->fields() as $handle => $field) {
            $status = $statuses[$handle];

            if (($reason = $this->skipReason($target, $options, $handle, $status->status)) !== null) {
                $skipped[$handle] = $reason;

                continue;
            }

            $fields[$handle] = $this->field($field, $status, $target->sourceValue($handle), $segments);
        }

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
            segments: $segments,
            batches: $this->batchPlanner->plan(
                $segments,
                (int) config("tolk.batch.max_chars.{$options->profile}"),
                (int) config('tolk.batch.max_segments'),
            ),
            skipped: $skipped,
        );
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
     * Walks the source value into segments, numbered on from the segments planned so far.
     */
    private function field(Field $field, FieldStatusInfo $status, mixed $sourceValue, array &$segments): array
    {
        $ids = [];

        foreach ($this->walker->leaves($field, $sourceValue) as $index => $leaf) {
            $id = 's'.(count($segments) + 1);
            $segments[$id] = $this->segment($field->handle(), $index, $leaf);
            $ids[] = $id;
        }

        return [
            'source_hash' => $status->sourceHash,
            'target_before' => $status->targetHash,
            'leaf_count' => count($ids),
            'status_before' => $status->status->value,
            'segment_ids' => $ids,
            'reused' => 0,
            // The source lost its text: applying the field removes the override, or copies a value without text (§A7).
            'clear' => $ids === [],
        ];
    }

    private function segment(string $handle, int $index, Leaf $leaf): array
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
            'translation' => null,
        ];
    }
}
