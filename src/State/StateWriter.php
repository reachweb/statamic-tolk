<?php

namespace Reach\StatamicTolk\State;

use Carbon\CarbonImmutable;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Support\GitListeners;
use Reach\StatamicTolk\Targets\TargetRef;

/**
 * Writes translation state. With `commit`, a method that changed state dispatches a git commit: the state lives under
 * content/, but no Statamic event fires for it.
 */
final readonly class StateWriter
{
    public const string COMMIT_MESSAGE = 'Translation state saved';

    public function __construct(
        private StateRepository $repository,
        private StatusResolver $resolver,
    ) {}

    public function recordPending(TargetRef $ref, string $sourceSite, string $handle, PendingState $pending, bool $commit = false): void
    {
        $this->repository->update($ref, fn (ItemState $state) => $state
            ->withSourceSite($sourceSite)
            ->withField($handle, $state->field($handle)->withPending($pending)));

        $this->commit($commit);
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

        foreach ($this->resolver->resolve($target)->fields as $handle => $field) {
            if (($handles === null || in_array((string) $handle, $handles, true)) && $this->ownedByHuman($field, $includeMachine, $includeCopies)) {
                $fields[$handle] = $field;
            }
        }

        if ($dryRun) {
            return count(array_filter($fields, fn (FieldStatusInfo $field) => $field->state->baselined($field->sourceHash, $field->targetHash)->toArray() !== $field->state->toArray()));
        }

        if ($fields === []) {
            return 0;
        }

        $sourceSite = $target->sourceSite();
        $now = CarbonImmutable::now();
        $count = 0;

        $this->repository->update($ref, function (ItemState $state) use ($fields, $sourceSite, $now, &$count) {
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

            return $count > 0 ? $state->withSourceSite($sourceSite) : $state;
        });

        $this->commit($commit && $count > 0);

        return $count;
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
            } elseif ($field->editedPending($status->targetHash, $status->sourceTextHash) !== null) {
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

    private function commit(bool $commit): void
    {
        if ($commit) {
            GitListeners::commit(self::COMMIT_MESSAGE);
        }
    }
}
