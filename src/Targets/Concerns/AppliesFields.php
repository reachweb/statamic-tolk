<?php

namespace Reach\StatamicTolk\Targets\Concerns;

use LogicException;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\State\PendingState;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\ApplyResult;
use Reach\StatamicTolk\Targets\TargetRef;
use Reach\StatamicTolk\Translation\AssembledField;
use Reach\StatamicTolk\Translation\TargetResult;

/**
 * The steps of Target::apply() that every target with a blueprint shares (§A11): the check of the fields against the
 * plan, the target_changed check, the normalisation, the pending entries before the save and the confirm after it.
 * Writing the values and saving are each target's own, in applyFresh(). The using class holds the StatusResolver in
 * `$resolver` and the StateWriter in `$stateWriter`.
 */
trait AppliesFields
{
    /**
     * Resolves the target fresh and confirms it (§A11 step 1), then applies on that fresh copy.
     *
     * @param  list<AssembledField>  $fields
     */
    public function apply(array $fields, ApplyOptions $options): ApplyResult
    {
        if (! ContentWriter::active()) {
            throw new LogicException('Translations are applied only inside ContentWriter::write().');
        }

        $target = $this->fresh();
        $this->stateWriter->confirm($target);

        return $target->applyFresh($fields, $options);
    }

    /**
     * @param  list<AssembledField>  $fields
     */
    abstract private function applyFresh(array $fields, ApplyOptions $options): ApplyResult;

    /**
     * @param  list<AssembledField>  $fields
     * @return array<string, AssembledField>
     *
     * @throws SourceChanged when a field is no longer in scope
     */
    private function keyByHandle(array $fields): array
    {
        $keyed = [];

        foreach ($fields as $field) {
            if (! $this->fields()->has($field->handle)) {
                throw SourceChanged::field($field->handle);
            }

            $keyed[$field->handle] = $field;
        }

        return $keyed;
    }

    /**
     * The fields to write, and those whose text somebody changed since the plan was made, which are skipped unless
     * the operation overwrites manual edits (§A11 step 2).
     *
     * @param  array<string, AssembledField>  $fields
     * @return array{0: array<string, AssembledField>, 1: array<string, string>} the writes, and the skipped handles with the reason
     */
    private function writable(array $fields, ApplyOptions $options): array
    {
        $writes = $skipped = [];

        foreach ($fields as $handle => $field) {
            if ($this->resolver->currentTargetHash($this, $this->fields()->get($handle)) !== $field->targetBefore && ! $options->overwriteManual) {
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
     * @param  object  $item  the entry, term or variables the values are written into
     * @param  array<string, AssembledField>  $writes
     * @return array<string, mixed> the normalised values by handle; a field that is removed has none
     */
    private function prepareWrites(object $item, TargetRef $ref, array $writes): array
    {
        $values = $this->normalise($item, array_map(
            fn (AssembledField $field) => $field->value,
            array_filter($writes, fn (AssembledField $field) => ! $field->remove),
        ));

        foreach ($values as $handle => $value) {
            $this->stateWriter->recordPending($ref, $this->sourceSite(), $handle, $this->pending($writes[$handle], $value));
        }

        return $values;
    }

    /**
     * After the save: forgets the state of the removed fields and promotes the pending entries (§A11 step 4).
     *
     * @param  array<string, AssembledField>  $writes
     */
    private function confirmWrites(TargetRef $ref, array $writes): void
    {
        $removed = $this->removedHandles($writes);

        if ($removed !== []) {
            $this->stateWriter->forget($ref, $removed);
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
     * The values as a load and save in the CP leaves them (§A11 step 3), so a later CP save does not rewrite them.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalise(object $item, array $values): array
    {
        if ($values === []) {
            return [];
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
