<?php

namespace Reach\StatamicTolk\Translation;

use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\TargetResultStatus;
use Reach\StatamicTolk\Exceptions\FieldFailed;
use Reach\StatamicTolk\Exceptions\SourceChanged;
use Reach\StatamicTolk\Exceptions\WriterBusy;
use Reach\StatamicTolk\Support\ContentWriter;
use Reach\StatamicTolk\Targets\ApplyOptions;

/**
 * Saves a finished plan into its target (§A8 steps 4 and 5, §A11), for TargetTranslator and runs alike.
 */
final readonly class PlanApplier
{
    public const string FIELDS_FAILED_MESSAGE = 'No field could be translated completely.';

    public const string REVIEW_SKIPPED_MESSAGE = 'Part of the text could not be reviewed and was kept as it was.';

    public function __construct(private Pipeline $pipeline) {}

    /**
     * Assembles every field from the given target and saves the fields that assembled through ContentWriter::write(),
     * whose Target::apply() checks their sources again under the write lock. Pass a target resolved after the units ran,
     * so a source edited meanwhile raises SourceChanged before this call takes the lock (a run already holds it); a
     * source that another process saved, which this process may still hold as it was (Target::fresh()), raises it under
     * the lock. Only a save takes the lock: a review without corrections and a strict plan whose every field failed
     * return without it. A review saves only the fields it corrected; a field that failed in strict mode is listed as
     * failed and the others are still saved. A reviewed field with a part the reviewer could not review is listed as
     * `review_skipped`, or `partly_reviewed` when its corrections were saved, and when nothing was corrected the target
     * fails with `review_skipped`, so it does not read as reviewed.
     *
     * @throws SourceChanged
     * @throws WriterBusy when there is something to save and the write lock was not free in time
     */
    public function apply(Plan $plan, Target $target, ApplyOptions $options): TargetResult
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
                $unchanged[$handle] = $field->reviewSkipped ? TargetResult::REVIEW_SKIPPED : TargetResult::NO_CORRECTIONS;
                array_push($warnings, ...$field->warnings);
            }
        }

        $assembled = array_diff_key($assembled, $unchanged);

        if ($assembled === [] && $unchanged !== []) {
            $skipped = in_array(TargetResult::REVIEW_SKIPPED, $unchanged, true);

            return new TargetResult(
                status: $skipped ? TargetResultStatus::Failed : TargetResultStatus::Skipped,
                ref: $target->ref(),
                fields: $this->ordered($target, $plan->skipped + $unchanged),
                warnings: $warnings,
                reason: $skipped ? TargetResult::REVIEW_SKIPPED : TargetResult::NO_CORRECTIONS,
                error: $skipped ? __(self::REVIEW_SKIPPED_MESSAGE) : null,
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

        $applied = ContentWriter::write(fn () => $target->apply(array_values($assembled), $options));
        $fields = $failed;

        foreach ($applied->applied as $handle) {
            $field = $assembled[$handle];
            $fields[$handle] = match (true) {
                $field->incomplete => TargetResult::INCOMPLETE,
                $field->reviewSkipped => TargetResult::PARTLY_REVIEWED,
                default => TargetResult::APPLIED,
            };
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
