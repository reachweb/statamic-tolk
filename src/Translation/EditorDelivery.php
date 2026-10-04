<?php

namespace Reach\StatamicTolk\Translation;

use LogicException;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\UnitKind;
use Reach\StatamicTolk\Exceptions\FieldFailed;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\State\StateWriter;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Targets\TargetRef;

/**
 * Hands assembled fields to the open publish form (§A8 step 5): in the shape the CP's edit page gives values and meta,
 * with a pending state entry for each, since nothing is saved until the editor saves the form.
 */
final readonly class EditorDelivery
{
    public function __construct(
        private Pipeline $pipeline,
        private StateWriter $stateWriter,
        private StatusResolver $resolver,
    ) {}

    /**
     * A field whose override is removed comes back with `sync` and the value and meta it inherits, so the form can put
     * it back to "inherited". A review delivers only the fields it corrected, as a server-side review saves only those
     * (T7.4); the others come back `unchanged`, with their warnings, and get no pending entry.
     *
     * @param  list<string>  $handles
     * @return array<string, array{value: mixed, meta: mixed, incomplete: bool, warnings: list<string>, corrections: list<array>, reviewer: ?string}|array{value: mixed, meta: mixed, sync: true}|array{unchanged: true, warnings: list<string>}>
     *
     * @throws SourceChanged when a source value is not the one that was planned
     * @throws FieldFailed when a part failed for good and the plan is strict
     */
    public function deliver(Plan $plan, Target $target, array $handles): array
    {
        $ref = $target->ref() ?? throw new LogicException('Translations are delivered only into a target that exists.');
        $assembled = [];

        foreach ($handles as $handle) {
            $assembled[$handle] = $this->pipeline->assemble($plan, $target, $handle);
        }

        $unchanged = $plan->kind === UnitKind::Review ? array_filter($assembled, fn (AssembledField $field) => $field->corrections === []) : [];
        $removed = array_filter($assembled, fn (AssembledField $field) => $field->remove);
        $delivered = $this->translated($plan, $ref, $target, array_diff_key($assembled, $removed, $unchanged))
            + $this->inherited($target, $removed)
            + array_map(fn (AssembledField $field) => ['unchanged' => true, 'warnings' => $field->warnings], $unchanged);

        return array_replace(array_fill_keys(array_keys($assembled), null), $delivered);
    }

    /**
     * @param  array<string, AssembledField>  $fields
     */
    private function translated(Plan $plan, TargetRef $ref, Target $target, array $fields): array
    {
        if ($fields === []) {
            return [];
        }

        // Values and meta come from one instance: Bard and Replicator meta is computed from the pre-processed value.
        $form = $target->formFields(array_map(fn (AssembledField $field) => $field->value, $fields))->preProcess();
        $values = $form->values()->all();
        $meta = $form->meta()->all();

        // Pre-processing fills set fields the raw value lacks with their defaults, and the CP stores what it receives.
        $stored = $form->process()->values()->all();
        $delivered = [];

        foreach ($fields as $handle => $field) {
            $targetHash = $this->resolver->targetHash($target->fields()->get($handle), $stored[$handle]);
            $this->stateWriter->recordPending($ref, $target->sourceSite(), $handle, $field->pending($targetHash));

            $delivered[$handle] = [
                'value' => $values[$handle],
                'meta' => $meta[$handle],
                'incomplete' => $field->incomplete,
                'warnings' => $field->warnings,
                'corrections' => array_map(fn (array $correction) => [
                    'id' => $correction['id'],
                    'label' => $plan->segments[$correction['id']]['label'],
                    'before' => $correction['before'],
                    'after' => $correction['after'],
                    'reason' => $correction['reason'],
                ], $field->corrections),
                'reviewer' => $field->reviewer,
            ];
        }

        return $delivered;
    }

    /**
     * @param  array<string, AssembledField>  $fields
     */
    private function inherited(Target $target, array $fields): array
    {
        if ($fields === []) {
            return [];
        }

        $form = $target->formFields(array_map(fn (AssembledField $field) => $target->inheritedValue($field->handle), $fields), source: true)->preProcess();
        $values = $form->values()->all();
        $meta = $form->meta()->all();

        return array_map(fn (AssembledField $field) => ['value' => $values[$field->handle], 'meta' => $meta[$field->handle], 'sync' => true], $fields);
    }
}
