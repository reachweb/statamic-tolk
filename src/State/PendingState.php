<?php

namespace Reach\StatamicTolk\State;

use Carbon\CarbonImmutable;
use Reach\StatamicTolk\State\Concerns\ReadsStoredValues;

final readonly class PendingState
{
    use ReadsStoredValues;

    /**
     * @param  list<?string>  $segments
     * @param  bool  $baseline  the output of a review of a manual field, which a save confirms as a baseline (T7.4)
     */
    public function __construct(
        public string $plan,
        public string $sourceHash,
        public string $targetHash,
        public ?string $targetBefore,
        public array $segments,
        public bool $identical,
        public bool $incomplete,
        public ?string $translator,
        public ?string $reviewer,
        public CarbonImmutable $createdAt,
        public bool $baseline = false,
    ) {}

    /**
     * Returns null for an entry that lacks its plan, either hash or its creation time.
     */
    public static function fromArray(array $data): ?self
    {
        $plan = self::storedString($data, 'plan');
        $sourceHash = self::storedString($data, 'source_hash');
        $targetHash = self::storedString($data, 'target_hash');
        $createdAt = self::storedTime($data, 'created_at');

        if ($plan === null || $sourceHash === null || $targetHash === null || $createdAt === null) {
            return null;
        }

        return new self(
            plan: $plan,
            sourceHash: $sourceHash,
            targetHash: $targetHash,
            targetBefore: self::storedString($data, 'target_before'),
            segments: self::storedSegments($data),
            identical: self::storedBool($data, 'identical'),
            incomplete: self::storedBool($data, 'incomplete'),
            translator: self::storedString($data, 'translator'),
            reviewer: self::storedString($data, 'reviewer'),
            createdAt: $createdAt,
            baseline: self::storedBool($data, 'baseline'),
        );
    }

    public function toArray(): array
    {
        return [
            'plan' => $this->plan,
            'source_hash' => $this->sourceHash,
            'target_hash' => $this->targetHash,
            'target_before' => $this->targetBefore,
            'segments' => $this->segments,
            'identical' => $this->identical,
            'incomplete' => $this->incomplete,
            'translator' => $this->translator,
            'reviewer' => $this->reviewer,
            'created_at' => self::timeToString($this->createdAt),
            ...($this->baseline ? ['baseline' => true] : []),
        ];
    }
}
