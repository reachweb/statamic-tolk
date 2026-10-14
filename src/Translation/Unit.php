<?php

namespace Reach\StatamicTolk\Translation;

use Reach\StatamicTolk\Enums\UnitKind;

/**
 * One provider call of a plan (§A8): an initial batch, or a unit spawned by an earlier outcome (§A10). Spawned ids are
 * deterministic, so parallel requests never write the same key.
 */
final readonly class Unit
{
    public const int MAX_SPLIT_DEPTH = 3;

    public const int MAX_REPAIRS = 2;

    /**
     * @param  list<string>  $segmentIds
     * @param  int  $depth  how many times a call split the segments in halves; a split to fit a smaller batch size does not count
     * @param  int  $repair  how many repairs of its one segment came before this one
     * @param  array<string, string>  $notes  segment id → the problem of an earlier translation, sent with a repair
     * @param  bool  $useFallback  the unit it came from was answered by the fallback model, or was split for its size when
     *                             it would have gone to the fallback
     * @param  bool  $missingRetry  it asks again for ids an earlier answer left out, so ids missing again fail
     */
    public function __construct(
        public string $id,
        public UnitKind $kind,
        public array $segmentIds,
        public int $depth = 0,
        public int $repair = 0,
        public int $capMultiplier = 1,
        public array $notes = [],
        public bool $useFallback = false,
        public bool $missingRetry = false,
    ) {}

    /**
     * @param  array{id: string, segment_ids: list<string>}  $batch
     */
    public static function batch(array $batch, UnitKind $kind): self
    {
        return new self($batch['id'], $kind, array_values($batch['segment_ids']));
    }

    public function canSplit(): bool
    {
        return count($this->segmentIds) > 1 && $this->depth < self::MAX_SPLIT_DEPTH;
    }

    /**
     * Whether a refusal splits the unit again: at any depth, so the refused segments end up alone (§A10). That takes
     * about two calls per halving, and up to twice as many calls as segments when every segment is refused.
     */
    public function canIsolate(): bool
    {
        return count($this->segmentIds) > 1;
    }

    /**
     * @param  bool  $deeper  false for a split that only fits the unit to a smaller batch size (Pipeline::fits()), which
     *                        leaves the halves the splits a timeout or an answer may still need
     * @return list<self> `{id}.a` and `{id}.b`
     */
    public function halves(bool $useFallback, bool $deeper = true): array
    {
        $size = (int) ceil(count($this->segmentIds) / 2);
        $depth = ['depth' => $this->depth + (int) $deeper];

        return [
            $this->derive('.a', array_slice($this->segmentIds, 0, $size), $useFallback, $depth),
            $this->derive('.b', array_slice($this->segmentIds, $size), $useFallback, $depth),
        ];
    }

    /**
     * `{id}~{segment}` for the first repair of a segment, `{id}~2` for the second.
     */
    public function repairOf(string $segmentId, string $note, bool $useFallback): self
    {
        return $this->derive($this->repair === 0 ? "~{$segmentId}" : '~'.($this->repair + 1), [$segmentId], $useFallback, [
            'repair' => $this->repair + 1,
            'notes' => [$segmentId => $note],
            'missingRetry' => false,
        ]);
    }

    /**
     * `{id}^`: the same segments with the output cap doubled.
     */
    public function withDoubledCap(bool $useFallback): self
    {
        return $this->derive('^', $this->segmentIds, $useFallback, ['capMultiplier' => $this->capMultiplier * 2]);
    }

    /**
     * `{id}?`: the segments an answer left out.
     *
     * @param  list<string>  $segmentIds
     */
    public function forMissing(array $segmentIds, bool $useFallback): self
    {
        return $this->derive('?', $segmentIds, $useFallback, ['missingRetry' => true]);
    }

    /**
     * `{id}.v`: the review of the translations this unit's answer gave for the segments (§A10).
     *
     * @param  list<string>  $segmentIds
     */
    public function forReview(array $segmentIds): self
    {
        return new self($this->id.'.v', UnitKind::Review, $segmentIds);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'segment_ids' => $this->segmentIds,
            'depth' => $this->depth,
            'repair' => $this->repair,
            'cap_multiplier' => $this->capMultiplier,
            'notes' => $this->notes,
            'use_fallback' => $this->useFallback,
            'missing_retry' => $this->missingRetry,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            kind: UnitKind::from($data['kind']),
            segmentIds: array_values(array_map('strval', $data['segment_ids'] ?? [])),
            depth: (int) ($data['depth'] ?? 0),
            repair: (int) ($data['repair'] ?? 0),
            capMultiplier: max(1, (int) ($data['cap_multiplier'] ?? 1)),
            notes: $data['notes'] ?? [],
            useFallback: (bool) ($data['use_fallback'] ?? false),
            missingRetry: (bool) ($data['missing_retry'] ?? false),
        );
    }

    /**
     * @param  list<string>  $segmentIds
     */
    private function derive(string $suffix, array $segmentIds, bool $useFallback, array $changes = []): self
    {
        return new self(...[
            'id' => $this->id.$suffix,
            'kind' => $this->kind,
            'segmentIds' => $segmentIds,
            'depth' => $this->depth,
            'repair' => $this->repair,
            'capMultiplier' => $this->capMultiplier,
            'notes' => array_intersect_key($this->notes, array_flip($segmentIds)),
            'useFallback' => $useFallback,
            'missingRetry' => $this->missingRetry,
            ...$changes,
        ]);
    }
}
