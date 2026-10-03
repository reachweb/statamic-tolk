<?php

use Reach\StatamicTolk\Enums\UnitKind;
use Reach\StatamicTolk\Translation\Unit;

it('names spawned units deterministically', function () {
    $unit = new Unit('b3', UnitKind::Translate, ['s6', 's7', 's8']);
    $repair = $unit->repairOf('s7', 'A note.', false);

    expect(array_column(array_map(fn (Unit $half) => $half->toArray(), $unit->halves(false)), 'id'))->toBe(['b3.a', 'b3.b'])
        ->and($repair->id)->toBe('b3~s7')
        ->and($repair->repairOf('s7', 'Another note.', false)->id)->toBe('b3~s7~2')
        ->and($unit->forMissing(['s8'], false)->id)->toBe('b3?')
        ->and($unit->withDoubledCap(false)->id)->toBe('b3^');
});

it('splits into halves one level deeper, the first half taking the odd segment', function () {
    $unit = new Unit('b1', UnitKind::Translate, ['s1', 's2', 's3'], notes: ['s1' => 'One.', 's3' => 'Three.'], missingRetry: true);

    [$first, $second] = $unit->halves(true);

    expect($first->segmentIds)->toBe(['s1', 's2'])
        ->and($second->segmentIds)->toBe(['s3'])
        ->and([$first->depth, $second->depth])->toBe([1, 1])
        ->and($first->notes)->toBe(['s1' => 'One.'])
        ->and($second->notes)->toBe(['s3' => 'Three.'])
        ->and([$first->useFallback, $first->missingRetry])->toBe([true, true]);
});

it('stops splitting at depth three and never splits one segment', function () {
    expect((new Unit('b1.a.b', UnitKind::Translate, ['s1', 's2'], depth: 2))->canSplit())->toBeTrue()
        ->and((new Unit('b1.a.b.a', UnitKind::Translate, ['s1', 's2'], depth: 3))->canSplit())->toBeFalse()
        ->and((new Unit('b1', UnitKind::Translate, ['s1']))->canSplit())->toBeFalse();
});

it('gives a repair one segment, its note and the next repair count', function () {
    $repair = (new Unit('b1?', UnitKind::Translate, ['s1', 's2'], capMultiplier: 2, missingRetry: true))->repairOf('s2', 'Fix it.', true);

    expect($repair->segmentIds)->toBe(['s2'])
        ->and($repair->notes)->toBe(['s2' => 'Fix it.'])
        ->and($repair->repair)->toBe(1)
        ->and($repair->capMultiplier)->toBe(2)
        ->and($repair->useFallback)->toBeTrue()
        ->and($repair->missingRetry)->toBeFalse();
});

it('doubles the cap and marks a request for missing ids', function () {
    $unit = new Unit('b1', UnitKind::Translate, ['s1', 's2']);

    expect($unit->withDoubledCap(false)->capMultiplier)->toBe(2)
        ->and($unit->forMissing(['s2'], false))->toEqual(new Unit('b1?', UnitKind::Translate, ['s2'], missingRetry: true));
});

it('survives toArray and fromArray', function () {
    $unit = new Unit('b1~s2~2', UnitKind::Translate, ['s2'], depth: 2, repair: 2, capMultiplier: 2, notes: ['s2' => 'A note.'], useFallback: true, missingRetry: true);

    expect(Unit::fromArray($unit->toArray()))->toEqual($unit)
        ->and($unit->toArray())->toBe([
            'id' => 'b1~s2~2',
            'kind' => 'translate',
            'segment_ids' => ['s2'],
            'depth' => 2,
            'repair' => 2,
            'cap_multiplier' => 2,
            'notes' => ['s2' => 'A note.'],
            'use_fallback' => true,
            'missing_retry' => true,
        ]);
});
