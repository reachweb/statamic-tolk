<?php

use Carbon\CarbonImmutable;
use Reach\StatamicTolk\State\FieldState;
use Reach\StatamicTolk\State\ItemState;
use Reach\StatamicTolk\State\PendingState;
use Reach\StatamicTolk\Targets\TargetRef;

function pendingState(string $targetHash, array $overrides = []): PendingState
{
    return new PendingState(...[
        'plan' => 'plan-'.$targetHash,
        'sourceHash' => 'source-'.$targetHash,
        'targetHash' => $targetHash,
        'targetBefore' => null,
        'segments' => ['a1b2c3d4e5f6'],
        'identical' => false,
        'incomplete' => false,
        'translator' => 'anthropic:claude-sonnet-5-5',
        'reviewer' => null,
        'createdAt' => CarbonImmutable::parse('2026-10-01T12:00:00Z'),
        ...$overrides,
    ]);
}

function machineState(string $sourceHash, string $targetHash, array $history = []): FieldState
{
    return new FieldState(sourceHash: $sourceHash, targetHash: $targetHash, history: $history);
}

it('round-trips a field record through the snake_case keys of the state file', function () {
    $field = new FieldState(
        sourceHash: '9f2c1a7e5b3d4c10',
        targetHash: '1b7d02c4a9e6f358',
        segments: ['a1b2c3d4e5f6', null],
        identical: true,
        incomplete: true,
        translatedAt: CarbonImmutable::parse('2026-10-01T12:00:00Z'),
        translator: 'anthropic:claude-sonnet-5-5',
        reviewer: 'openai:gpt-6.1-sol',
        history: [['source_hash' => '77aa000000000000', 'target_hash' => '31c9000000000000']],
        pending: [pendingState('2222222222222222', ['targetBefore' => '1b7d02c4a9e6f358', 'reviewer' => 'gemini:gemini-3.8-flash'])],
    );

    $array = $field->toArray();

    expect(array_keys($array))->toBe([
        'source_hash', 'target_hash', 'segments', 'identical', 'incomplete', 'translated_at', 'translator', 'reviewer', 'history', 'pending',
    ])
        ->and($array['translated_at'])->toBe('2026-10-01T12:00:00Z')
        ->and($array['segments'])->toBe(['a1b2c3d4e5f6', null])
        ->and($array['pending'][0])->toBe([
            'plan' => 'plan-2222222222222222',
            'source_hash' => 'source-2222222222222222',
            'target_hash' => '2222222222222222',
            'target_before' => '1b7d02c4a9e6f358',
            'segments' => ['a1b2c3d4e5f6'],
            'identical' => false,
            'incomplete' => false,
            'translator' => 'anthropic:claude-sonnet-5-5',
            'reviewer' => 'gemini:gemini-3.8-flash',
            'created_at' => '2026-10-01T12:00:00Z',
        ])
        ->and(FieldState::fromArray($array))->toEqual($field)
        ->and(FieldState::fromArray($array)->toArray())->toBe($array);
});

it('round-trips an item record', function () {
    $item = new ItemState(TargetRef::parse('entry::0b5f'), 'en', ItemState::VERSION, [
        'title' => machineState('9f2c1a7e5b3d4c10', '1b7d02c4a9e6f358'),
        'body' => new FieldState(sourceHash: '77aa000000000000'),
    ]);

    expect($item->toArray())->toBe([
        'reference' => 'entry::0b5f',
        'source_site' => 'en',
        'v' => 1,
        'fields' => [
            'title' => ['source_hash' => '9f2c1a7e5b3d4c10', 'target_hash' => '1b7d02c4a9e6f358'],
            'body' => ['source_hash' => '77aa000000000000'],
        ],
    ])
        ->and(ItemState::fromArray($item->toArray()))->toEqual($item);
});

it('writes only the keys a field record holds', function () {
    expect((new FieldState(sourceHash: '77aa000000000000'))->toArray())->toBe(['source_hash' => '77aa000000000000'])
        ->and((new FieldState)->toArray())->toBe([]);
});

it('reads a record that consists of pending entries only', function () {
    $field = FieldState::fromArray(['pending' => [pendingState('2222222222222222')->toArray()]]);

    expect($field->isMachine())->toBeFalse()
        ->and($field->isBaseline())->toBeFalse()
        ->and($field->isEmpty())->toBeFalse()
        ->and($field->sourceHash)->toBeNull()
        ->and($field->segments)->toBe([])
        ->and($field->pending)->toEqual([pendingState('2222222222222222')])
        ->and($field->toArray())->toBe(['pending' => [pendingState('2222222222222222')->toArray()]]);

    $item = ItemState::fromArray(['reference' => 'entry::0b5f', 'v' => 1, 'fields' => ['title' => $field->toArray()]]);

    expect($item->isEmpty())->toBeFalse()
        ->and($item->field('title'))->toEqual($field);
});

it('detects machine output and baselines', function () {
    expect(machineState('9f2c1a7e5b3d4c10', '1b7d02c4a9e6f358'))
        ->isMachine()->toBeTrue()
        ->isBaseline()->toBeFalse();

    expect(new FieldState(sourceHash: '9f2c1a7e5b3d4c10'))
        ->isMachine()->toBeFalse()
        ->isBaseline()->toBeTrue();

    expect(new FieldState)
        ->isMachine()->toBeFalse()
        ->isBaseline()->toBeFalse()
        ->isEmpty()->toBeTrue();

    expect(FieldState::fromArray(['source_hash' => '9f2c1a7e5b3d4c10', 'target_hash' => null]))
        ->isBaseline()->toBeTrue();
});

it('promotes a pending entry to the confirmed keys and moves the outputs of the other entries to the history', function () {
    $older = pendingState('3333333333333333');
    $newer = pendingState('4444444444444444', [
        'segments' => ['a1b2c3d4e5f6', null],
        'identical' => true,
        'incomplete' => true,
        'reviewer' => 'openai:gpt-6.1-sol',
        'createdAt' => CarbonImmutable::parse('2026-10-02T08:30:00Z'),
    ]);

    $field = machineState('1111111111111111', '2222222222222222')->withPending($older)->withPending($newer)->promote($newer);

    expect($field->sourceHash)->toBe('source-4444444444444444')
        ->and($field->targetHash)->toBe('4444444444444444')
        ->and($field->segments)->toBe(['a1b2c3d4e5f6', null])
        ->and($field->identical)->toBeTrue()
        ->and($field->incomplete)->toBeTrue()
        ->and($field->translatedAt->toIso8601ZuluString())->toBe('2026-10-02T08:30:00Z')
        ->and($field->translator)->toBe('anthropic:claude-sonnet-5-5')
        ->and($field->reviewer)->toBe('openai:gpt-6.1-sol')
        ->and($field->pending)->toBe([])
        ->and($field->history)->toBe([
            ['source_hash' => 'source-3333333333333333', 'target_hash' => '3333333333333333'],
            ['source_hash' => '1111111111111111', 'target_hash' => '2222222222222222'],
        ]);
});

it('leaves the output of a reviewed manual field out of the history when another entry is promoted', function () {
    $reviewed = pendingState('3333333333333333', ['baseline' => true]);
    $translated = pendingState('4444444444444444');
    $field = machineState('1111111111111111', '2222222222222222')->withPending($reviewed)->withPending($translated);

    expect($field->promote($translated)->history)->toBe([['source_hash' => '1111111111111111', 'target_hash' => '2222222222222222']])
        ->and($field->promote($reviewed)->history)->toBe([
            ['source_hash' => 'source-4444444444444444', 'target_hash' => '4444444444444444'],
            ['source_hash' => '1111111111111111', 'target_hash' => '2222222222222222'],
        ]);
});

it('promotes the pending entry of a reviewed manual field to a baseline that records the reviewer', function () {
    $pending = pendingState('4444444444444444', ['translator' => null, 'reviewer' => 'openai:gpt-6.1-sol', 'baseline' => true]);
    $baseline = new FieldState(sourceHash: '1111111111111111', translator: 'gemini:gemini-3.8-flash', history: [
        ['source_hash' => '0000000000000000', 'target_hash' => '4444444444444444'],
        ['source_hash' => '0000000000000000', 'target_hash' => '3333333333333333'],
    ]);

    $field = $baseline->withPending($pending)->promote($pending);

    expect($field->isBaseline())->toBeTrue()
        ->and($field->sourceHash)->toBe('source-4444444444444444')
        ->and($field->reviewer)->toBe('openai:gpt-6.1-sol')
        ->and($field->translator)->toBeNull()
        ->and($field->segments)->toBe([])
        ->and($field->pending)->toBe([])
        ->and($field->history)->toBe([['source_hash' => '0000000000000000', 'target_hash' => '3333333333333333']])
        ->and($field->reconcile('4444444444444444', CarbonImmutable::now()))->toEqual($field);
});

it('stores the baseline flag of a pending entry only when it is set', function () {
    $pending = pendingState('4444444444444444', ['baseline' => true]);

    expect($pending->toArray()['baseline'])->toBeTrue()
        ->and(PendingState::fromArray($pending->toArray()))->toEqual($pending)
        ->and(pendingState('4444444444444444')->toArray())->not->toHaveKey('baseline')
        ->and(PendingState::fromArray(pendingState('4444444444444444')->toArray())->baseline)->toBeFalse();
});

it('pushes the previous machine pair to the history and keeps three', function () {
    $field = machineState('s1', 't1');

    foreach (['t2', 't3', 't4', 't5'] as $targetHash) {
        $field = $field->promote(pendingState($targetHash, ['sourceHash' => 's'.substr($targetHash, 1)]));
    }

    expect($field->targetHash)->toBe('t5')
        ->and($field->history)->toBe([
            ['source_hash' => 's4', 'target_hash' => 't4'],
            ['source_hash' => 's3', 'target_hash' => 't3'],
            ['source_hash' => 's2', 'target_hash' => 't2'],
        ]);
});

it('keeps each output once in the history and never the confirmed one', function () {
    $field = machineState('s2', 't2', [['source_hash' => 's1', 'target_hash' => 't1']]);

    expect($field->promote(pendingState('t1', ['sourceHash' => 's3']))->history)
        ->toBe([['source_hash' => 's2', 'target_hash' => 't2']])
        ->and($field->promote(pendingState('t2', ['sourceHash' => 's3']))->history)
        ->toBe([['source_hash' => 's1', 'target_hash' => 't1']]);
});

it('promotes onto a baseline or an empty record without adding history', function () {
    expect((new FieldState(sourceHash: 's1'))->promote(pendingState('t1'))->history)->toBe([])
        ->and((new FieldState)->promote(pendingState('t1')))->isMachine()->toBeTrue();
});

it('turns a pending entry into a baseline', function () {
    $pending = pendingState('4444444444444444', ['translator' => 'openai:gpt-6-luna', 'reviewer' => 'gemini:gemini-3.8-flash']);

    $field = machineState('1111111111111111', '2222222222222222')->withPending($pending)->toBaseline($pending, 'edited', $pending->createdAt);

    expect($field->isBaseline())->toBeTrue()
        ->and($field->sourceHash)->toBe('source-4444444444444444')
        ->and($field->targetHash)->toBeNull()
        ->and($field->translator)->toBe('openai:gpt-6-luna')
        ->and($field->reviewer)->toBeNull()
        ->and($field->segments)->toBe([])
        ->and($field->translatedAt)->toBeNull()
        ->and($field->pending)->toEqual([pendingState('4444444444444444', ['translator' => 'openai:gpt-6-luna', 'reviewer' => 'gemini:gemini-3.8-flash', 'targetBefore' => 'edited'])])
        ->and($field->history)->toBe([['source_hash' => '1111111111111111', 'target_hash' => '2222222222222222']]);
});

it('prepends pending entries and keeps three', function () {
    $field = new FieldState(sourceHash: 's1', targetHash: 't1');

    foreach (['p1', 'p2', 'p3', 'p4'] as $targetHash) {
        $field = $field->withPending(pendingState($targetHash));
    }

    expect(array_map(fn (PendingState $pending) => $pending->targetHash, $field->pending))->toBe(['p4', 'p3', 'p2'])
        ->and($field->sourceHash)->toBe('s1')
        ->and($field->targetHash)->toBe('t1');
});

it('replaces a pending entry of the same plan', function () {
    $field = (new FieldState)
        ->withPending(pendingState('p1', ['plan' => 'one']))
        ->withPending(pendingState('p2', ['plan' => 'two']))
        ->withPending(pendingState('p3', ['plan' => 'one']));

    expect(array_map(fn (PendingState $pending) => [$pending->plan, $pending->targetHash], $field->pending))->toBe([['one', 'p3'], ['two', 'p2']]);
});

it('replaces a fingerprint wherever a record holds it when another walk reads the text', function () {
    $field = machineState('s2', 'cut', [['source_hash' => 's1', 'target_hash' => 'cut'], ['source_hash' => 's0', 'target_hash' => 't0']])
        ->withPending(pendingState('cut', ['plan' => 'one']))
        ->withPending(pendingState('p2', ['plan' => 'two', 'targetBefore' => 'cut']));

    $rewalked = $field->rewalked('cut', 'whole');

    expect($rewalked->targetHash)->toBe('whole')
        ->and($rewalked->sourceHash)->toBe('s2')
        ->and($rewalked->history)->toBe([['source_hash' => 's1', 'target_hash' => 'whole'], ['source_hash' => 's0', 'target_hash' => 't0']])
        ->and(array_map(fn (PendingState $pending) => [$pending->plan, $pending->targetHash, $pending->targetBefore], $rewalked->pending))->toBe([['two', 'p2', 'whole'], ['one', 'whole', null]])
        ->and($rewalked->pending[0]->segments)->toBe(['a1b2c3d4e5f6'])
        ->and($field->rewalked('other', 'whole'))->toEqual($field)
        ->and($field->rewalked('cut', 'cut'))->toBe($field);
});

it('changes fields of an item without changing the item', function () {
    $item = ItemState::empty(TargetRef::parse('term::topics::food::el'));
    $title = machineState('s1', 't1');

    $withTitle = $item->withField('title', $title);

    expect($item->isEmpty())->toBeTrue()
        ->and($item->version)->toBe(ItemState::VERSION)
        ->and($item->sourceSite)->toBeNull()
        ->and($item->field('title'))->toEqual(new FieldState)
        ->and($withTitle->isEmpty())->toBeFalse()
        ->and($withTitle->field('title'))->toBe($title)
        ->and($withTitle->reference)->toBe($item->reference)
        ->and($withTitle->withoutField('title')->isEmpty())->toBeTrue()
        ->and($withTitle->withoutField('missing')->fields)->toBe(['title' => $title])
        ->and($withTitle->withField('title', new FieldState)->isEmpty())->toBeTrue()
        ->and((new ItemState($item->reference, 'en', 1, ['title' => new FieldState]))->isEmpty())->toBeTrue();
});

it('keeps the order of fields and replaces a field in place', function () {
    $item = ItemState::empty(TargetRef::parse('entry::0b5f'))
        ->withField('title', machineState('s1', 't1'))
        ->withField('body', machineState('s2', 't2'))
        ->withField('title', machineState('s3', 't3'));

    expect(array_keys($item->fields))->toBe(['title', 'body'])
        ->and($item->field('title')->targetHash)->toBe('t3');
});

it('keeps a handle that reads as an integer when it adds or replaces a field', function () {
    $item = ItemState::empty(TargetRef::parse('lang::errors::de'))
        ->withField('404', machineState('s1', 't1'))
        ->withField('title', machineState('s2', 't2'))
        ->withField('404', machineState('s3', 't3'));

    expect(array_map('strval', array_keys($item->fields)))->toBe(['404', 'title'])
        ->and($item->field('404')->targetHash)->toBe('t3')
        ->and($item->field('title')->targetHash)->toBe('t2');
});

it('reads values of the wrong type as missing', function () {
    $field = FieldState::fromArray([
        'source_hash' => 42,
        'target_hash' => '',
        'segments' => ['a1b2c3d4e5f6', 7, '', null],
        'identical' => 'yes',
        'incomplete' => 1,
        'translated_at' => 'not a date',
        'translator' => ['anthropic'],
        'history' => [
            ['source_hash' => 's1', 'target_hash' => 't1'],
            ['source_hash' => 's2'],
            'not a pair',
            ['target_hash' => 't3'],
        ],
        'pending' => [
            pendingState('p1')->toArray(),
            [...pendingState('p2')->toArray(), 'plan' => null],
            [...pendingState('p3')->toArray(), 'created_at' => 'yesterday-ish'],
            [...pendingState('p4')->toArray(), 'target_hash' => null],
            'not an entry',
        ],
    ]);

    expect($field->sourceHash)->toBeNull()
        ->and($field->targetHash)->toBeNull()
        ->and($field->segments)->toBe(['a1b2c3d4e5f6', null, null, null])
        ->and($field->identical)->toBeFalse()
        ->and($field->incomplete)->toBeFalse()
        ->and($field->translatedAt)->toBeNull()
        ->and($field->translator)->toBeNull()
        ->and($field->history)->toBe([
            ['source_hash' => 's1', 'target_hash' => 't1'],
            ['source_hash' => null, 'target_hash' => 't3'],
        ])
        ->and($field->pending)->toEqual([pendingState('p1')]);

    expect(FieldState::fromArray(['segments' => 'a1b2', 'history' => 'x', 'pending' => 'y']))->toEqual(new FieldState);
});

it('caps history and pending entries when reading', function () {
    $field = FieldState::fromArray([
        'history' => array_map(fn ($i) => ['source_hash' => "s{$i}", 'target_hash' => "t{$i}"], range(1, 5)),
        'pending' => array_map(fn ($i) => pendingState("p{$i}")->toArray(), range(1, 5)),
    ]);

    expect(array_column($field->history, 'target_hash'))->toBe(['t1', 't2', 't3'])
        ->and(array_map(fn (PendingState $pending) => $pending->targetHash, $field->pending))->toBe(['p1', 'p2', 'p3']);
});

it('reads times in UTC, also with an offset or as a Unix time', function () {
    $read = fn (mixed $time) => FieldState::fromArray(['source_hash' => 's1', 'translated_at' => $time])->translatedAt?->toIso8601ZuluString();

    expect($read('2026-10-01T15:00:00+03:00'))->toBe('2026-10-01T12:00:00Z')
        ->and($read(1790856000))->toBe('2026-10-01T12:00:00Z')
        ->and($read(null))->toBeNull();

    expect(pendingState('p1', ['createdAt' => CarbonImmutable::parse('2026-10-01T15:00:00+03:00')])->toArray()['created_at'])
        ->toBe('2026-10-01T12:00:00Z');
});

it('reads an item record leniently', function () {
    $item = ItemState::fromArray([
        'reference' => 'nav::main::el',
        'source_site' => '',
        'fields' => [
            'title' => ['source_hash' => 's1'],
            'empty' => [],
            'broken' => 'not a record',
        ],
    ]);

    expect((string) $item->reference)->toBe('nav::main::el')
        ->and($item->sourceSite)->toBeNull()
        ->and($item->version)->toBe(0)
        ->and(array_keys($item->fields))->toBe(['title'])
        ->and($item->toArray())->toBe(['reference' => 'nav::main::el', 'v' => 0, 'fields' => ['title' => ['source_hash' => 's1']]]);

    expect(ItemState::fromArray(['reference' => 'entry::0b5f', 'v' => 2])->version)->toBe(2);
});

it('reconciles to the same record when nothing is pending', function () {
    $field = machineState('s1', 't1');

    expect($field->reconcile('t2', CarbonImmutable::now()))->toBe($field)
        ->and($field->inHistory('t1'))->toBeFalse()
        ->and(machineState('s2', 't2', [['source_hash' => 's1', 'target_hash' => 't1']])->inHistory('t1'))->toBeTrue();
});

it('promotes the newest of several pending entries with the saved output and keeps the other outputs in the history', function () {
    $now = CarbonImmutable::parse('2026-10-03T12:00:00Z');
    $older = pendingState('t1', ['plan' => 'older', 'createdAt' => $now->subHours(2)]);
    $newer = pendingState('t1', ['plan' => 'newer', 'createdAt' => $now->subHour()]);
    $other = pendingState('t2', ['createdAt' => $now->subMinute()]);
    $field = (new FieldState)->withPending($older)->withPending($newer)->withPending($other);

    $reconciled = $field->reconcile('t1', $now);

    expect($field->savedPending('t1'))->toBe($newer)
        ->and($field->savedPending(null))->toBeNull()
        ->and($reconciled->targetHash)->toBe('t1')
        ->and($reconciled->translatedAt)->toEqual($newer->createdAt)
        ->and($reconciled->pending)->toBe([])
        ->and($reconciled->history)->toBe([['source_hash' => $other->sourceHash, 'target_hash' => 't2']]);
});

it('reads a delivery that another save overtook as older machine output when it is saved later, not as an edit', function () {
    $now = CarbonImmutable::parse('2026-10-03T12:00:00Z');
    $form = pendingState('t2', ['targetBefore' => 't1', 'createdAt' => $now->subHour()]);
    $run = pendingState('t3', ['targetBefore' => 't1', 'createdAt' => $now->subMinute()]);
    $promoted = machineState('s1', 't1')->withPending($form)->withPending($run)->reconcile('t3', $now);

    expect($promoted->targetHash)->toBe('t3')
        ->and($promoted->pending)->toBe([])
        ->and($promoted->history)->toBe([['source_hash' => $form->sourceHash, 'target_hash' => 't2'], ['source_hash' => 's1', 'target_hash' => 't1']])
        ->and($promoted->inHistory('t2'))->toBeTrue()
        ->and($promoted->reconcile('t2', $now))->toBe($promoted);
});

it('keeps three outputs in the history when it takes in the outputs of the other entries', function () {
    $now = CarbonImmutable::parse('2026-10-03T12:00:00Z');
    $field = machineState('s1', 't1', [['source_hash' => 's0', 'target_hash' => 't0']]);

    foreach (['p1', 'p2', 'p3'] as $minutes => $targetHash) {
        $field = $field->withPending(pendingState($targetHash, ['createdAt' => $now->subMinutes(3 - $minutes)]));
    }

    expect(array_column($field->reconcile('p2', $now)->history, 'target_hash'))->toBe(['p3', 'p1', 't1']);
});

it('writes a baseline from the newest pending entry whose plan saw other text', function () {
    $now = CarbonImmutable::parse('2026-10-03T12:00:00Z');
    $unchanged = pendingState('t2', ['targetBefore' => 'edited', 'createdAt' => $now->subMinute()]);
    $planned = pendingState('t3', ['targetBefore' => null, 'createdAt' => $now->subHour()]);
    $field = machineState('s1', 't1')->withPending($planned)->withPending($unchanged);

    $reconciled = $field->reconcile('edited', $now);

    expect($field->editedPending('edited'))->toBe($planned)
        ->and($field->editedPending(null))->toBeNull()
        ->and($field->editedPending('t1'))->toBeNull()
        ->and($reconciled->isBaseline())->toBeTrue()
        ->and($reconciled->sourceHash)->toBe($planned->sourceHash)
        ->and($reconciled->history)->toBe([['source_hash' => 's1', 'target_hash' => 't1']])
        ->and($reconciled->pending)->toEqual([$unchanged, pendingState('t3', ['targetBefore' => 'edited', 'createdAt' => $now->subHour()])]);
});

it('keeps the recent pending entries after a baseline, so a later save of one reads as machine output and the same text is no new edit', function () {
    $now = CarbonImmutable::parse('2026-10-03T12:00:00Z');
    $fresh = pendingState('t2', ['createdAt' => $now->subDays(FieldState::PENDING_DAYS)->addSecond()]);
    $stale = pendingState('t3', ['createdAt' => $now->subDays(FieldState::PENDING_DAYS)]);

    $baseline = machineState('s1', 't1')->withPending($stale)->withPending($fresh)->reconcile('edited', $now);

    expect($baseline->isBaseline())->toBeTrue()
        ->and($baseline->sourceHash)->toBe($fresh->sourceHash)
        ->and($baseline->pending)->toEqual([pendingState('t2', ['targetBefore' => 'edited', 'createdAt' => $fresh->createdAt])])
        ->and($baseline->reconcile('edited', $now))->toEqual($baseline)
        ->and($baseline->reconcile('t2', $now)->isMachine())->toBeTrue()
        ->and($baseline->reconcile('t2', $now)->pending)->toBe([])
        ->and($baseline->reconcile('edited again', $now)->pending)->toEqual([pendingState('t2', ['targetBefore' => 'edited again', 'createdAt' => $fresh->createdAt])]);
});

it('keeps unsaved pending entries for 30 days', function () {
    $now = CarbonImmutable::parse('2026-10-03T12:00:00Z');
    $fresh = pendingState('t2', ['targetBefore' => 't1', 'createdAt' => $now->subDays(FieldState::PENDING_DAYS)->addSecond()]);
    $stale = pendingState('t3', ['targetBefore' => 't1', 'createdAt' => $now->subDays(FieldState::PENDING_DAYS)]);

    $reconciled = machineState('s1', 't1')->withPending($stale)->withPending($fresh)->reconcile('t1', $now);

    expect($reconciled->pending)->toBe([$fresh])
        ->and($reconciled->targetHash)->toBe('t1');
});
