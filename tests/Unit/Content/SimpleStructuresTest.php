<?php

use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Content\Handlers\ArrayHandler;
use Reach\StatamicTolk\Content\Handlers\ListHandler;
use Reach\StatamicTolk\Content\Handlers\TableHandler;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Tests\Support\ContentFixtures;
use Statamic\Fields\Field;

function simpleWalker(): ContentWalker
{
    return app(ContentWalker::class);
}

/**
 * @return list<array{0: string, 1: string, 2: string, 3: list<string|int>}>
 */
function simpleLeaves(Field $field, mixed $value, bool $target = false): array
{
    return array_map(
        fn (Leaf $leaf) => [$leaf->kind->value, $leaf->text, $leaf->label, $leaf->path],
        simpleWalker()->leaves($field, $value, $target),
    );
}

function simpleTranslate(Field $field, mixed $value): mixed
{
    return simpleWalker()->mapField($field, $value, fn (Leaf $leaf) => "[el] {$leaf->text}");
}

function keyedArrayField(mixed $keys): Field
{
    return new Field('facts', ['type' => 'array', 'display' => 'Facts', 'mode' => 'keyed', 'keys' => $keys]);
}

it('is registered for table, list and array at boot', function () {
    $registry = app(FieldtypeRegistry::class);

    expect($registry->for(ContentFixtures::field('table')))->toBeInstanceOf(TableHandler::class)
        ->and($registry->for(ContentFixtures::field('list')))->toBeInstanceOf(ListHandler::class)
        ->and($registry->for(ContentFixtures::field('array-dynamic')))->toBeInstanceOf(ArrayHandler::class);
});

it('returns an identical value when every leaf keeps its text', function (string $fixture, bool $target) {
    $field = ContentFixtures::field($fixture);
    $value = ContentFixtures::value($fixture);
    $texts = array_map(fn (Leaf $leaf) => $leaf->text, simpleWalker()->leaves($field, $value, $target));

    expect($texts)->not->toBe([])
        ->and(simpleWalker()->mapField($field, $value, fn (Leaf $leaf) => $leaf->text, $target))->toBe($value)
        ->and(simpleWalker()->inject($field, $value, $texts, $target))->toBe($value);
})->with(['table', 'list', 'array-dynamic', 'array-expanded', 'array-keyed'])->with(['source' => false, 'target' => true]);

it('offers each table cell with letters as a plain leaf, under its row and column', function () {
    expect(simpleLeaves(ContentFixtures::field('table'), ContentFixtures::value('table')))->toBe([
        ['plain', 'Season', 'Prices', ['table', 0, 'cells', 0]],
        ['plain', 'Garden double', 'Prices', ['table', 0, 'cells', 1]],
        ['plain', 'Sea view suite', 'Prices', ['table', 0, 'cells', 2]],
        ['plain', 'April – June', 'Prices', ['table', 1, 'cells', 0]],
        ['plain', 'July &amp; August', 'Prices', ['table', 2, 'cells', 0]],
        ['plain', 'Children &lt; 12', 'Prices', ['table', 3, 'cells', 0]],
        ['plain', '**Free**', 'Prices', ['table', 3, 'cells', 1]],
    ]);
});

it('translates only the cells that are leaves, and keeps the table shape', function () {
    $value = ContentFixtures::value('table');

    expect(simpleTranslate(ContentFixtures::field('table'), $value))->toBe([
        ['cells' => ['[el] Season', '[el] Garden double', '[el] Sea view suite']],
        ['cells' => ['[el] April – June', '€120', '€240']],
        ['cells' => ['[el] July & August', '€160', '€310']],
        ['cells' => ['[el] Children < 12', '[el] **Free**', '']],
    ]);
});

it('offers each string item of a list, and leaves numbers and identifiers untouched', function () {
    $field = ContentFixtures::field('list');
    $value = ContentFixtures::value('list');

    expect(simpleLeaves($field, $value))->toBe([
        ['plain', 'Sea view', 'Features', ['list', 0]],
        ['plain', 'Free Wi-Fi', 'Features', ['list', 1]],
        ['plain', 'Breakfast &amp; dinner', 'Features', ['list', 5]],
        ['plain', 'Airport transfer on request', 'Features', ['list', 6]],
    ])->and($translated = simpleTranslate($field, $value))->toBe([
        '[el] Sea view',
        '[el] Free Wi-Fi',
        42,
        3.5,
        'king-size',
        '[el] Breakfast & dinner',
        '[el] Airport transfer on request',
    ])->and($translated[2])->toBeInt()
        ->and($translated[3])->toBeFloat();
});

it('offers the values of a dynamic array labelled by their keys, and never touches a key', function () {
    $field = ContentFixtures::field('array-dynamic');
    $value = ContentFixtures::value('array-dynamic');

    expect(simpleLeaves($field, $value))->toBe([
        ['plain', 'From 15:00', 'Facts › check-in', ['array_dynamic', 'check-in']],
        ['plain', 'Until 11:00', 'Facts › check.out', ['array_dynamic', 'check.out']],
        ['plain', 'Free in every room', 'Facts › wifi', ['array_dynamic', 'wifi']],
        ['plain', 'Welcome in the garden rooms', 'Facts › Pets', ['array_dynamic', 'Pets']],
    ])->and($translated = simpleTranslate($field, $value))->toBe([
        'check-in' => '[el] From 15:00',
        'check.out' => '[el] Until 11:00',
        'wifi' => '[el] Free in every room',
        'Pets' => '[el] Welcome in the garden rooms',
        'floors' => '4',
    ])->and(array_keys($translated))->toBe(array_keys($value));
});

it('offers the values of an expanded array and keeps every key item as it was', function () {
    $field = ContentFixtures::field('array-expanded');
    $value = ContentFixtures::value('array-expanded');

    expect(simpleLeaves($field, $value))->toBe([
        ['plain', 'From 15:00', 'Opening hours › check-in', ['array_expanded', 0, 'value']],
        ['plain', 'Until 11:00', 'Opening hours › check.out', ['array_expanded', 1, 'value']],
        ['plain', 'Free for guests', 'Opening hours › parking', ['array_expanded', 2, 'value']],
    ])->and(simpleTranslate($field, $value))->toBe([
        ['key' => 'check-in', 'value' => '[el] From 15:00'],
        ['key' => 'check.out', 'value' => '[el] Until 11:00'],
        ['key' => 'parking', 'value' => '[el] Free for guests'],
        ['key' => 'floors', 'value' => '4'],
    ]);
});

it('labels the values of a keyed array with the labels of its keys', function () {
    $field = ContentFixtures::field('array-keyed');
    $value = ContentFixtures::value('array-keyed');

    expect(simpleLeaves($field, $value))->toBe([
        ['plain', 'All year', 'Facts › When', ['array_keyed', 'when']],
        ['plain', 'Old town, Chania', 'Facts › Where', ['array_keyed', 'where']],
        ['plain', '€10–30 per person', 'Facts › Price', ['array_keyed', 'price']],
    ])->and(simpleTranslate($field, $value))->toBe([
        'when' => '[el] All year',
        'where' => '[el] Old town, Chania',
        'price' => '[el] €10–30 per person',
    ]);
});

it('reads key labels in both config formats, and falls back to the key', function (mixed $keys) {
    $value = ['when' => 'All year', 'where' => 'Old town', 'price' => 'From ten euros', 'extra' => 'Not configured'];

    expect(array_column(simpleLeaves(keyedArrayField($keys), $value), 2))->toBe([
        'Facts › When', 'Facts › where', 'Facts › price', 'Facts › extra',
    ]);
})->with([
    'a map' => [['when' => 'When', 'where' => '', 'price' => null]],
    'a list of key items' => [[['key' => 'when', 'value' => 'When'], ['key' => 'where', 'value' => ' '], ['key' => 'price']]],
]);

it('labels a keyed expanded array by the labels of its keys', function () {
    $field = new Field('facts', ['type' => 'array', 'display' => 'Facts', 'mode' => 'keyed', 'expand' => true, 'keys' => ['when' => 'When']]);

    expect(simpleLeaves($field, [['key' => 'when', 'value' => 'All year'], ['value' => 'No key'], ['key' => ['x'], 'value' => 'An odd key']]))->toBe([
        ['plain', 'All year', 'Facts › When', ['facts', 0, 'value']],
        ['plain', 'No key', 'Facts', ['facts', 1, 'value']],
        ['plain', 'An odd key', 'Facts', ['facts', 2, 'value']],
    ]);
});

it('labels the values of an array whose keys are numbers', function () {
    $field = new Field('facts', ['type' => 'array', 'display' => 'Facts']);

    expect(simpleLeaves($field, ['Sea view', 7 => 'Free parking']))->toBe([
        ['plain', 'Sea view', 'Facts › 0', ['facts', 0]],
        ['plain', 'Free parking', 'Facts › 7', ['facts', 7]],
    ]);
});

it('walks a translated value into the same leaves, in the same order', function (string $fixture) {
    $field = ContentFixtures::field($fixture);
    $value = ContentFixtures::value($fixture);
    $source = simpleWalker()->leaves($field, $value);

    $target = simpleWalker()->leaves($field, simpleTranslate($field, $value), target: true);

    expect(array_map(fn (Leaf $leaf) => $leaf->text, $target))->toBe(array_map(fn (Leaf $leaf) => "[el] {$leaf->text}", $source))
        ->and(array_map(fn (Leaf $leaf) => $leaf->path, $target))->toBe(array_map(fn (Leaf $leaf) => $leaf->path, $source));
})->with(['table', 'list', 'array-dynamic', 'array-expanded', 'array-keyed']);

it('carries Antlers through as atoms', function (Field $field, mixed $value, mixed $translated) {
    $leaves = simpleWalker()->leaves($field, $value);

    expect($leaves)->toHaveCount(1)
        ->and($leaves[0]->text)->toBe('Rooms at <x1/>')
        ->and($leaves[0]->placeholders)->toBe(['x1' => '{{ site:name }}'])
        ->and(simpleWalker()->mapField($field, $value, fn (Leaf $leaf) => 'Δωμάτια στο <x1/>'))->toBe($translated);
})->with([
    'a table cell' => fn () => [ContentFixtures::field('table'), [['cells' => ['Rooms at {{ site:name }}', '{{ price }}']]], [['cells' => ['Δωμάτια στο {{ site:name }}', '{{ price }}']]]],
    'a list item' => fn () => [ContentFixtures::field('list'), ['Rooms at {{ site:name }}'], ['Δωμάτια στο {{ site:name }}']],
    'an array value' => fn () => [ContentFixtures::field('array-dynamic'), ['rooms' => 'Rooms at {{ site:name }}'], ['rooms' => 'Δωμάτια στο {{ site:name }}']],
]);

it('gives every leaf the field’s hint', function (string $type) {
    $field = new Field('facts', ['type' => $type, 'display' => 'Facts', 'tolk_hint' => '  Hotel amenities ']);
    $value = match ($type) {
        'table' => [['cells' => ['Sea view', 'Pool']]],
        'list' => ['Sea view', 'Pool'],
        'array' => ['view' => 'Sea view', 'pool' => 'Pool'],
    };

    expect(array_map(fn (Leaf $leaf) => $leaf->hint, simpleWalker()->leaves($field, $value)))->toBe(['Hotel amenities', 'Hotel amenities'])
        ->and(array_map(fn (Leaf $leaf) => $leaf->maxLength, simpleWalker()->leaves($field, $value)))->toBe([null, null]);
})->with(['table', 'list', 'array']);

it('leaves values of another shape untouched', function (Field $field, mixed $value) {
    expect(simpleLeaves($field, $value))->toBe([])
        ->and(simpleWalker()->mapField($field, $value, fn (Leaf $leaf) => 'X'))->toBe($value);
})->with([
    'a null table' => fn () => [ContentFixtures::field('table'), null],
    'a table string' => fn () => [ContentFixtures::field('table'), 'Season'],
    'a table map' => fn () => [ContentFixtures::field('table'), ['first' => ['cells' => ['Season']]]],
    'table rows without cells' => fn () => [ContentFixtures::field('table'), [['Season'], 'Season', null, ['cells' => 'Season']]],
    'table cells that are a map' => fn () => [ContentFixtures::field('table'), [['cells' => ['first' => 'Season']]]],
    'table cells that are no strings' => fn () => [ContentFixtures::field('table'), [['cells' => [12, null, true, ['Season']]]]],
    'a null list' => fn () => [ContentFixtures::field('list'), null],
    'a list string' => fn () => [ContentFixtures::field('list'), 'Sea view'],
    'a list map' => fn () => [ContentFixtures::field('list'), ['first' => 'Sea view']],
    'list items that are no strings' => fn () => [ContentFixtures::field('list'), [12, 3.5, null, false, ['Sea view']]],
    'an empty list' => fn () => [ContentFixtures::field('list'), []],
    'a null array' => fn () => [ContentFixtures::field('array-dynamic'), null],
    'an array string' => fn () => [ContentFixtures::field('array-dynamic'), 'From 15:00'],
    'an empty array' => fn () => [ContentFixtures::field('array-dynamic'), []],
    'array values that are no strings' => fn () => [ContentFixtures::field('array-dynamic'), ['floors' => 4, 'pets' => true, 'none' => null, 'nested' => ['Sea view']]],
    'expanded items without a string value' => fn () => [ContentFixtures::field('array-expanded'), [['key' => 'when'], ['key' => 'where', 'value' => null], ['key' => 'what', 'value' => ['All year']]]],
]);
