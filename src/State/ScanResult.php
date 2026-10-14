<?php

namespace Reach\StatamicTolk\State;

use Reach\StatamicTolk\Enums\ItemStatus;

/**
 * What StatusScanner::scan() found in the targets of one container and site, or in part of them (§A6).
 */
final readonly class ScanResult
{
    /**
     * @param  array<string, int>  $counts  targets per item status, keyed by every ItemStatus value in its order
     * @param  int  $untracked  manual fields without a known source fingerprint, which `tolk:baseline` records
     * @param  int  $unreadable  targets whose stored state cannot be read, which no status counts
     * @param  int  $scanned  the targets scanned, the unreadable included
     * @param  ?string  $cursor  where the next scan continues; null once every target is scanned
     */
    public function __construct(
        public array $counts,
        public int $untracked = 0,
        public int $unreadable = 0,
        public int $scanned = 0,
        public ?string $cursor = null,
    ) {}

    public static function empty(): self
    {
        return new self(array_fill_keys(array_map(fn (ItemStatus $status) => $status->value, ItemStatus::cases()), 0));
    }

    public function isFinished(): bool
    {
        return $this->cursor === null;
    }

    public function withTarget(TargetStatus $status): self
    {
        $counts = $this->counts;
        $counts[$status->itemStatus->value]++;

        return new self($counts, $this->untracked + $status->counts['untracked'], $this->unreadable, $this->scanned + 1, $this->cursor);
    }

    /**
     * A target whose stored state cannot be read, so its status is unknown.
     */
    public function withUnreadable(): self
    {
        return new self($this->counts, $this->untracked, $this->unreadable + 1, $this->scanned + 1, $this->cursor);
    }

    public function withCursor(?string $cursor): self
    {
        return new self($this->counts, $this->untracked, $this->unreadable, $this->scanned, $cursor);
    }

    /**
     * The totals of this scan and the one that continued it, with the later one's cursor.
     */
    public function add(self $next): self
    {
        $counts = $this->counts;

        foreach ($next->counts as $status => $count) {
            $counts[$status] = ($counts[$status] ?? 0) + $count;
        }

        return new self($counts, $this->untracked + $next->untracked, $this->unreadable + $next->unreadable, $this->scanned + $next->scanned, $next->cursor);
    }

    /**
     * @return array{counts: array<string, int>, untracked: int, unreadable: int, scanned: int, cursor: ?string}
     */
    public function toArray(): array
    {
        return [
            'counts' => $this->counts,
            'untracked' => $this->untracked,
            'unreadable' => $this->unreadable,
            'scanned' => $this->scanned,
            'cursor' => $this->cursor,
        ];
    }

    public static function fromArray(array $data): self
    {
        $count = fn (mixed $value) => is_numeric($value) ? max(0, (int) $value) : 0;
        $counts = self::empty()->counts;

        foreach ($counts as $status => $zero) {
            $counts[$status] = $count($data['counts'][$status] ?? 0);
        }

        return new self(
            $counts,
            $count($data['untracked'] ?? 0),
            $count($data['unreadable'] ?? 0),
            $count($data['scanned'] ?? 0),
            is_string($data['cursor'] ?? null) ? $data['cursor'] : null,
        );
    }
}
