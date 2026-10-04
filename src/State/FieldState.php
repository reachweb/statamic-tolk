<?php

namespace Reach\StatamicTolk\State;

use Carbon\CarbonImmutable;
use Reach\StatamicTolk\State\Concerns\ReadsStoredValues;

final readonly class FieldState
{
    use ReadsStoredValues;

    public const int MAX_HISTORY = 3;

    public const int MAX_PENDING = 3;

    public const int PENDING_DAYS = 30;

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

    public function inHistory(string $targetHash): bool
    {
        return in_array($targetHash, array_column($this->history, 'target_hash'), true);
    }

    /**
     * An entry of the same plan is replaced: parallel editor requests can deliver the same field of a plan twice.
     */
    public function withPending(PendingState $pending): self
    {
        return $this->withPendingEntries([$pending, ...array_filter($this->pending, fn (PendingState $entry) => $entry->plan !== $pending->plan)]);
    }

    /**
     * Resolves the pending entries against the target's text fingerprint, null when the target has no override (§A5 "Reconcile").
     * `$sourceTextHash` is the source's text fingerprint walked as a target: a target holding that text was not edited.
     */
    public function reconcile(?string $targetHash, CarbonImmutable $now, ?string $sourceTextHash = null): self
    {
        if ($this->pending === []) {
            return $this;
        }

        if (($saved = $this->savedPending($targetHash)) !== null) {
            return $this->promote($saved);
        }

        if (($edited = $this->editedPending($targetHash, $sourceTextHash)) !== null) {
            return $this->toBaseline($edited);
        }

        $cutoff = $now->subDays(self::PENDING_DAYS);

        return $this->withPendingEntries(array_filter($this->pending, fn (PendingState $pending) => $pending->createdAt->greaterThan($cutoff)));
    }

    /**
     * Reconcile rule 1: the entry whose output the target holds, or, without an override, an output identical to the source.
     */
    public function savedPending(?string $targetHash): ?PendingState
    {
        foreach ($this->pending as $pending) {
            if ($targetHash === null ? $pending->identical : $pending->targetHash === $targetHash) {
                return $pending;
            }
        }

        return null;
    }

    /**
     * Reconcile rule 2: the newest entry whose output was edited before it was saved. The target holds text that changed
     * since the entry's plan and is no machine output, nor a copy of the source's text, which the CP saves without an
     * edit when a Bard field desyncs itself (T6.11).
     */
    public function editedPending(?string $targetHash, ?string $sourceTextHash = null): ?PendingState
    {
        if ($targetHash === null || $targetHash === $this->targetHash || $targetHash === $sourceTextHash || $this->inHistory($targetHash)) {
            return null;
        }

        foreach ($this->pending as $pending) {
            if ($pending->targetBefore !== $targetHash) {
                return $pending;
            }
        }

        return null;
    }

    /**
     * A reviewed manual field stays manual: its entry becomes a baseline that records the reviewer (T7.4).
     */
    public function promote(PendingState $pending): self
    {
        if ($pending->baseline) {
            return new self(
                sourceHash: $pending->sourceHash,
                translator: $pending->translator,
                reviewer: $pending->reviewer,
                history: $this->historyReplacedBy($pending->targetHash),
            );
        }

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

    /**
     * A baseline against the given source for a target whose text has the given fingerprint. That text reads manual from
     * now on, machine output included (D27), so it leaves the history. Unsaved deliveries stay pending.
     */
    public function baselined(string $sourceHash, string $targetHash): self
    {
        return new self(
            sourceHash: $sourceHash,
            translator: $this->translator,
            history: $this->historyReplacedBy($targetHash),
            pending: $this->pending,
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

    private function withPendingEntries(array $pending): self
    {
        return new self(...[...get_object_vars($this), 'pending' => array_slice(array_values($pending), 0, self::MAX_PENDING)]);
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

        foreach (self::storedArray($data, 'history') as $pair) {
            if (is_array($pair) && ($targetHash = self::storedString($pair, 'target_hash')) !== null) {
                $history[] = ['source_hash' => self::storedString($pair, 'source_hash'), 'target_hash' => $targetHash];
            }
        }

        return array_slice($history, 0, self::MAX_HISTORY);
    }

    private static function storedPending(array $data): array
    {
        $pending = [];

        foreach (self::storedArray($data, 'pending') as $entry) {
            if (is_array($entry) && ($state = PendingState::fromArray($entry)) !== null) {
                $pending[] = $state;
            }
        }

        return array_slice($pending, 0, self::MAX_PENDING);
    }
}
