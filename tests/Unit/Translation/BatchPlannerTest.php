<?php

use Reach\StatamicTolk\Translation\BatchPlanner;

/**
 * @param  array<string, string|array{0: string, 1: string}>  $texts  id => text, or id => [text, translation] for a reused segment
 */
function batchSegments(array $texts): array
{
    return array_map(fn (string|array $text) => is_array($text)
        ? ['text' => $text[0], 'translation' => $text[1]]
        : ['text' => $text, 'translation' => null], $texts);
}

function planBatches(array $texts, int $maxChars = 10, int $maxSegments = 80): array
{
    return (new BatchPlanner)->plan(batchSegments($texts), $maxChars, $maxSegments);
}

it('fills each batch up to the character budget', function () {
    expect(planBatches(['s1' => 'abcd', 's2' => 'abc', 's3' => 'abcde', 's4' => 'ab', 's5' => 'abcdefghij']))->toBe([
        ['id' => 'b1', 'segment_ids' => ['s1', 's2'], 'chars' => 7],
        ['id' => 'b2', 'segment_ids' => ['s3', 's4'], 'chars' => 7],
        ['id' => 'b3', 'segment_ids' => ['s5'], 'chars' => 10],
    ]);
});

it('never exceeds the budget with segments that fit it', function () {
    $texts = [];

    foreach (range(1, 40) as $index) {
        $texts["s{$index}"] = str_repeat('x', ($index * 7) % 10 + 1);
    }

    $batches = planBatches($texts);

    expect(collect($batches)->every(fn (array $batch) => $batch['chars'] <= 10))->toBeTrue()
        ->and(array_merge(...array_column($batches, 'segment_ids')))->toBe(array_keys($texts));
});

it('keeps document order across batches', function () {
    $batches = planBatches(['s1' => 'abcdefgh', 's2' => 'abcdefgh', 's3' => 'ab', 's4' => 'abcdefgh', 's5' => 'a']);

    expect(array_column($batches, 'segment_ids'))->toBe([['s1'], ['s2', 's3'], ['s4', 's5']])
        ->and(array_column($batches, 'id'))->toBe(['b1', 'b2', 'b3']);
});

it('gives a segment longer than the budget a batch of its own', function () {
    $batches = planBatches(['s1' => 'abc', 's2' => str_repeat('x', 25), 's3' => 'abc', 's4' => str_repeat('y', 11)]);

    expect($batches)->toBe([
        ['id' => 'b1', 'segment_ids' => ['s1'], 'chars' => 3],
        ['id' => 'b2', 'segment_ids' => ['s2'], 'chars' => 25],
        ['id' => 'b3', 'segment_ids' => ['s3'], 'chars' => 3],
        ['id' => 'b4', 'segment_ids' => ['s4'], 'chars' => 11],
    ]);
});

it('caps the number of segments per batch', function () {
    $batches = planBatches(['s1' => 'a', 's2' => 'b', 's3' => 'c', 's4' => 'd', 's5' => 'e'], maxSegments: 2);

    expect(array_column($batches, 'segment_ids'))->toBe([['s1', 's2'], ['s3', 's4'], ['s5']]);
});

it('never batches a segment that already has a translation', function () {
    $batches = planBatches(['s1' => 'abcd', 's2' => ['reused text', 'κείμενο'], 's3' => 'abcd', 's4' => ['x', 'χ']]);

    expect($batches)->toBe([['id' => 'b1', 'segment_ids' => ['s1', 's3'], 'chars' => 8]]);
});

it('gives no batches when nothing is left to translate', function () {
    expect(planBatches([]))->toBe([])
        ->and(planBatches(['s1' => ['Hello', 'Γεια'], 's2' => ['World', 'Κόσμος']]))->toBe([]);
});

it('counts characters, not bytes', function () {
    expect(planBatches(['s1' => 'Γεια σας', 's2' => 'Καλά'], maxChars: 12))
        ->toBe([['id' => 'b1', 'segment_ids' => ['s1', 's2'], 'chars' => 12]]);
});

it('treats a budget below one as one', function () {
    expect(array_column(planBatches(['s1' => 'a', 's2' => 'b'], maxChars: 0, maxSegments: 0), 'segment_ids'))
        ->toBe([['s1'], ['s2']]);
});
