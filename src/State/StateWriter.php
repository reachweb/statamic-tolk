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
     * Persists the reconcile of §A5 for every in-scope field that has pending entries.
     */
    public function confirm(Target $target, bool $commit = false): ConfirmReport
    {
        $ref = $target->ref();

        if ($ref === null || ! $this->repository->get($ref)->hasPending()) {
            return new ConfirmReport;
        }

        $targetHashes = array_map(fn (FieldStatusInfo $field) => $field->targetHash, $this->resolver->resolve($target)->fields);
        $now = CarbonImmutable::now();
        $report = new ConfirmReport;

        $this->repository->update($ref, function (ItemState $state) use ($targetHashes, $now, &$report) {
            $promoted = $baselined = $unresolved = $expired = [];

            foreach ($targetHashes as $handle => $targetHash) {
                $field = $state->field($handle);

                if ($field->pending === []) {
                    continue;
                }

                $reconciled = $field->reconcile($targetHash, $now);
                $state = $state->withField($handle, $reconciled);

                if ($field->savedPending($targetHash) !== null) {
                    $promoted[] = $handle;
                } elseif ($field->editedPending($targetHash) !== null) {
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

            $report = new ConfirmReport($promoted, $baselined, $unresolved, $expired);

            return $state;
        });

        $this->commit($commit && $report->changed());

        return $report;
    }

    /**
     * Records that a human owns the text of every manual field, checked against the current source, so later origin changes
     * are detected. With $includeMachine, machine output is turned manual too ("mark as manual", D27). Returns how many
     * fields changed.
     *
     * @param  ?list<string>  $handles  null for every in-scope field
     */
    public function baseline(Target $target, ?array $handles = null, bool $includeMachine = false, bool $commit = false): int
    {
        $ref = $target->ref();

        if ($ref === null) {
            return 0;
        }

        $fields = [];

        foreach ($this->resolver->resolve($target)->fields as $handle => $field) {
            if (($handles === null || in_array((string) $handle, $handles, true)) && $this->ownedByHuman($field, $includeMachine)) {
                $fields[$handle] = $field;
            }
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
                $current = $state->field($handle)->reconcile($field->targetHash, $now);
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
     * A manual field, or with $includeMachine machine output the target holds. A field the target inherits has no text to own.
     */
    private function ownedByHuman(FieldStatusInfo $field, bool $includeMachine): bool
    {
        return $field->targetHash !== null && match ($field->status) {
            FieldStatus::Manual => true,
            FieldStatus::Current, FieldStatus::Outdated => $includeMachine,
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
