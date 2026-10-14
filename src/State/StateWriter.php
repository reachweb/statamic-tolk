<?php

namespace Reach\StatamicTolk\State;

use Carbon\CarbonImmutable;
use Closure;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Support\GitListeners;
use Reach\StatamicTolk\Targets\TargetRef;
use Statamic\Fields\Field;

/**
 * Writes translation state. With `commit`, a method that changed state dispatches a git commit: the state lives under
 * content/, but no Statamic event fires for it. For the same reason confirm() and baseline() make the Overview count the
 * target's container again when they changed state.
 */
final readonly class StateWriter
{
    public const string COMMIT_MESSAGE = 'Translation state saved';

    public function __construct(
        private StateRepository $repository,
        private StatusResolver $resolver,
        private OverviewCache $overview,
    ) {}

    /**
     * $ref is the reference of $target, which Target::ref() does not give until the target exists.
     */
    public function recordPending(TargetRef $ref, Target $target, string $handle, PendingState $pending, bool $commit = false): void
    {
        $this->recordPendingFields($ref, $target, [$handle => $pending]);
        $this->commit($commit);
    }

    /**
     * recordPending() for several fields of one target, in one update of its state.
     *
     * @param  array<string, PendingState>  $pending  by handle
     */
    public function recordPendingFields(TargetRef $ref, Target $target, array $pending): void
    {
        if ($pending === []) {
            return;
        }

        $this->repository->update($ref, function (ItemState $state) use ($target, $pending) {
            $state = $this->located($state, $target);

            foreach ($pending as $handle => $entry) {
                $state = $state->withField((string) $handle, $state->field((string) $handle)->withPending($entry));
            }

            return $state;
        });
    }

    /**
     * Persists the reconcile of §A5 for every in-scope field that has pending entries. A dry run reports what it would
     * change and writes nothing.
     */
    public function confirm(Target $target, bool $commit = false, bool $dryRun = false): ConfirmReport
    {
        $ref = $target->ref();
        $stored = $ref === null ? null : $this->repository->get($ref);

        if ($stored === null || ! $stored->hasPending()) {
            return new ConfirmReport;
        }

        $statuses = $this->resolver->resolve($target)->fields;
        $now = CarbonImmutable::now();

        if ($dryRun) {
            return $this->reconciled($stored, $statuses, $now)[1];
        }

        $report = new ConfirmReport;

        $this->repository->update($ref, function (ItemState $state) use ($statuses, $now, &$report) {
            [$state, $report] = $this->reconciled($state, $statuses, $now);

            return $state;
        });

        if ($report->changed()) {
            $this->overview->bump($target->type(), $target->container());
        }

        $this->commit($commit && $report->changed());

        return $report;
    }

    /**
     * Records that a human owns the text of every manual field, checked against the current source, so later origin changes
     * are detected. With $includeMachine, machine output is turned manual too ("mark as manual", D27), and with
     * $includeCopies a copy of the source's text that nobody vouched for (§A6), which reads untranslated. Returns how many
     * fields changed; a dry run counts them and writes nothing.
     *
     * @param  ?list<string>  $handles  null for every in-scope field
     */
    public function baseline(Target $target, ?array $handles = null, bool $includeMachine = false, bool $includeCopies = false, bool $commit = false, bool $dryRun = false): int
    {
        $ref = $target->ref();

        if ($ref === null) {
            return 0;
        }

        $fields = [];

        // A field whose state is already its baseline would be skipped below, so it is left out before the update.
        foreach ($this->resolver->resolve($target)->fields as $handle => $field) {
            if (($handles === null || in_array((string) $handle, $handles, true))
                && $this->ownedByHuman($field, $includeMachine, $includeCopies)
                && $field->state->baselined($field->sourceHash, $field->targetHash)->toArray() !== $field->state->toArray()) {
                $fields[$handle] = $field;
            }
        }

        if ($dryRun || $fields === []) {
            return count($fields);
        }

        $now = CarbonImmutable::now();
        $count = 0;

        $this->repository->update($ref, function (ItemState $state) use ($fields, $target, $now, &$count) {
            $count = 0;

            foreach ($fields as $handle => $field) {
                $current = $state->field($handle)->reconcile($field->targetHash, $now, $field->sourceTextHash);
                $baseline = $current->baselined($field->sourceHash, $field->targetHash);

                // State written since the status was read can mean that the status no longer holds.
                if ($current->toArray() !== $field->state->toArray() || $baseline->toArray() === $current->toArray()) {
                    continue;
                }

                $state = $state->withField($handle, $baseline);
                $count++;
            }

            return $count > 0 ? $this->located($state, $target) : $state;
        });

        if ($count > 0) {
            $this->overview->bump($target->type(), $target->container());
        }

        $this->commit($commit && $count > 0);

        return $count;
    }

    /**
     * Moves to the target's state the records that the state of $from holds for fields the target has a value for but
     * no record of its own. A key of `lang/{lang}.json` that moves between the source language's JSON file and the forms
     * changes the target it belongs to (§A16), and without its record its translation would read as edited by hand.
     * The other target may walk the value differently, so its fingerprint of the value the target holds is replaced in
     * the record by the target's own.
     *
     * @param  ?Closure(string): Field  $fromField  the field the target of $from makes of a handle; null when it is the target's own
     * @return list<string> the handles whose records moved
     */
    public function adopt(Target $target, TargetRef $from, ?Closure $fromField = null): array
    {
        $ref = $target->ref();

        if ($ref === null) {
            return [];
        }

        $other = $this->repository->get($from);
        $records = [];

        foreach ($target->fields() as $handle => $field) {
            $handle = (string) $handle;
            $record = $other->field($handle);

            if (! $record->isEmpty() && $target->hasOverride($handle)) {
                $value = $target->targetValue($handle);
                $records[$handle] = $fromField === null ? $record : $record->rewalked(
                    $this->resolver->targetHash($fromField($handle), $value),
                    $this->resolver->targetHash($field, $value),
                );
            }
        }

        if ($records === []) {
            return [];
        }

        $adopted = [];

        $this->repository->update($ref, function (ItemState $state) use ($records, $target, &$adopted) {
            $adopted = [];

            foreach ($records as $handle => $record) {
                if ($state->field((string) $handle)->isEmpty()) {
                    $state = $state->withField((string) $handle, $record);
                    $adopted[] = (string) $handle;
                }
            }

            return $adopted === [] ? $state : $this->located($state, $target);
        });

        if ($adopted !== []) {
            $this->repository->update($from, fn (ItemState $state) => array_reduce($adopted, fn (ItemState $state, string $handle) => $state->withoutField($handle), $state));
        }

        return $adopted;
    }

    /**
     * Records that the overrides of the given fields were removed because their source lost its value: each record keeps
     * only its history, the outputs it held included (FieldState::removed()), so one that a form or a restored revision
     * saves again reads outdated, not manual.
     *
     * @param  list<string>  $handles
     */
    public function recordRemoved(TargetRef $ref, array $handles): void
    {
        $this->repository->update($ref, fn (ItemState $state) => array_reduce(
            $handles,
            fn (ItemState $state, string $handle) => $state->withField($handle, $state->field($handle)->removed()),
            $state,
        ));
    }

    /**
     * Deletes the target's state, or only the state of the given fields. Returns whether there was any.
     *
     * @param  ?list<string>  $handles
     */
    public function forget(TargetRef $ref, ?array $handles = null, bool $commit = false): bool
    {
        $stored = $this->repository->get($ref);

        if ($handles === null ? $stored->isEmpty() : array_intersect_key($stored->fields, array_flip($handles)) === []) {
            return false;
        }

        if ($handles === null) {
            $this->repository->delete($ref);
        } else {
            $this->repository->update($ref, fn (ItemState $state) => array_reduce(
                $handles,
                fn (ItemState $state, string $handle) => $state->withoutField($handle),
                $state,
            ));
        }

        $this->commit($commit);

        return true;
    }

    /**
     * The reconcile of confirm() applied to a stored state.
     *
     * @param  array<string, FieldStatusInfo>  $statuses
     * @return array{0: ItemState, 1: ConfirmReport}
     */
    private function reconciled(ItemState $state, array $statuses, CarbonImmutable $now): array
    {
        $promoted = $baselined = $unresolved = $expired = [];

        foreach ($statuses as $handle => $status) {
            $field = $state->field($handle);

            if ($field->pending === []) {
                continue;
            }

            $reconciled = $field->reconcile($status->targetHash, $now, $status->sourceTextHash);
            $state = $state->withField($handle, $reconciled);

            if ($field->savedPending($status->targetHash) !== null) {
                $promoted[] = $handle;
            } elseif ($field->editedPending($status->targetHash, $status->sourceTextHash) !== null && $reconciled->toArray() !== $field->toArray()) {
                $baselined[] = $handle;
            } else {
                if (count($reconciled->pending) < count($field->pending)) {
                    $expired[] = $handle;
                }

                if ($reconciled->pending !== []) {
                    $unresolved[] = $handle;
                }
            }
        }

        return [$state, new ConfirmReport($promoted, $baselined, $unresolved, $expired)];
    }

    /**
     * A manual field, with $includeMachine also machine output or a copy of the source's text the target holds, and with
     * $includeCopies such a copy. A field the target inherits has no text to own; an untranslated field with text of its
     * own is a copy (StatusResolver).
     */
    private function ownedByHuman(FieldStatusInfo $field, bool $includeMachine, bool $includeCopies): bool
    {
        return $field->targetHash !== null && match ($field->status) {
            FieldStatus::Manual => true,
            FieldStatus::Current, FieldStatus::Outdated => $includeMachine,
            FieldStatus::Untranslated => $includeMachine || $includeCopies,
            default => false,
        };
    }

    /**
     * $state with the source site of $target and, for an entry, its collection and site, which tolk:prune reads once core
     * no longer finds the entry: it indexes no entry of a site its collection does not list (§A5).
     */
    private function located(ItemState $state, Target $target): ItemState
    {
        $state = $state->withSourceSite($target->sourceSite());

        return $target->type() === ContentType::Entry ? $state->withCollection($target->container(), $target->targetSite()) : $state;
    }

    private function commit(bool $commit): void
    {
        if ($commit) {
            GitListeners::commit(self::COMMIT_MESSAGE);
        }
    }
}
