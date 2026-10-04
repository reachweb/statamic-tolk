<?php

namespace Reach\StatamicTolk\State;

use Carbon\CarbonImmutable;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\Enums\ItemStatus;

final readonly class FieldStatusInfo
{
    /**
     * @param  ?bool  $sourceChanged  whether the source changed since the state's source fingerprint; null when none is known
     * @param  bool  $incomplete  whether the target holds machine output with a part left untranslated (D28)
     * @param  FieldState  $state  the state after the in-memory reconcile
     * @param  string  $sourceHash  the source's current fingerprint
     * @param  ?string  $targetHash  the target's current text fingerprint; null when the target has no override
     * @param  ?string  $sourceTextHash  the source's text fingerprint walked as a target (§A3), which a copy of the source
     *                                   has; computed only when it can decide the status or a reconcile, else null
     */
    public function __construct(
        public FieldStatus $status,
        public ?bool $sourceChanged,
        public bool $incomplete,
        public ?CarbonImmutable $translatedAt,
        public ?string $translator,
        public ?string $reviewer,
        public string $label,
        public FieldState $state,
        public string $sourceHash,
        public ?string $targetHash,
        public ?string $sourceTextHash = null,
    ) {}

    public function needsAttention(): bool
    {
        return $this->status === FieldStatus::Manual && $this->sourceChanged === true;
    }

    public function isUntracked(): bool
    {
        return $this->status === FieldStatus::Manual && $this->sourceChanged === null;
    }

    public function itemStatus(): ?ItemStatus
    {
        return match (true) {
            $this->status === FieldStatus::NotApplicable => null,
            $this->status === FieldStatus::Untranslated => ItemStatus::Untranslated,
            $this->status === FieldStatus::Outdated => ItemStatus::Outdated,
            $this->needsAttention() => ItemStatus::Attention,
            default => ItemStatus::Current,
        };
    }
}
