<?php

namespace Reach\StatamicTolk\Translation;

final class BatchPlanner
{
    /**
     * Groups segments greedily in document order. A segment longer than the budget gets a batch of its own, and a
     * segment that already has a translation is never batched.
     *
     * @param  array<string, array{text: string, translation?: ?string}>  $segments  by id, in document order
     * @return list<array{id: string, segment_ids: list<string>, chars: int}>
     */
    public function plan(array $segments, int $maxChars, int $maxSegments): array
    {
        $maxChars = max(1, $maxChars);
        $maxSegments = max(1, $maxSegments);
        $batches = [];
        $current = ['segment_ids' => [], 'chars' => 0];

        foreach ($segments as $id => $segment) {
            if (($segment['translation'] ?? null) !== null) {
                continue;
            }

            $chars = mb_strlen($segment['text']);

            if ($current['segment_ids'] !== [] && ($maxChars < $current['chars'] + $chars || count($current['segment_ids']) >= $maxSegments)) {
                $batches[] = $current;
                $current = ['segment_ids' => [], 'chars' => 0];
            }

            $current['segment_ids'][] = (string) $id;
            $current['chars'] += $chars;
        }

        if ($current['segment_ids'] !== []) {
            $batches[] = $current;
        }

        return array_map(
            fn (array $batch, int $index) => ['id' => 'b'.($index + 1), ...$batch],
            $batches,
            array_keys($batches),
        );
    }
}
