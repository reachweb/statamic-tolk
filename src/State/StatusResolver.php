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

    /**
     * @param  ?array<string, mixed>  $values  raw values that replace the target's own values of the handles they hold,
     *                                         such as the open form's; reconciled as if they were saved
     */
    public function resolve(Target $target, ?array $values = null): TargetStatus
    {
        $ref = $target->ref();
        $state = $ref === null ? null : $this->repository->get($ref);
        $now = CarbonImmutable::now();
        $fields = [];

        foreach ($target->fields() as $handle => $field) {
            $targetHash = $values !== null && array_key_exists($handle, $values)
                ? $this->targetHash($field, $values[$handle])
                : $this->currentTargetHash($target, $field);

            $fields[$handle] = $this->field($target, $field, $targetHash, $state?->field($handle) ?? new FieldState, $now);
        }

        return TargetStatus::of($target->exists(), $fields, $state?->version ?? ItemState::VERSION);
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

    private function field(Target $target, Field $field, ?string $targetHash, FieldState $stored, CarbonImmutable $now): FieldStatusInfo
    {
        $sourceValue = $target->sourceValue($field->handle());
        $sourceHash = Fingerprint::of($sourceValue);
        // A second walk, so only where it decides something: an override without a known source, or a reconcile.
        $sourceTextHash = $targetHash !== null && ($stored->pending !== [] || $stored->sourceHash === null) ? $this->targetHash($field, $sourceValue) : null;
        $state = $stored->reconcile($targetHash, $now, $sourceTextHash);

        // Machine output the target still holds; without an override only a translation identical to its source counts.
        $machine = $targetHash === null ? $state->identical : $targetHash === $state->targetHash;

        // A source without text still has a fingerprint: machine output made from it is current, such as the copy written by a clear (§A7).
        $status = match (true) {
            $targetHash === null && $this->walker->leaves($field, $sourceValue) === [] => FieldStatus::NotApplicable,
            $machine => ! $state->incomplete && $state->sourceHash === $sourceHash ? FieldStatus::Current : FieldStatus::Outdated,
            $targetHash === null => FieldStatus::Untranslated,
            $state->inHistory($targetHash) => FieldStatus::Outdated,
            $this->isCopy($state, $targetHash, $sourceTextHash) => FieldStatus::Untranslated,
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
            sourceTextHash: $sourceTextHash,
        );
    }

    /**
     * An override nobody vouched for that holds the source's own text, as the CP saves an inherited Bard field that
     * desynced itself when the form loaded. No human changed its text (D5), so it is untranslated, not manual (T6.11).
     */
    private function isCopy(FieldState $state, string $targetHash, ?string $sourceTextHash): bool
    {
        return $state->sourceHash === null && $targetHash === $sourceTextHash && $targetHash !== Fingerprint::ofLeaves([]);
    }
}
