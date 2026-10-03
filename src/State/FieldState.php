<?php

namespace Reach\StatamicTolk\State;

use Carbon\CarbonImmutable;
use Reach\StatamicTolk\State\Concerns\ReadsStoredValues;

final readonly class FieldState
{
    use ReadsStoredValues;

    public const int MAX_HISTORY = 3;

    public const int MAX_PENDING = 3;

    /**
     * @param  list<?string>  $segments
     * @param  list<array{source_hash: ?string, target_hash: string}>  $history  newest first
     * @param  list<PendingState>  $pending  newest first
     */
    public function __construct(
        public ?string $sourceHash = null,
        public ?string $targetHash = null,
        public array $segments = [],
        public bool $identical = false,
        public bool $incomplete = false,
        public ?CarbonImmutable $translatedAt = null,
        public ?string $translator = null,
        public ?string $reviewer = null,
        public array $history = [],
        public array $pending = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            sourceHash: self::storedString($data, 'source_hash'),
            targetHash: self::storedString($data, 'target_hash'),
            segments: self::storedSegments($data),
            identical: self::storedBool($data, 'identical'),
            incomplete: self::storedBool($data, 'incomplete'),
            translatedAt: self::storedTime($data, 'translated_at'),
            translator: self::storedString($data, 'translator'),
            reviewer: self::storedString($data, 'reviewer'),
            history: self::storedHistory($data),
            pending: self::storedPending($data),
        );
    }

    public function isMachine(): bool
    {
        return $this->targetHash !== null;
    }

    public function isBaseline(): bool
    {
        return $this->sourceHash !== null && $this->targetHash === null;
    }

    /**
     * A record without hashes, history or pending entries says nothing about the field and is not stored.
     */
    public function isEmpty(): bool
    {
        return $this->sourceHash === null && $this->targetHash === null && $this->history === [] && $this->pending === [];
    }

    public function withPending(PendingState $pending): self
    {
        return new self(
            sourceHash: $this->sourceHash,
            targetHash: $this->targetHash,
            segments: $this->segments,
            identical: $this->identical,
            incomplete: $this->incomplete,
            translatedAt: $this->translatedAt,
            translator: $this->translator,
            reviewer: $this->reviewer,
            history: $this->history,
            pending: array_slice([$pending, ...$this->pending], 0, self::MAX_PENDING),
        );
    }

    public function promote(PendingState $pending): self
    {
        return new self(
            sourceHash: $pending->sourceHash,
            targetHash: $pending->targetHash,
            segments: $pending->segments,
            identical: $pending->identical,
            incomplete: $pending->incomplete,
            translatedAt: $pending->createdAt,
            translator: $pending->translator,
            reviewer: $pending->reviewer,
            history: $this->historyReplacedBy($pending->targetHash),
        );
    }

    public function toBaseline(PendingState $pending): self
    {
        return new self(
            sourceHash: $pending->sourceHash,
            translator: $pending->translator,
            history: $this->historyReplacedBy(null),
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'source_hash' => $this->sourceHash,
            'target_hash' => $this->targetHash,
            'segments' => $this->segments,
            'identical' => $this->identical,
            'incomplete' => $this->incomplete,
            'translated_at' => self::timeToString($this->translatedAt),
            'translator' => $this->translator,
            'reviewer' => $this->reviewer,
            'history' => $this->history,
            'pending' => array_map(fn (PendingState $pending) => $pending->toArray(), $this->pending),
        ], fn (mixed $value) => $value !== null && $value !== false && $value !== []);
    }

    /**
     * The history once a new output replaces this record: its machine pair first, no output twice, none equal to the new one.
     */
    private function historyReplacedBy(?string $targetHash): array
    {
        $pairs = $this->isMachine()
            ? [['source_hash' => $this->sourceHash, 'target_hash' => $this->targetHash], ...$this->history]
            : $this->history;

        $history = [];

        foreach ($pairs as $pair) {
            if ($pair['target_hash'] !== $targetHash) {
                $history[$pair['target_hash']] ??= $pair;
            }
        }

        return array_slice(array_values($history), 0, self::MAX_HISTORY);
    }

    private static function storedHistory(array $data): array
    {
        $history = [];

        foreach (is_array($data['history'] ?? null) ? $data['history'] : [] as $pair) {
            if (is_array($pair) && ($targetHash = self::storedString($pair, 'target_hash')) !== null) {
                $history[] = ['source_hash' => self::storedString($pair, 'source_hash'), 'target_hash' => $targetHash];
            }
        }

        return array_slice($history, 0, self::MAX_HISTORY);
    }

    private static function storedPending(array $data): array
    {
        $pending = [];

        foreach (is_array($data['pending'] ?? null) ? $data['pending'] : [] as $entry) {
            if (is_array($entry) && ($state = PendingState::fromArray($entry)) !== null) {
                $pending[] = $state;
            }
        }

        return array_slice($pending, 0, self::MAX_PENDING);
    }
}
