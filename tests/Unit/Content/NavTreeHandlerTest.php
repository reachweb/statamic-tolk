<?php

use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Content\Handlers\NavTreeHandler;
use Reach\StatamicTolk\Content\Leaf;
use Statamic\Fields\Field;

function navTreeField(array $fields = []): Field
{
    return new Field('tree', ['type' => 'yaml', 'display' => 'Main', 'tolk_handler' => NavTreeHandler::HANDLE, 'fields' => $fields]);
}

function navTreeValue(): array
{
    return [
        ['id' => 'about', 'entry' => 'entry-about'],
        ['id' => 'stay', 'title' => 'Stay with us', 'children' => [
            ['id' => 'suites', 'entry' => 'entry-suites', 'title' => 'Our suites', 'data' => ['badge' => 'New', 'icon' => 'Calendar icon']],
            ['id' => 'map', 'title' => 'Map', 'url' => '/map'],
        ]],
        ['id' => 'book', 'title' => 'Book your stay', 'url' => 'https://booking.example', 'data' => ['badge' => 'Open now']],
    ];
}

function navTreeFields(): array
{
    return [
        ['handle' => 'badge', 'field' => ['type' => 'text', 'display' => 'Badge']],
        ['handle' => 'icon', 'field' => ['type' => 'text', 'display' => 'Icon', 'tolk_translate' => false]],
    ];
}

/**
 * @return list<array{0: string, 1: string, 2: string}> text, path and label of each leaf
 */
function navTreeLeaves(Field $field, mixed $value, bool $target = false): array
{
    return array_map(
        fn (Leaf $leaf) => [$leaf->text, implode('.', $leaf->path), $leaf->label],
        app(ContentWalker::class)->leaves($field, $value, $target),
    );
}

it('is the handler of a field that names it as tolk_handler, whatever its type', function () {
    $registry = app(FieldtypeRegistry::class);

    expect($registry->for(navTreeField()))->toBeInstanceOf(NavTreeHandler::class)
        ->and($registry->translates(navTreeField()))->toBeTrue()
        ->and($registry->for(new Field('tree', ['type' => 'yaml'])))->toBeNull()
        ->and($registry->for(new Field('title', ['type' => 'text', 'tolk_handler' => 'unknown'])))->not->toBeInstanceOf(NavTreeHandler::class)
        ->and($registry->handles())->not->toContain(NavTreeHandler::HANDLE);
});

it('walks titles and the data fields depth-first, leaving ids, entries, urls and untranslated fields alone', function () {
    expect(navTreeLeaves(navTreeField(navTreeFields()), navTreeValue()))->toBe([
        ['Stay with us', 'tree.1.title', 'Main › Title'],
        ['Our suites', 'tree.1.children.0.title', 'Main › Title'],
        ['New', 'tree.1.children.0.data.badge', 'Main › Badge'],
        ['Map', 'tree.1.children.1.title', 'Main › Title'],
        ['Book your stay', 'tree.2.title', 'Main › Title'],
        ['Open now', 'tree.2.data.badge', 'Main › Badge'],
    ]);
});

it('injects translations in walk order and returns the value itself when nothing changes', function () {
    $field = navTreeField(navTreeFields());
    $value = navTreeValue();
    $walker = app(ContentWalker::class);
    $texts = array_column(navTreeLeaves($field, $value), 0);

    $translated = $walker->inject($field, $value, array_map(fn (string $text) => "[el] {$text}", $texts));

    expect($walker->inject($field, $value, $texts))->toBe($value)
        ->and($translated[0])->toBe($value[0])
        ->and($translated[1]['title'])->toBe('[el] Stay with us')
        ->and($translated[1]['children'][0])->toBe(['id' => 'suites', 'entry' => 'entry-suites', 'title' => '[el] Our suites', 'data' => ['badge' => '[el] New', 'icon' => 'Calendar icon']])
        ->and($translated[1]['children'][1])->toBe(['id' => 'map', 'title' => '[el] Map', 'url' => '/map'])
        ->and($translated[2])->toBe(['id' => 'book', 'title' => '[el] Book your stay', 'url' => 'https://booking.example', 'data' => ['badge' => '[el] Open now']]);
});

it('leaves data out without a blueprint field for it, and a title that is no text', function () {
    $value = [
        ['id' => 'a', 'title' => 'https://example.com', 'data' => ['badge' => 'New']],
        ['id' => 'b', 'title' => '2026', 'children' => [['id' => 'c', 'title' => 'Contact']]],
    ];

    expect(navTreeLeaves(navTreeField(), $value))->toBe([['Contact', 'tree.1.children.0.title', 'Main › Title']]);
});

it('takes the hint of a title field in the nav blueprint, and leaves titles alone when it is not translated', function () {
    $value = [['id' => 'a', 'title' => 'Book your stay']];
    $hinted = navTreeField([['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Label', 'tolk_hint' => 'A menu label, keep it short']]]);
    $off = navTreeField([['handle' => 'title', 'field' => ['type' => 'text', 'tolk_translate' => false]]]);
    $leaves = app(ContentWalker::class)->leaves($hinted, $value);

    expect(array_map(fn (Leaf $leaf) => [$leaf->text, $leaf->label, $leaf->hint], $leaves))->toBe([['Book your stay', 'Main › Label', 'A menu label, keep it short']])
        ->and(navTreeLeaves($off, $value))->toBe([]);
});

it('leaves a value that is not a list of branches alone', function (mixed $value) {
    expect(app(ContentWalker::class)->leaves(navTreeField(), $value))->toBe([]);
})->with([
    'null' => [null],
    'string' => ['Main'],
    'map' => [['title' => 'Main']],
]);

it('leaves out a data field that holds its default on the target side only', function () {
    $field = navTreeField([['handle' => 'badge', 'field' => ['type' => 'text', 'default' => 'New']]]);
    $value = [['id' => 'a', 'title' => 'Rooms', 'data' => ['badge' => 'New']]];

    expect(array_column(navTreeLeaves($field, $value), 0))->toBe(['Rooms', 'New'])
        ->and(array_column(navTreeLeaves($field, $value, target: true), 0))->toBe(['Rooms']);
});
