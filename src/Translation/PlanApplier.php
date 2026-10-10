<?php

namespace Reach\StatamicTolk\Translation;

use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\TargetResultStatus;
use Reach\StatamicTolk\Exceptions\FieldFailed;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Targets\ApplyOptions;

/**
 * Saves a finished plan into its target (§A8 steps 4 and 5, §A11), for TargetTranslator and runs alike.
 */
final readonly class PlanApplier
{
    public const string FIELDS_FAILED_MESSAGE = 'No field could be translated completely.';

    public function __construct(private Pipeline $pipeline) {}

    /**
     * Assembles every field and saves the fields that assembled, under the write lock and from the target resolved again
     * once ContentWriter reloaded what other processes saved, so a source edited while the units ran raises
     * SourceChanged even when this process still holds it in memory. A review saves only the fields it corrected; a
     * field that failed in strict mode is listed as failed and the others are still saved.
     *
     * @throws SourceChanged
     */
    public function apply(Plan $plan, Target $target, ApplyOptions $options): TargetResult
    {
        return ContentWriter::write(fn () => $this->applyFresh($plan, $target->fresh(), $options));
    }

    private function applyFresh(Plan $plan, Target $target, ApplyOptions $options): TargetResult
    {
        $assembled = $failed = $unchanged = $corrections = [];
        $warnings = $plan->noteList();

        foreach ($plan->fieldHandles() as $handle) {
            try {
                $assembled[$handle] = $this->pipeline->assemble($plan, $target, $handle);
            } catch (FieldFailed $failure) {
                $failed[$handle] = array_values($failure->reasons)[0];
                $warnings[] = $failure->getMessage();
            }
        }

        foreach ($assembled as $handle => $field) {
            if ($field->unchanged) {
                $unchanged[$handle] = TargetResult::NO_CORRECTIONS;
                array_push($warnings, ...$field->warnings);
            }
        }

        $assembled = array_diff_key($assembled, $unchanged);

        if ($assembled === [] && $unchanged !== []) {
            return new TargetResult(
                status: TargetResultStatus::Skipped,
                ref: $target->ref(),
                fields: $this->ordered($target, $plan->skipped + $unchanged),
                warnings: $warnings,
                reason: TargetResult::NO_CORRECTIONS,
            );
        }

        if ($assembled === []) {
            return new TargetResult(
                status: TargetResultStatus::Failed,
                ref: $target->ref(),
                fields: $this->ordered($target, $plan->skipped + $failed),
                warnings: $warnings,
                reason: TargetResult::FIELDS_FAILED,
                error: __(self::FIELDS_FAILED_MESSAGE),
            );
        }

        $applied = $target->apply(array_values($assembled), $options);
        $fields = $failed;

        foreach ($applied->applied as $handle) {
            $field = $assembled[$handle];
            $fields[$handle] = $field->incomplete ? TargetResult::INCOMPLETE : TargetResult::APPLIED;
            array_push($warnings, ...$field->warnings);

            if ($field->corrections !== []) {
                $corrections[$handle] = $field->corrections;
            }
        }

        [$status, $reason] = match (true) {
            $applied->applied !== [] => [TargetResultStatus::Done, null],
            $failed !== [] => [TargetResultStatus::Failed, TargetResult::FIELDS_FAILED],
            default => [TargetResultStatus::Skipped, TargetResult::TARGET_CHANGED],
        };

        return new TargetResult(
            status: $status,
            ref: $applied->ref,
            created: $applied->created,
            fields: $this->ordered($target, $plan->skipped + $applied->skipped + $unchanged + $fields),
            corrections: $corrections,
            warnings: [...$warnings, ...$applied->warnings],
            reason: $reason,
            error: $status === TargetResultStatus::Failed ? __(self::FIELDS_FAILED_MESSAGE) : null,
        );
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, string> the fields in blueprint order
     */
    private function ordered(Target $target, array $fields): array
    {
        return array_replace(array_intersect_key(array_flip($target->fields()->keys()->all()), $fields), $fields);
    }
}
