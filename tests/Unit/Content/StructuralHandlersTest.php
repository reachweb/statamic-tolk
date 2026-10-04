<?php

use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Content\Handlers\GridHandler;
use Reach\StatamicTolk\Content\Handlers\GroupHandler;
use Reach\StatamicTolk\Content\Handlers\ReplicatorHandler;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Tests\Support\ContentFixtures;
use Statamic\Fields\Field;

function structuralWalker(): ContentWalker
{
    return app(ContentWalker::class);
}

/**
 * @return list<array{0: string, 1: string, 2: string}>
 */
function structuralLeaves(Field $field, mixed $value, bool $target = false): array
{
    return array_map(
        fn (Leaf $leaf) => [$leaf->kind->value, $leaf->text, $leaf->label],
        structuralWalker()->leaves($field, $value, $target),
    );
}

function replicatorField(array $config = []): Field
{
    return new Field('blocks', ['type' => 'replicator', 'display' => 'Blocks', 'sets' => ['main' => ['display' => 'Main', 'sets' => [
        'quote' => ['display' => 'Quote', 'fields' => [
            ['handle' => 'text', 'field' => ['type' => 'textarea', 'display' => 'Text']],
        ]],
    ]]], ...$config]);
}

it('is registered for replicator, grid and group at boot', function () {
    $registry = app(FieldtypeRegistry::class);

    expect($registry->for(replicatorField()))->toBeInstanceOf(ReplicatorHandler::class)
        ->and($registry->for(ContentFixtures::field('grid')))->toBeInstanceOf(GridHandler::class)
        ->and($registry->for(ContentFixtures::field('replicator-nested')))->toBeInstanceOf(GroupHandler::class);
});

it('returns an identical value when every leaf keeps its text', function (string $fixture, bool $target) {
    $field = ContentFixtures::field($fixture);
    $value = ContentFixtures::value($fixture);
    $texts = array_map(fn (Leaf $leaf) => $leaf->text, structuralWalker()->leaves($field, $value, $target));

    expect($texts)->not->toBe([])
        ->and(structuralWalker()->mapField($field, $value, fn (Leaf $leaf) => $leaf->text, $target))->toBe($value)
        ->and(structuralWalker()->inject($field, $value, $texts, $target))->toBe($value);
})->with(['replicator-nested', 'grid'])->with(['source' => false, 'target' => true]);

it('lists the leaves of replicator-nested set by set, with their labels', function () {
    expect(structuralLeaves(ContentFixtures::field('replicator-nested'), ContentFixtures::value('replicator-nested')))->toBe([
        ['plain', 'Summer 2026', 'Page builder › Kicker'],
        ['plain', 'Special offers', 'Page builder › Blocks › Text › Heading'],
        ['plain', 'Read more', 'Page builder › Blocks › Text › Link label'],
        ['inline', 'Stay <1>seven nights</1> and the seventh is on us. See the <2>rooms</2>.', 'Page builder › Blocks › Text › Body'],
        ['inline', 'Valid from April to June.', 'Page builder › Blocks › Text › Body'],
        ['plain', 'Breakfast like a local', 'Page builder › Blocks › Text › Heading'],
        ['inline', 'Start with <1>bougatsa</1>, a warm pastry filled with sweet cheese.', 'Page builder › Blocks › Text › Body'],
        // A single word passes the leaf rules; the audit suggests switching technical fields such as `anchor` off (T6.8).
        ['plain', 'breakfast', 'Page builder › Blocks › Text › Anchor'],
        ['plain', 'Is the tap water safe to drink?', 'Page builder › Blocks › FAQ › Questions › Question'],
        ['plain', 'Yes, although most restaurants serve bottled water.', 'Page builder › Blocks › FAQ › Questions › Answer'],
        ['plain', 'Do I need to tip?', 'Page builder › Blocks › FAQ › Questions › Question'],
        ['plain', 'Service is included, but leaving 5–10% for good service is common.', 'Page builder › Blocks › FAQ › Questions › Answer'],
        ['plain', 'When do restaurants open for dinner?', 'Page builder › Blocks › FAQ › Questions › Question'],
        ['plain', 'Most open around 19:00, but Cretans rarely eat before 21:00.', 'Page builder › Blocks › FAQ › Questions › Answer'],
        ['plain', 'Book your stay', 'Page builder › Blocks › Call to action › Label'],
        ['plain', 'Prices include breakfast and the city tax.', 'Page builder › Blocks › Note › Text'],
    ]);
});

it('gives nested leaves the path of their data', function () {
    $leaves = structuralWalker()->leaves(ContentFixtures::field('replicator-nested'), ContentFixtures::value('replicator-nested'));

    expect($leaves[0]->path)->toBe(['replicator_nested', 'kicker'])
        ->and($leaves[1]->path)->toBe(['replicator_nested', 'blocks', 0, 'heading'])
        ->and($leaves[3]->path)->toBe(['replicator_nested', 'blocks', 0, 'body'])
        ->and($leaves[10]->path)->toBe(['replicator_nested', 'blocks', 2, 'items', 1, 'question'])
        ->and($leaves[14]->path)->toBe(['replicator_nested', 'blocks', 3, 'cta_label'])
        ->and($leaves[15]->path)->toBe(['replicator_nested', 'blocks', 4, 'text']);
});

it('never offers a field with translation switched off, a reference or a non-text field', function () {
    $texts = array_column(structuralLeaves(ContentFixtures::field('replicator-nested'), ContentFixtures::value('replicator-nested')), 1);

    expect($texts)->not->toContain('Ask Nikos before changing the prices.')
        ->and($texts)->not->toContain('special-offers')
        ->and($texts)->not->toContain('https://booking.kyma-bay.example/')
        ->and($texts)->not->toContain('primary');
});

it('changes only leaf texts, and keeps ids, set types and enabled flags', function () {
    $field = ContentFixtures::field('replicator-nested');
    $value = ContentFixtures::value('replicator-nested');

    $translated = structuralWalker()->mapField($field, $value, fn (Leaf $leaf) => "[el] {$leaf->text}");
    $blocks = $translated['blocks'];

    expect(array_map(fn (array $set) => array_intersect_key($set, array_flip(['id', 'type', 'enabled'])), $blocks))
        ->toBe(array_map(fn (array $set) => array_intersect_key($set, array_flip(['id', 'type', 'enabled'])), $value['blocks']))
        ->and(array_map(fn (array $row) => array_keys($row), $blocks[2]['items']))->toBe(array_map(fn (array $row) => array_keys($row), $value['blocks'][2]['items']))
        ->and($blocks[2]['items'][0])->toBe([
            'id' => 'ka44eyis',
            'question' => '[el] Is the tap water safe to drink?',
            'answer' => '[el] Yes, although most restaurants serve bottled water.',
        ])
        ->and($blocks[0]['anchor'])->toBe('special-offers')
        ->and($blocks[0]['body'][0]['content'][3])->toBe([
            'type' => 'text',
            'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::7fa871f4-da60-46fe-9053-a94e5eb8e212']]],
            'text' => 'rooms',
        ])
        ->and($blocks[3])->toBe([
            'type' => 'cta',
            'cta_label' => '[el] Book your stay',
            'cta_url' => 'https://booking.kyma-bay.example/',
            'cta_style' => 'primary',
        ])
        ->and($blocks[4])->toBe([
            'id' => 'n4vq2bzk',
            'type' => 'note',
            'enabled' => false,
            'text' => '[el] Prices include breakfast and the city tax.',
            'internal' => 'Ask Nikos before changing the prices.',
        ])
        ->and($translated['kicker'])->toBe('[el] Summer 2026');
});

it('walks a translated value into the same leaves, in the same order', function () {
    $field = ContentFixtures::field('replicator-nested');
    $value = ContentFixtures::value('replicator-nested');
    $source = structuralWalker()->leaves($field, $value);

    $target = structuralWalker()->leaves($field, structuralWalker()->mapField($field, $value, fn (Leaf $leaf) => "[el] {$leaf->text}"), target: true);

    expect(array_map(fn (Leaf $leaf) => $leaf->text, $target))->toBe(array_map(fn (Leaf $leaf) => "[el] {$leaf->text}", $source))
        ->and(array_map(fn (Leaf $leaf) => $leaf->path, $target))->toBe(array_map(fn (Leaf $leaf) => $leaf->path, $source));
});

it('offers a set field holding its default on a source walk and not on a target walk', function () {
    $field = ContentFixtures::field('replicator-nested');
    $value = ContentFixtures::value('replicator-nested');

    expect(array_column(structuralLeaves($field, $value), 1))->toContain('Read more')
        ->and(array_column(structuralLeaves($field, $value, target: true), 1))->not->toContain('Read more')
        ->and(structuralLeaves($field, $value, target: true))->toHaveCount(15);
});

it('leaves a set of an unknown type, or without a type, untouched', function () {
    $value = [
        ['id' => 'a1', 'type' => 'gallery', 'enabled' => true, 'text' => 'Not mine'],
        ['id' => 'a2', 'text' => 'No type'],
        ['id' => 'a3', 'type' => 12, 'text' => 'A number'],
        'quote',
        ['id' => 'a4', 'type' => 'quote', 'text' => 'Lovely stay'],
    ];

    expect(structuralLeaves(replicatorField(), $value))->toBe([['plain', 'Lovely stay', 'Blocks › Quote › Text']])
        ->and(structuralWalker()->mapField(replicatorField(), $value, fn (Leaf $leaf) => 'X'))->toBe([...array_slice($value, 0, 4), [...$value[4], 'text' => 'X']]);
});

it('labels a set without a display by its handle', function () {
    $field = new Field('blocks', ['type' => 'replicator', 'display' => 'Blocks', 'sets' => ['call_to_action' => ['fields' => [
        ['handle' => 'label', 'field' => ['type' => 'text']],
    ]]]]);

    expect(structuralLeaves($field, [['type' => 'call_to_action', 'label' => 'Book now']]))
        ->toBe([['plain', 'Book now', 'Blocks › Call To Action › Label']]);
});

it('lists the leaves of a grid row by row, and leaves numbers, identifiers and URLs untouched', function () {
    $field = ContentFixtures::field('grid');
    $value = ContentFixtures::value('grid');

    expect(structuralLeaves($field, $value))->toBe([
        ['plain', 'Garden double', 'Rooms › Name'],
        ['plain', 'A quiet room on the ground floor, with a door to the garden.', 'Rooms › Description'],
        ['plain', 'Sea view suite', 'Rooms › Name'],
        ['plain', "Two rooms and a balcony over the harbour.\nThe sofa turns into a bed for one child.", 'Rooms › Description'],
        ['plain', 'Honeymoon suite', 'Rooms › Name'],
    ])->and(structuralWalker()->leaves($field, $value)[2]->path)->toBe(['grid', 1, 'name'])
        ->and(structuralWalker()->mapField($field, $value, fn (Leaf $leaf) => "[el] {$leaf->text}"))->toBe([
            [...$value[0], 'name' => '[el] Garden double', 'description' => '[el] A quiet room on the ground floor, with a door to the garden.'],
            [...$value[1], 'name' => '[el] Sea view suite', 'description' => "[el] Two rooms and a balcony over the harbour.\nThe sofa turns into a bed for one child."],
            [...$value[2], 'name' => '[el] Honeymoon suite'],
        ]);
});

it('leaves values of another shape untouched', function (Field $field, mixed $value) {
    expect(structuralLeaves($field, $value))->toBe([])
        ->and(structuralWalker()->mapField($field, $value, fn (Leaf $leaf) => 'X'))->toBe($value);
})->with([
    'a null replicator' => fn () => [replicatorField(), null],
    'a replicator string' => fn () => [replicatorField(), 'Lovely stay'],
    'a replicator map' => fn () => [replicatorField(), ['first' => ['type' => 'quote', 'text' => 'Lovely stay']]],
    'an empty replicator' => fn () => [replicatorField(), []],
    'a null grid' => fn () => [ContentFixtures::field('grid'), null],
    'a grid map' => fn () => [ContentFixtures::field('grid'), ['first' => ['name' => 'Garden double']]],
    'grid rows that are not arrays' => fn () => [ContentFixtures::field('grid'), ['Garden double', 12, null]],
    'a null group' => fn () => [ContentFixtures::field('replicator-nested'), null],
    'a group string' => fn () => [ContentFixtures::field('replicator-nested'), 'Summer 2026'],
    'a group list' => fn () => [ContentFixtures::field('replicator-nested'), ['Summer 2026']],
    'an empty group' => fn () => [ContentFixtures::field('replicator-nested'), []],
]);

it('leaves keys that are no fields untouched', function () {
    $value = ['kicker' => 'Summer 2026', 'legacy_heading' => 'Not in the blueprint', 'blocks' => 'Not a list'];

    expect(structuralLeaves(ContentFixtures::field('replicator-nested'), $value))->toBe([['plain', 'Summer 2026', 'Page builder › Kicker']]);
});
