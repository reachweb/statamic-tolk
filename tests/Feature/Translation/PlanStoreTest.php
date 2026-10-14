<?php

use Illuminate\Support\Facades\Cache;
use Reach\StatamicTolk\Ai\ModelRef;
use Reach\StatamicTolk\Ai\Usage;
use Reach\StatamicTolk\Ai\UsageMap;
use Reach\StatamicTolk\Enums\UnitKind;
use Reach\StatamicTolk\Translation\Plan;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\PlanStore;
use Reach\StatamicTolk\Translation\Unit;
use Reach\StatamicTolk\Translation\UnitOutcome;

beforeEach(function () {
    $this->useFileCache();
});

function storePlan(string $id = '01JPLAN', array $batches = [['id' => 'b1', 'segment_ids' => ['s1', 's2'], 'chars' => 10], ['id' => 'b2', 'segment_ids' => ['s3'], 'chars' => 5]]): Plan
{
    return new Plan(
        id: $id,
        kind: UnitKind::Translate,
        reference: 'entry::abc',
        target: ['type' => 'entry', 'source_id' => 'origin', 'site' => 'el'],
        sourceSite: 'en',
        targetSite: 'el',
        options: new PlanOptions(ModelRef::fromString('anthropic:claude-sonnet-5-5'), userId: '7'),
        document: ['type' => 'entry', 'title' => 'Hello', 'container' => 'Articles', 'url' => null, 'summary' => ''],
        fields: [],
        segments: [],
        batches: $batches,
        skipped: [],
    );
}

function storeOutcome(string $unit, array $spawned = [], array $translations = []): UnitOutcome
{
    return new UnitOutcome(
        unit: $unit,
        model: 'anthropic:claude-sonnet-5-5',
        translations: $translations,
        failed: ['s9' => UnitOutcome::INVALID],
        warnings: ['s1' => ['A warning.']],
        usage: (new UsageMap)->add(ModelRef::fromString('anthropic:claude-sonnet-5-5'), new Usage(input: 3, output: 2, calls: 1)),
        spawned: $spawned,
    );
}

it('stores a plan as an array and reads it back', function () {
    $plan = storePlan();

    app(PlanStore::class)->put($plan);

    expect(Cache::get('tolk:plan:01JPLAN'))->toBe($plan->toArray())
        ->and(app(PlanStore::class)->get('01JPLAN')->toArray())->toBe($plan->toArray())
        ->and(app(PlanStore::class)->get('01JMISSING'))->toBeNull();
});

it('says whether a plan is still stored', function () {
    $store = app(PlanStore::class);
    $store->put(storePlan());

    expect($store->has('01JPLAN'))->toBeTrue()
        ->and($store->has('01JMISSING'))->toBeFalse();

    Cache::flush();

    expect($store->has('01JPLAN'))->toBeFalse();
});

it('stores an outcome as an array under the unit\'s own key and reads it back', function () {
    $outcome = storeOutcome('b1', [new Unit('b1~s2', UnitKind::Translate, ['s2'], repair: 1, notes: ['s2' => 'A note.'])], ['s1' => 'Γεια']);

    app(PlanStore::class)->putOutcome('01JPLAN', $outcome);

    expect(Cache::get('tolk:plan:01JPLAN:unit:b1'))->toBe($outcome->toArray())
        ->and(app(PlanStore::class)->outcome('01JPLAN', 'b1'))->toEqual($outcome)
        ->and(app(PlanStore::class)->outcome('01JPLAN', 'b2'))->toBeNull();
});

it('lists the initial batches, each followed depth first by what its outcome spawned', function () {
    $plan = storePlan();
    $store = app(PlanStore::class);
    $store->putOutcome($plan->id, storeOutcome('b1', [new Unit('b1.a', UnitKind::Translate, ['s1'], depth: 1), new Unit('b1.b', UnitKind::Translate, ['s2'], depth: 1)]));
    $store->putOutcome($plan->id, storeOutcome('b1.a', [new Unit('b1.a^', UnitKind::Translate, ['s1'], depth: 1, capMultiplier: 2)]));

    $units = $store->units($plan);

    expect(array_keys($units))->toBe(['b1', 'b1.a', 'b1.a^', 'b1.b', 'b2'])
        ->and(array_map(fn (array $node) => $node['outcome'] !== null, $units))->toBe(['b1' => true, 'b1.a' => true, 'b1.a^' => false, 'b1.b' => false, 'b2' => false])
        ->and($units['b2']['unit'])->toEqual(new Unit('b2', UnitKind::Translate, ['s3']));
});

it('counts transient failures per unit', function () {
    $store = app(PlanStore::class);

    expect($store->failures('01JPLAN', 'b1'))->toBe(0)
        ->and($store->countFailure('01JPLAN', 'b1'))->toBe(1)
        ->and($store->countFailure('01JPLAN', 'b1'))->toBe(2)
        ->and($store->failures('01JPLAN', 'b1'))->toBe(2)
        ->and($store->failures('01JPLAN', 'b2'))->toBe(0);
});

it('forgets the plan, every outcome reachable from it and the failure counts', function () {
    $plan = storePlan();
    $other = storePlan('01JOTHER');
    $store = app(PlanStore::class);
    $store->put($plan);
    $store->put($other);
    $store->putOutcome($plan->id, storeOutcome('b1', [new Unit('b1?', UnitKind::Translate, ['s2'], missingRetry: true)]));
    $store->putOutcome($plan->id, storeOutcome('b1?'));
    $store->countFailure($plan->id, 'b2');
    $store->putOutcome($other->id, storeOutcome('b1'));

    $store->forget($plan);

    expect($store->get($plan->id))->toBeNull()
        ->and($store->outcome($plan->id, 'b1'))->toBeNull()
        ->and($store->outcome($plan->id, 'b1?'))->toBeNull()
        ->and($store->failures($plan->id, 'b2'))->toBe(0)
        ->and($store->get($other->id))->not->toBeNull()
        ->and($store->outcome($other->id, 'b1'))->not->toBeNull();
});

it('keeps plans and outcomes for plan_ttl seconds', function () {
    config(['tolk.plan_ttl' => 600]);
    $store = app(PlanStore::class);
    $store->put(storePlan());
    $store->putOutcome('01JPLAN', storeOutcome('b1'));

    $this->travel(599)->seconds();

    expect($store->get('01JPLAN'))->not->toBeNull();

    $this->travel(2)->seconds();

    expect($store->get('01JPLAN'))->toBeNull()
        ->and($store->outcome('01JPLAN', 'b1'))->toBeNull();
});

it('locks a unit for the given seconds', function () {
    $lock = app(PlanStore::class)->lock('01JPLAN', 'b1', 30);

    expect($lock->get())->toBeTrue()
        ->and(app(PlanStore::class)->lock('01JPLAN', 'b1', 30)->get())->toBeFalse()
        ->and(app(PlanStore::class)->lock('01JPLAN', 'b2', 30)->get())->toBeTrue();

    $lock->release();

    expect(app(PlanStore::class)->lock('01JPLAN', 'b1', 30)->get())->toBeTrue();
});
