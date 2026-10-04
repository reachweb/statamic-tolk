<?php

namespace Reach\StatamicTolk\State;

use Carbon\CarbonImmutable;
use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Contracts\StateRepository;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\FieldStatus;
use Statamic\Fields\Field;

/**
 * Derives field and item status from content and state (§A6). It reconciles pending entries in memory only and never writes.
 */
final readonly class StatusResolver
{
    public function __construct(
        private StateRepository $repository,
        private ContentWalker $walker,
    ) {}

    public function resolve(Target $target): TargetStatus
    {
        $ref = $target->ref();
        $state = $ref === null ? null : $this->repository->get($ref);
        $now = CarbonImmutable::now();
        $fields = [];

        foreach ($target->fields() as $handle => $field) {
            $fields[$handle] = $this->field($target, $field, $state?->field($handle) ?? new FieldState, $now);
        }

        return TargetStatus::of($target->exists(), $fields);
    }

    /**
     * The text fingerprint of a target's own value (§A4), walked as a target (§A3).
     */
    public function targetHash(Field $field, mixed $value): string
    {
        return Fingerprint::ofLeaves(array_map(fn (Leaf $leaf) => $leaf->text, $this->walker->leaves($field, $value, target: true)));
    }

    /**
     * The text fingerprint of the field's value in the target now, or null while the target does not override it.
     */
    public function currentTargetHash(Target $target, Field $field): ?string
    {
        return $target->hasOverride($field->handle()) ? $this->targetHash($field, $target->targetValue($field->handle())) : null;
    }

    private function field(Target $target, Field $field, FieldState $stored, CarbonImmutable $now): FieldStatusInfo
    {
        $handle = $field->handle();
        $sourceValue = $target->sourceValue($handle);
        $sourceHash = Fingerprint::of($sourceValue);
        $targetHash = $this->currentTargetHash($target, $field);
        $state = $stored->reconcile($targetHash, $now);

        // Machine output the target still holds; without an override only a translation identical to its source counts.
        $machine = $targetHash === null ? $state->identical : $targetHash === $state->targetHash;

        // A source without text still has a fingerprint: machine output made from it is current, such as the copy written by a clear (§A7).
        $status = match (true) {
            $targetHash === null && $this->walker->leaves($field, $sourceValue) === [] => FieldStatus::NotApplicable,
            $machine => ! $state->incomplete && $state->sourceHash === $sourceHash ? FieldStatus::Current : FieldStatus::Outdated,
            $targetHash === null => FieldStatus::Untranslated,
            $state->inHistory($targetHash) => FieldStatus::Outdated,
            default => FieldStatus::Manual,
        };

        return new FieldStatusInfo(
            status: $status,
            sourceChanged: $state->sourceHash === null ? null : $state->sourceHash !== $sourceHash,
            incomplete: $machine && $state->incomplete,
            translatedAt: $state->translatedAt,
            translator: $state->translator,
            reviewer: $state->reviewer,
            label: $field->display(),
            state: $state,
            sourceHash: $sourceHash,
            targetHash: $targetHash,
        );
    }
}
