<?php

namespace Reach\StatamicTolk\State;

use Carbon\CarbonImmutable;
use Closure;
use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\ContentType;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Exceptions\UnreadableState;
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
        private ContentWalker $walker,
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
     * Persists the reconcile of §A5 for every in-scope field that has pending entries, and with $recordCopies records the
     * Bard copies of a source without text that no record describes (§A6). A dry run reports what it would change and
     * writes nothing.
     */
    public function confirm(Target $target, bool $commit = false, bool $dryRun = false, bool $recordCopies = true): ConfirmReport
    {
        $ref = $target->ref();
        $stored = $ref === null ? null : $this->repository->get($ref);
        $copies = $stored === null || ! $recordCopies ? [] : $this->textlessCopies($target, $stored);

        if ($stored === null || (! $stored->hasPending() && $copies === [])) {
            return new ConfirmReport;
        }

        $statuses = $stored->hasPending() ? $this->resolver->resolve($target)->fields : [];
        $now = CarbonImmutable::now();

        if ($dryRun) {
            return $this->reconciled($stored, $statuses, $now, $target, $copies)[1];
        }

        $report = new ConfirmReport;

        $this->repository->update($ref, function (ItemState $state) use ($statuses, $now, $target, $copies, &$report) {
            [$state, $report] = $this->reconciled($state, $statuses, $now, $target, $copies);

            return $state;
        });

        if ($report->changed()) {
            $this->overview->bump($target->type(), $target->container());
        }

        $this->commit($commit && $report->changed());

        return $report;
    }

    /**
     * Records the Bard copies of a source without text that confirm() records, and nothing else, for a localization
     * just saved by anyone (Listeners\RecordBardCopiesOfSavedContent): once the source has text, such a copy can no
     * longer be told apart from a field emptied by hand.
     *
     * @return list<string> the handles recorded
     */
    public function recordCopies(Target $target, bool $commit = false): array
    {
        $ref = $target->ref();

        if ($ref === null || ! $target->fields()->contains(fn (Field $field) => $field->type() === 'bard')) {
            return [];
        }

        $copies = $this->textlessCopies($target, $this->repository->get($ref));

        if ($copies === []) {
            return [];
        }

        $copied = [];

        $this->repository->update($ref, function (ItemState $state) use ($target, $copies, &$copied) {
            [$state, $report] = $this->reconciled($state, [], CarbonImmutable::now(), $target, $copies);
            $copied = $report->copies;

            return $state;
        });

        if ($copied !== []) {
            $this->overview->bump($target->type(), $target->container());
        }

        $this->commit($commit && $copied !== []);

        return $copied;
    }

    /**
     * Records that a human owns the text of every manual field, checked against the current source, so later origin changes
     * are detected. With $includeMachine, machine output is turned manual too ("mark as manual", D27), checked against the
     * source it was translated from, so an outdated one needs attention; one with a part left untranslated stays machine
     * output, which the next Update completes (D28). With $includeCopies also a copy of the source's text that nobody
     * vouched for (§A6), which reads untranslated. Returns how many fields changed; a dry run counts them and writes nothing.
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
                && $this->baselined($field, $field->state)->toArray() !== $field->state->toArray()) {
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
                $baseline = $this->baselined($field, $current);

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
     * Records the collection and site of an entry in its state when the state names others or none, as one written by
     * 1.0.0-beta.1 does, so tolk:prune keeps it once its site leaves the collection (§A5). Returns whether the state
     * changed; a dry run tells whether it would and writes nothing.
     */
    public function locate(TargetRef $ref, string $collection, string $site, bool $dryRun = false): bool
    {
        $unlocated = fn (ItemState $state) => ! $state->isEmpty() && ($state->collection !== $collection || $state->site !== $site);

        if (! $unlocated($this->repository->get($ref))) {
            return false;
        }

        if ($dryRun) {
            return true;
        }

        $changed = false;

        $this->repository->update($ref, function (ItemState $state) use ($unlocated, $collection, $site, &$changed) {
            $changed = $unlocated($state);

            return $changed ? $state->withCollection($collection, $site) : $state;
        });

        return $changed;
    }

    /**
     * Moves to the target's state the records that the state of $from holds for fields the target has a value for but
     * no record of its own, or one with only the history of an override Update removed, which the moved record keeps
     * (FieldState::describesNoValue()). A key of `lang/{lang}.json` that moves between the source language's JSON file and the forms
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
                $current = $state->field((string) $handle);

                if ($current->describesNoValue()) {
                    $state = $state->withField((string) $handle, $record->replacing($current));
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
     * Moves the state of $from to $to, a reference of the same content under its new identity, unless $to has state of
     * its own. Both records are locked while it moves. Returns whether anything moved.
     */
    public function move(TargetRef $from, TargetRef $to, bool $commit = false): bool
    {
        if ($this->repository->get($from)->isEmpty()) {
            return false;
        }

        $moved = false;

        $this->repository->update($from, function (ItemState $stored) use ($to, &$moved) {
            if ($stored->isEmpty()) {
                return $stored;
            }

            $this->repository->update($to, function (ItemState $state) use ($stored, $to, &$moved) {
                $moved = $state->isEmpty();

                return $moved ? $stored->withReference($to) : $state;
            });

            return $moved ? ItemState::empty($stored->reference) : $stored;
        });

        $this->commit($commit && $moved);

        return $moved;
    }

    /**
     * Gives a target without state the records of the target of the same sites that its text was copied from, as core's
     * Duplicate action copies a localization. A record is copied as the original reads it, without the pending entries
     * of the original's plans. Where the copy holds other text than the original, such as the title core adds
     * "(Duplicated)" to, machine output keeps its record with that text as an earlier output, so it reads outdated; a
     * field edited by hand keeps its record and stays manual, and any other field gets none.
     *
     * @return list<string> the handles given a record
     */
    public function copy(Target $target, Target $original): array
    {
        $ref = $target->ref();

        if ($ref === null || $original->ref() === null || $target->targetSite() !== $original->targetSite() || $target->sourceSite() !== $original->sourceSite() || ! $this->repository->get($ref)->isEmpty()) {
            return [];
        }

        $originals = $this->resolver->resolve($original)->fields;
        $records = [];

        foreach ($target->fields() as $handle => $field) {
            if (isset($originals[$handle]) && ($record = $this->copied($originals[$handle], $this->resolver->currentTargetHash($target, $field))) !== null) {
                $records[(string) $handle] = $record;
            }
        }

        if ($records === []) {
            return [];
        }

        $copied = [];

        $this->repository->update($ref, function (ItemState $state) use ($records, $target, &$copied) {
            // State written since it was read belongs to the copy itself.
            if (! $state->isEmpty()) {
                $copied = [];

                return $state;
            }

            foreach ($records as $handle => $record) {
                $state = $state->withField((string) $handle, $record);
            }

            $copied = array_map('strval', array_keys($records));

            return $this->located($state, $target);
        });

        return $copied;
    }

    /**
     * Deletes the target's state, or only the state of the given fields. Returns whether there was any. Nothing of a
     * record that is deleted whole is kept, so one that cannot be read is deleted too; single fields are not.
     *
     * @param  ?list<string>  $handles
     *
     * @throws UnreadableState for single fields of a record that cannot be read
     */
    public function forget(TargetRef $ref, ?array $handles = null, bool $commit = false): bool
    {
        try {
            $stored = $this->repository->get($ref);
        } catch (UnreadableState $exception) {
            if ($handles !== null) {
                throw $exception;
            }

            $this->repository->delete($ref);
            $this->commit($commit);

            return true;
        }

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
     * The reconcile of confirm() applied to a stored state, with the records of $copies given to the target's fields
     * whose records still describe no value, keeping their history.
     *
     * @param  array<string, FieldStatusInfo>  $statuses
     * @param  array<string, FieldState>  $copies
     * @return array{0: ItemState, 1: ConfirmReport}
     */
    private function reconciled(ItemState $state, array $statuses, CarbonImmutable $now, Target $target, array $copies): array
    {
        $promoted = $baselined = $unresolved = $expired = $plans = $copied = [];

        // State written since it was read belongs to the field.
        foreach ($copies as $handle => $record) {
            $current = $state->field((string) $handle);

            if ($current->describesNoValue()) {
                $state = $state->withField((string) $handle, $record->replacing($current));
                $copied[] = (string) $handle;
            }
        }

        if ($copied !== []) {
            $state = $this->located($state, $target);
        }

        foreach ($statuses as $handle => $status) {
            $field = $state->field($handle);

            if ($field->pending === []) {
                continue;
            }

            $reconciled = $field->reconcile($status->targetHash, $now, $status->sourceTextHash);
            $state = $state->withField($handle, $reconciled);

            if ($field->savedPending($status->targetHash) !== null) {
                $promoted[] = $handle;
                array_push($plans, ...array_map(fn (PendingState $pending) => $pending->plan, $field->pending));
            } elseif ($field->editedPending($status->targetHash, $status->sourceTextHash) !== null && $reconciled->toArray() !== $field->toArray()) {
                $baselined[] = $handle;
                array_push($plans, ...array_map(fn (PendingState $pending) => $pending->plan, $field->pending));
            } else {
                if (count($reconciled->pending) < count($field->pending)) {
                    $expired[] = $handle;
                }

                if ($reconciled->pending !== []) {
                    $unresolved[] = $handle;
                }
            }
        }

        return [$state, new ConfirmReport($promoted, $baselined, $unresolved, $expired, array_values(array_unique($plans)), $copied)];
    }

    /**
     * The records for the Bard fields without a record of their value, such as one with only the history of an override
     * Update removed, whose own value has no text, like their source: the CP saves
     * such a copy when an inherited Bard field desyncs itself while the form loads (T6.11). Recorded as output made
     * from that source, the field reads current, and outdated once the source changes, so Update translates it; without
     * a record it would read as emptied by hand once the source has text, and never be translated (D5). A Bard field
     * only: what it holds besides text (an image, a rule) is not tracked (D27), and the copy cannot be told apart by
     * value, since Bard rewrites its JSON. A field of another type without text, such as an address, can differ by
     * site on purpose.
     *
     * @return array<string, FieldState> by handle
     */
    private function textlessCopies(Target $target, ItemState $stored): array
    {
        $copies = [];
        $none = Fingerprint::ofLeaves([]);

        foreach ($target->fields() as $handle => $field) {
            if ($field->type() !== 'bard' || ! $stored->field((string) $handle)->describesNoValue() || $this->resolver->currentTargetHash($target, $field) !== $none) {
                continue;
            }

            $source = $target->sourceValue((string) $handle);

            if ($this->walker->leaves($field, $source) === []) {
                $copies[(string) $handle] = new FieldState(sourceHash: Fingerprint::of($source), targetHash: $none);
            }
        }

        return $copies;
    }

    /**
     * The record copy() gives a field whose text has the fingerprint $targetHash (null while it inherits), from the
     * status of the field it was copied from; null for none.
     */
    private function copied(FieldStatusInfo $original, ?string $targetHash): ?FieldState
    {
        $record = $original->state->withoutPending();

        return match (true) {
            $record->isEmpty() => null,
            $targetHash === $original->targetHash => $record,
            $targetHash === null => null,
            $original->status === FieldStatus::Manual, $targetHash === $record->targetHash, $record->inHistory($targetHash) => $record,
            $original->status === FieldStatus::Current, $original->status === FieldStatus::Outdated => $record->withHistory(
                ($original->targetHash === null ? null : $record->machineSourceHash($original->targetHash)) ?? $record->sourceHash,
                $targetHash,
            ),
            default => null,
        };
    }

    /**
     * A manual field, with $includeMachine also machine output without a part left untranslated or a copy of the source's
     * text the target holds, and with $includeCopies such a copy. A field the target inherits has no text to own; an
     * untranslated field with text of its own is a copy (StatusResolver).
     */
    private function ownedByHuman(FieldStatusInfo $field, bool $includeMachine, bool $includeCopies): bool
    {
        return $field->targetHash !== null && match ($field->status) {
            FieldStatus::Manual => true,
            FieldStatus::Current, FieldStatus::Outdated => $includeMachine && ! $field->incomplete,
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

    /**
     * The field's state once its text is a baseline. Machine output is checked against the source it was translated
     * from, any other text against the current source.
     */
    private function baselined(FieldStatusInfo $field, FieldState $state): FieldState
    {
        return $state->baselined($state->machineSourceHash($field->targetHash) ?? $field->sourceHash, $field->targetHash);
    }

    private function commit(bool $commit): void
    {
        if ($commit) {
            GitListeners::commit(self::COMMIT_MESSAGE);
        }
    }
}
