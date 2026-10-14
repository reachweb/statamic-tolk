<?php

namespace Reach\StatamicTolk\Translation;

use Carbon\CarbonImmutable;
use Reach\StatamicTolk\State\PendingState;

/**
 * One field of a plan, ready to be delivered or applied (§A8 step 4).
 */
final readonly class AssembledField
{
    /**
     * @param  string  $plan  the plan's id, for the pending state entry
     * @param  mixed  $value  the raw target value; null when the override is removed
     * @param  bool  $remove  the source lost its value, so the target's override is removed
     * @param  ?string  $targetHash  the text fingerprint of the value (§A4); null when the override is removed
     * @param  ?string  $targetBefore  the target's text fingerprint when the plan was made
     * @param  list<?string>  $segments  the fingerprint of each source leaf, null for a leaf left untranslated; empty when
     *                                   the produced value's leaves do not line up with them
     * @param  ?string  $translator  the model that translated most of the field's segments; null when none was called
     * @param  list<array{id: string, before: string, after: string, reason: string}>  $corrections
     * @param  list<string>  $warnings
     * @param  ?string  $reviewer  the model that reviewed the field's translations; null when none did
     * @param  bool  $baseline  a reviewed manual field, whose pending entry a save confirms as a baseline (T7.4)
     * @param  bool  $unchanged  a reviewed field without corrections, which is neither delivered nor saved (T7.4)
     * @param  bool  $fromTarget  a reviewed field, whose value is the target's text when the plan was made with the
     *                            corrections: a target edited since is kept, overwrite_manual or not (§A11 step 2)
     */
    public function __construct(
        public string $handle,
        public string $plan,
        public mixed $value,
        public bool $remove,
        public string $sourceHash,
        public ?string $targetHash,
        public ?string $targetBefore,
        public array $segments,
        public bool $identical,
        public bool $incomplete,
        public ?string $translator,
        public array $corrections = [],
        public array $warnings = [],
        public ?string $reviewer = null,
        public bool $baseline = false,
        public bool $unchanged = false,
        public bool $fromTarget = false,
    ) {}

    /**
     * @param  string  $targetHash  the text fingerprint of the value as it will be stored
     */
    public function pending(string $targetHash): PendingState
    {
        return new PendingState(
            plan: $this->plan,
            sourceHash: $this->sourceHash,
            targetHash: $targetHash,
            targetBefore: $this->targetBefore,
            segments: $this->segments,
            identical: $this->identical,
            incomplete: $this->incomplete,
            translator: $this->translator,
            reviewer: $this->reviewer,
            createdAt: CarbonImmutable::now(),
            baseline: $this->baseline,
        );
    }
}
