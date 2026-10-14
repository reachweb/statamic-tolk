<?php

namespace Reach\StatamicTolk\Targets\Concerns;

use LogicException;
use Reach\StatamicTolk\Content\RowIds;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\State\PendingState;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\ApplyResult;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Translation\AssembledField;
use Reach\StatamicTolk\Translation\SourceCheck;
use Reach\StatamicTolk\Translation\TargetResult;

/**
 * The steps of Target::apply() that the targets share (§A11): the check of the fields and their sources against the
 * plan, the target_changed check, the normalisation of a target with a blueprint, the pending entries before the save
 * and the confirm after it.
 * Writing the values and saving are each target's own, in applyFresh(). The using class holds the StatusResolver in
 * `$resolver` and the StateWriter in `$stateWriter`.
 */
trait AppliesFields
{
    /**
     * Resolves the target fresh, checks that every field is still in scope and that its source is still the value it was
     * assembled from, and confirms it (§A11 step 1), then applies on that fresh copy. ContentWriter::write() has just
     * reloaded what other processes saved, so the check sees a source that another process saved after the fields were
     * assembled, or before, while the target they were assembled from still held it as it was (Target::fresh()).
     *
     * @param  list<AssembledField>  $fields
     *
     * @throws SourceChanged before anything is written, state included
     */
    public function apply(array $fields, ApplyOptions $options): ApplyResult
    {
        if (! ContentWriter::active()) {
            throw new LogicException('Translations are applied only inside ContentWriter::write().');
        }

        $target = $this->fresh();

        foreach ($fields as $field) {
            SourceCheck::verify($target, $field->handle, $field->sourceHash);
        }

        $this->stateWriter->confirm($target);

        return $target->applyFresh($fields, $options);
    }

    /**
     * @param  list<AssembledField>  $fields
     */
    abstract private function applyFresh(array $fields, ApplyOptions $options): ApplyResult;

    /**
     * The fields by handle; apply() has checked that each is in scope.
     *
     * @param  list<AssembledField>  $fields
     * @return array<string, AssembledField>
     */
    private function keyByHandle(array $fields): array
    {
        return array_column($fields, null, 'handle');
    }

    /**
     * The fields to write, and those whose text somebody changed since the plan was made, which are skipped unless
     * the operation overwrites manual edits (§A11 step 2). A reviewed field is skipped either way: its value is the
     * text the plan read with the corrections, so writing it would undo the edit. A target that already holds what the
     * field writes has not changed: a run's attempt that saved it and then threw writes it again on its next attempt.
     *
     * @param  array<string, AssembledField>  $fields
     * @return array{0: array<string, AssembledField>, 1: array<string, string>} the writes, and the skipped handles with the reason
     */
    private function writable(array $fields, ApplyOptions $options): array
    {
        $writes = $skipped = [];

        foreach ($fields as $handle => $field) {
            $current = $this->resolver->currentTargetHash($this, $this->fields()->get($handle));
            $changed = $current !== $field->targetBefore && $current !== $field->targetHash;

            if ($changed && ($field->fromTarget || ! $options->overwriteManual)) {
                $skipped[$handle] = TargetResult::TARGET_CHANGED;
            } else {
                $writes[$handle] = $field;
            }
        }

        return [$writes, $skipped];
    }

    /**
     * Normalises the values to write into $item (§A11 step 3) and records a pending entry for each before the save.
     *
     * @param  ?object  $item  the entry, term or variables the values are written into; null for a target without a
     *                         blueprint, whose values are written as they are
     * @param  array<string, AssembledField>  $writes
     * @return array<string, mixed> the normalised values by handle; a field that is removed has none
     */
    private function prepareWrites(?object $item, TargetRef $ref, array $writes): array
    {
        $values = array_map(
            fn (AssembledField $field) => $field->value,
            array_filter($writes, fn (AssembledField $field) => ! $field->remove),
        );
        $values = $item === null ? $values : $this->normalise($item, $values);

        $pending = [];

        foreach ($values as $handle => $value) {
            $pending[$handle] = $this->pending($writes[$handle], $value);
        }

        $this->stateWriter->recordPendingFields($ref, $this, $pending);

        return $values;
    }

    /**
     * After the save: keeps only the history of the removed fields' state and promotes the pending entries (§A11 step 4).
     *
     * @param  array<string, AssembledField>  $writes
     */
    private function confirmWrites(TargetRef $ref, array $writes): void
    {
        $removed = $this->removedHandles($writes);

        if ($removed !== []) {
            $this->stateWriter->recordRemoved($ref, $removed);
        }

        $this->stateWriter->confirm($this->fresh());
    }

    /**
     * @param  array<string, AssembledField>  $writes
     * @return list<string>
     */
    private function removedHandles(array $writes): array
    {
        return array_keys(array_filter($writes, fn (AssembledField $field) => $field->remove));
    }

    /**
     * The values as a load and save in the CP leaves them (§A11 step 3), so a later CP save does not rewrite them, with
     * the target's own row and set ids where the source has none.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalise(object $item, array $values): array
    {
        if ($values === []) {
            return [];
        }

        foreach ($values as $handle => $value) {
            $values[$handle] = RowIds::keep($this->fields()->get($handle), $value, $this->targetValue($handle));
        }

        // Blueprint objects are shared (by every entry of a collection, by every site of a term), so the parent is set
        // right before fields().
        $processed = $item->blueprint()->setParent($item)->fields()
            ->addValues($values)
            ->preProcess()
            ->process()
            ->values()
            ->all();

        return array_intersect_key($processed, $values);
    }

    private function pending(AssembledField $field, mixed $value): PendingState
    {
        return $field->pending($this->resolver->targetHash($this->fields()->get($field->handle), $value));
    }
}
