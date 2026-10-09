<?php

use Facades\Statamic\Fieldtypes\RowId;
use Reach\StatamicTolk\Ai\ModelCatalog;
use Reach\StatamicTolk\Content\Fingerprint;
use Reach\StatamicTolk\Contracts\Target;
use Reach\StatamicTolk\Enums\FieldStatus;
use Reach\StatamicTolk\State\StatusResolver;
use Reach\StatamicTolk\Support\Settings;
use Reach\StatamicTolk\Targets\ApplyOptions;
use Reach\StatamicTolk\Targets\TargetFactory;
use Reach\StatamicTolk\Tests\Support\ContentFixtures;
use Reach\StatamicTolk\Tests\Support\StateFixtures;
use Reach\StatamicTolk\Translation\EditorDelivery;
use Reach\StatamicTolk\Translation\Pipeline;
use Reach\StatamicTolk\Translation\Plan;
use Reach\StatamicTolk\Translation\Planner;
use Reach\StatamicTolk\Translation\PlanOptions;
use Reach\StatamicTolk\Translation\PlanStore;
use Reach\StatamicTolk\Translation\TargetTranslator;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Entry;

beforeEach(function () {
    $this->useFileCache();

    $this->makeCollection('articles', ['en', 'el', 'de'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'intro' => ['type' => 'textarea', 'localizable' => true, 'display' => 'Introduction'],
        'subtitle' => ['type' => 'text', 'localizable' => true, 'default' => 'Read on'],
    ]);

    $this->origin = $this->makeEntry('articles', 'en', ['title' => 'Hello', 'intro' => 'A short intro.', 'subtitle' => 'All about it'], 'hello');
    $this->greek = $this->localize($this->origin, 'el', ['title' => 'Γεια']);
});

function greekDeliveryTarget(): Target
{
    return app(TargetFactory::class)->forEntry(test()->origin, 'el');
}

/**
 * Plans the target, runs every unit and returns the plan.
 */
function runDeliveryPlan(Target $target, array $options = []): Plan
{
    $plan = app(Planner::class)->plan($target, PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class), $options));
    $pipeline = app(Pipeline::class);
    app(PlanStore::class)->put($plan);

    while (($units = $pipeline->runnable($plan)) !== []) {
        foreach (array_keys($units) as $unitId) {
            $pipeline->runUnit($plan, $unitId);
        }
    }

    return $plan;
}

/**
 * What core's own edit endpoint gives the publish form of the entry.
 */
function coreEditPayload(EntryContract $entry): array
{
    test()->signInAdmin();

    return test()->getJson(cp_route('collections.entries.edit', [$entry->collectionHandle(), $entry->id()]))->assertOk()->json();
}

it('delivers a text field as core\'s edit page gives it', function () {
    $this->fakeTranslations();
    $plan = runDeliveryPlan(greekDeliveryTarget());

    $delivered = app(EditorDelivery::class)->deliver($plan, greekDeliveryTarget(), ['intro', 'subtitle']);

    expect(array_keys($delivered))->toBe(['intro', 'subtitle'])
        ->and($delivered['intro'])->toBe(['value' => '[T] A short intro.', 'meta' => null, 'incomplete' => false, 'warnings' => [], 'corrections' => [], 'reviewer' => null])
        ->and($delivered['subtitle']['value'])->toBe('[T] All about it');

    $entry = Entry::find($this->greek->id());
    $entry->merge(['intro' => '[T] A short intro.', 'subtitle' => '[T] All about it'])->save();
    $core = coreEditPayload($entry);

    foreach (['intro', 'subtitle'] as $handle) {
        expect($delivered[$handle]['value'])->toBe($core['values'][$handle])
            ->and($delivered[$handle]['meta'])->toBe($core['meta'][$handle]);
    }
});

it('records a pending entry and leaves the stored entry unchanged', function () {
    $this->fakeTranslations();
    $before = Entry::find($this->greek->id())->data()->all();
    $plan = runDeliveryPlan(greekDeliveryTarget());

    app(EditorDelivery::class)->deliver($plan, greekDeliveryTarget(), ['intro']);

    $pending = StateFixtures::stored($this->greek)->field('intro')->pending;

    expect(Entry::find($this->greek->id())->data()->all())->toBe($before)
        ->and($pending)->toHaveCount(1)
        ->and($pending[0]->plan)->toBe($plan->id)
        ->and($pending[0]->sourceHash)->toBe(Fingerprint::of('A short intro.'))
        ->and($pending[0]->targetHash)->toBe(Fingerprint::ofLeaves(['[T] A short intro.']))
        ->and($pending[0]->targetBefore)->toBeNull()
        ->and($pending[0]->segments)->toBe([Fingerprint::ofText('A short intro.')])
        ->and($pending[0]->translator)->toBe('anthropic:claude-sonnet-5-5')
        ->and(StateFixtures::statuses($this->origin)['intro'])->toBe(FieldStatus::Untranslated);
});

it('promotes the pending entry once the form is saved with the delivered value', function () {
    $this->fakeTranslations();
    $plan = runDeliveryPlan(greekDeliveryTarget());
    $delivered = app(EditorDelivery::class)->deliver($plan, greekDeliveryTarget(), ['intro']);

    Entry::find($this->greek->id())->set('intro', $delivered['intro']['value'])->save();

    expect(StateFixtures::statuses($this->origin)['intro'])->toBe(FieldStatus::Current);
});

it('records the hash of the target that was there when the plan was made', function () {
    $this->fakeTranslations();
    $plan = runDeliveryPlan(greekDeliveryTarget(), ['mode' => 'all', 'overwriteManual' => true]);

    app(EditorDelivery::class)->deliver($plan, greekDeliveryTarget(), ['title']);

    expect(StateFixtures::stored($this->greek)->field('title')->pending[0]->targetBefore)
        ->toBe(Fingerprint::ofLeaves(['Γεια']));
});

it('delivers a field whose source lost its value as a sync with what the origin gives', function (?string $emptied) {
    $this->fakeTranslations();
    app(TargetTranslator::class)->translate(greekDeliveryTarget(), PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class)), new ApplyOptions);
    ($emptied === null ? $this->origin->remove('subtitle') : $this->origin->set('subtitle', $emptied))->save();
    $state = StateFixtures::stored($this->greek)->toArray();

    $plan = runDeliveryPlan(greekDeliveryTarget(), ['mode' => 'update']);
    $delivered = app(EditorDelivery::class)->deliver($plan, greekDeliveryTarget(), ['subtitle']);
    $core = coreEditPayload(Entry::find($this->greek->id()));

    expect($plan->fields['subtitle']['clear'])->toBeTrue()
        ->and($delivered['subtitle'])->toBe(['value' => 'Read on', 'meta' => null, 'sync' => true])
        ->and($delivered['subtitle']['value'])->toBe($core['originValues']['subtitle'])
        ->and($delivered['subtitle']['meta'])->toBe($core['originMeta']['subtitle'])
        ->and(StateFixtures::stored($this->greek)->toArray())->toBe($state);
})->with([
    'removed' => [null],
    'emptied' => [''],
]);

it('delivers a sync with what a chained source shows for a field it emptied', function () {
    $this->fakeTranslations();
    $this->greek->delete();
    $german = $this->localize($this->origin, 'de', ['title' => 'Hallo', 'subtitle' => 'Alles darüber']);
    $greek = $this->localize($german, 'el');
    $target = fn () => app(TargetFactory::class)->forEntry($german, 'el');
    app(TargetTranslator::class)->translate($target(), PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class)), new ApplyOptions);
    $german->set('subtitle', '')->save();

    $delivered = app(EditorDelivery::class)->deliver(runDeliveryPlan($target(), ['mode' => 'update']), $target(), ['subtitle']);
    $core = coreEditPayload(Entry::find($greek->id()));

    expect($delivered['subtitle'])->toBe(['value' => '', 'meta' => null, 'sync' => true])
        ->and($delivered['subtitle']['value'])->toBe($core['originValues']['subtitle']);
});

it('delivers a copy of a source without text', function () {
    $this->fakeTranslations();
    app(TargetTranslator::class)->translate(greekDeliveryTarget(), PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class)), new ApplyOptions);
    $this->origin->set('subtitle', '2026')->save();

    $plan = runDeliveryPlan(greekDeliveryTarget(), ['mode' => 'update']);
    $delivered = app(EditorDelivery::class)->deliver($plan, greekDeliveryTarget(), ['subtitle']);
    $pending = StateFixtures::stored($this->greek)->field('subtitle')->pending;

    expect($delivered['subtitle'])->toBe(['value' => '2026', 'meta' => null, 'incomplete' => false, 'warnings' => [], 'corrections' => [], 'reviewer' => null])
        ->and($pending[0]->identical)->toBeTrue()
        ->and($pending[0]->targetHash)->toBe(Fingerprint::ofLeaves([]));
});

it('flags a field with a part left untranslated', function () {
    $this->fakeTranslations(fn (string $text) => "<b>{$text}");
    $plan = runDeliveryPlan(greekDeliveryTarget(), ['fields' => ['intro']]);

    $delivered = app(EditorDelivery::class)->deliver($plan, greekDeliveryTarget(), ['intro']);

    expect($delivered['intro']['value'])->toBe('A short intro.')
        ->and($delivered['intro']['incomplete'])->toBeTrue()
        ->and($delivered['intro']['warnings'])->toHaveCount(1)
        ->and(StateFixtures::stored($this->greek)->field('intro')->pending[0]->incomplete)->toBeTrue();
});

it('refuses a target that does not exist', function () {
    $this->fakeTranslations();
    $target = app(TargetFactory::class)->forEntry($this->origin, 'de');
    $plan = runDeliveryPlan($target);

    app(EditorDelivery::class)->deliver($plan, $target, ['intro']);
})->throws(LogicException::class);

it('delivers the ids the localization\'s rows and sets have where the origin stores none', function () {
    $this->signInAdmin();
    $this->fakeTranslations();
    $this->makeCollection('pages', ['en', 'el'], [
        'blocks' => ['type' => 'replicator', 'localizable' => true, 'sets' => ['main' => ['sets' => [
            'text' => ['display' => 'Text', 'fields' => [['handle' => 'heading', 'field' => ['type' => 'text']]]],
        ]]]],
        'body' => ['type' => 'bard', 'localizable' => true, 'sets' => ['main' => ['sets' => [
            'quote' => ['display' => 'Quote', 'fields' => [['handle' => 'text', 'field' => ['type' => 'textarea']]]],
        ]]]],
    ]);
    // Written by code: no row or set ids.
    $origin = $this->makeEntry('pages', 'en', [
        'blocks' => [['type' => 'text', 'heading' => 'Rooms'], ['type' => 'text', 'heading' => 'Dining']],
        'body' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Welcome.']]],
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'quote', 'text' => 'Best stay ever.']]],
        ],
    ], 'home');
    $target = fn () => app(TargetFactory::class)->forEntry($origin, 'el');
    app(TargetTranslator::class)->translate($target(), PlanOptions::fromSettings(app(Settings::class), app(ModelCatalog::class)), new ApplyOptions);
    $greek = $origin->fresh()->in('el');

    $delivered = app(EditorDelivery::class)->deliver(runDeliveryPlan($target(), ['mode' => 'all']), $target(), ['blocks', 'body']);

    expect(array_column($delivered['blocks']['value'], '_id'))->toBe(array_column($greek->get('blocks'), 'id'))
        ->and($delivered['body']['value'][1]['attrs']['id'])->toBe($greek->get('body')[1]['attrs']['id'])->toBeString();
});

describe('rich content', function () {
    beforeEach(function () {
        // Rows and sets stored without an id get a random one on every pre-processing; numbered instead, two
        // pre-processings of the same value give the same ids.
        $this->rowIds = 0;
        RowId::partialMock()->shouldReceive('generate')->andReturnUsing(fn () => 'row'.++$this->rowIds);
    });

    it('delivers Bard with sets, Replicator and Grid values with the meta core gives them', function () {
        $this->signInAdmin();
        $this->fakeTranslations();
        AssetContainer::make('assets')->disk('local')->save();
        ContentFixtures::field('replicator-nested');
        $fixtures = ['content' => 'bard-rich', 'builder' => 'replicator-nested', 'rooms' => 'grid'];
        $this->makeCollection('pages', ['en', 'el'], [
            'title' => ['type' => 'text', 'localizable' => true],
            ...array_map(fn (string $name) => [...ContentFixtures::raw($name)['field'], 'localizable' => true], $fixtures),
        ]);
        $origin = $this->makeEntry('pages', 'en', ['title' => 'Home', ...array_map(ContentFixtures::value(...), $fixtures)], 'home');
        $greek = $this->localize($origin, 'el');
        $target = fn () => app(TargetFactory::class)->forEntry($origin, 'el');
        $plan = runDeliveryPlan($target());
        $handles = array_keys($fixtures);

        $this->rowIds = 0;
        // As the editor's endpoints send it.
        $delivered = json_decode(json_encode(app(EditorDelivery::class)->deliver($plan, $target(), $handles)), true);

        Entry::find($greek->id())->merge(array_combine($handles, array_map(
            fn (string $handle) => app(Pipeline::class)->assemble($plan, $target(), $handle)->value,
            $handles,
        )))->save();
        $this->rowIds = 0;
        $core = coreEditPayload(Entry::find($greek->id()));

        foreach ($handles as $handle) {
            expect($delivered[$handle]['value'])->toBe($core['values'][$handle])
                ->and($delivered[$handle]['meta'])->toBe($core['meta'][$handle]);
        }

        $sets = array_values(array_filter($delivered['content']['value'], fn (array $node) => $node['type'] === 'set'));
        $blocks = $delivered['builder']['value']['blocks'];

        expect($sets)->toHaveCount(2)
            ->and(array_keys($delivered['content']['meta']['existing']))->toBe(array_map(fn (array $set) => $set['attrs']['id'], $sets))
            ->and(array_keys($delivered['builder']['meta']['blocks']['existing']))->toBe(array_column($blocks, '_id'))
            ->and(array_keys($delivered['rooms']['meta']['existing']))->toBe(array_column($delivered['rooms']['value'], '_id'))
            ->and($this->rowIds)->toBeGreaterThan(0);
    });

    it('delivers the default of a set field the source rows lack, and records the hash of the value as it will be stored', function () {
        $this->fakeTranslations();
        $this->makeCollection('pages', ['en', 'el'], [
            'blocks' => ['type' => 'replicator', 'localizable' => true, 'sets' => ['main' => ['sets' => [
                'text' => ['display' => 'Text', 'fields' => [
                    ['handle' => 'heading', 'field' => ['type' => 'text']],
                    ['handle' => 'kicker', 'field' => ['type' => 'text', 'default' => 'Read this']],
                ]],
            ]]]],
        ]);
        $origin = $this->makeEntry('pages', 'en', ['blocks' => [
            ['type' => 'text', 'heading' => 'Rooms'],
            ['type' => 'text', 'heading' => 'Dining'],
        ]], 'home');
        $greek = $this->localize($origin, 'el');
        $target = app(TargetFactory::class)->forEntry($origin, 'el');
        $plan = runDeliveryPlan($target);
        $assembled = app(Pipeline::class)->assemble($plan, $target, 'blocks');

        $delivered = app(EditorDelivery::class)->deliver($plan, $target, ['blocks']);
        $pending = StateFixtures::stored($greek)->field('blocks')->pending[0];

        expect(array_column($delivered['blocks']['value'], 'kicker'))->toBe(['Read this', 'Read this'])
            ->and(array_column($delivered['blocks']['value'], 'heading'))->toBe(['[T] Rooms', '[T] Dining'])
            ->and(array_column($assembled->value, 'kicker'))->toBe([])
            ->and($pending->targetHash)->toBe($assembled->targetHash)
            ->and($pending->segments)->toBe($assembled->segments);

        Entry::find($greek->id())->set('blocks', $target->formFields(['blocks' => $delivered['blocks']['value']])->process()->values()['blocks'])->save();

        expect(Entry::find($greek->id())->get('blocks')[0]['kicker'])->toBe('Read this')
            ->and(StateFixtures::statuses($origin)['blocks'])->toBe(FieldStatus::Current)
            ->and(app(StatusResolver::class)->targetHash($target->fields()->get('blocks'), Entry::find($greek->id())->get('blocks')))->toBe($assembled->targetHash);
    });
});
