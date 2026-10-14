<?php

use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Content\Handlers\LangStringHandler;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Enums\SegmentKind;
use Statamic\Fields\Field;

function langStringField(string $key = 'booking.rooms_left'): Field
{
    return new Field($key, ['type' => 'text', 'display' => $key, 'tolk_handler' => LangStringHandler::HANDLE]);
}

/**
 * @return list<Leaf>
 */
function langStringLeaves(string $value): array
{
    return app(ContentWalker::class)->leaves(langStringField(), $value);
}

it('is the handler of a field that names it as tolk_handler', function () {
    $registry = app(FieldtypeRegistry::class);

    expect($registry->for(langStringField()))->toBeInstanceOf(LangStringHandler::class)
        ->and($registry->translates(langStringField()))->toBeTrue()
        ->and($registry->handles())->not->toContain(LangStringHandler::HANDLE);
});

it('makes parameters atoms in every case', function () {
    $leaves = langStringLeaves('Welcome back, :name! :Name and :NAME and :count_left.');

    expect($leaves)->toHaveCount(1)
        ->and($leaves[0]->kind)->toBe(SegmentKind::Plain)
        ->and($leaves[0]->text)->toBe('Welcome back, <x1/>! <x2/> and <x3/> and <x4/>.')
        ->and($leaves[0]->placeholders)->toBe(['x1' => ':name', 'x2' => ':Name', 'x3' => ':NAME', 'x4' => ':count_left'])
        ->and($leaves[0]->label)->toBe('booking.rooms_left')
        ->and($leaves[0]->hint)->toBeNull();
});

it('does not read times, URLs or a colon after a word as parameters', function (string $value) {
    expect(langStringLeaves($value)[0]->placeholders)->toBe([]);
})->with([
    'time' => ['Check-in from 10:30 until 12:00.'],
    'url' => ['Visit https://example.com/rooms for more.'],
    'colon after a word' => ['Note:read this first'],
    'colon before a space' => ['Opening hours: daily'],
]);

it('gives each variant of a pluralised string its own leaf and keeps the prefixes outside', function () {
    $leaves = langStringLeaves('{0} none|[1,*] :count items');

    expect(array_map(fn (Leaf $leaf) => [$leaf->text, $leaf->path, $leaf->placeholders], $leaves))->toBe([
        ['none', ['booking.rooms_left', 0], []],
        ['<x1/> items', ['booking.rooms_left', 1], ['x1' => ':count']],
    ])->and($leaves[0]->hint)->toBe('Part 1 of 2 of a string divided by |, used for the count {0}. Translate only this part, without a | character.')
        ->and($leaves[1]->hint)->toBe('Part 2 of 2 of a string divided by |, used for the count [1,*]. Translate only this part, without a | character.');
});

it('cuts every string at its |, but does not call a string without conditions pluralised', function () {
    $leaves = langStringLeaves('Blog | :site');

    expect(array_map(fn (Leaf $leaf) => [$leaf->text, $leaf->path, $leaf->hint, $leaf->pluralForm], $leaves))->toBe([
        ['Blog', ['booking.rooms_left', 0], 'Part 1 of 2 of a string divided by |. Translate only this part, without a | character.', true],
    ])->and(app(ContentWalker::class)->inject(langStringField(), 'Blog | :site', ['Ιστολόγιο']))->toBe('Ιστολόγιο | :site');
});

it('gives a string that is never pluralised one leaf, whatever its |', function () {
    $field = new Field('Rating | 1 to 5', ['type' => 'text', 'display' => 'Feedback › Rating', 'tolk_handler' => LangStringHandler::HANDLE, LangStringHandler::PLURAL => false]);
    $leaves = app(ContentWalker::class)->leaves($field, 'Rating | 1 to 5');

    expect(array_map(fn (Leaf $leaf) => [$leaf->text, $leaf->path, $leaf->hint, $leaf->pluralForm], $leaves))->toBe([
        ['Rating | 1 to 5', ['Rating | 1 to 5'], null, false],
    ])->and(app(ContentWalker::class)->inject($field, 'Rating | 1 to 5', ['Bewertung | 1 bis 5']))->toBe('Bewertung | 1 bis 5');
});

it('marks the leaves of a pluralised string as plural forms, whose translation must hold no |', function () {
    expect(array_map(fn (Leaf $leaf) => $leaf->pluralForm, langStringLeaves('{0} none|[1,*] :count items')))->toBe([true, true])
        ->and(langStringLeaves('Welcome back, :name!')[0]->pluralForm)->toBeFalse();
});

it('reassembles a pluralised string with its prefixes and parameters', function () {
    $field = langStringField();
    $walker = app(ContentWalker::class);

    expect($walker->inject($field, '{0} none|[1,*] :count items', ['keine', '<x1/> Artikel']))->toBe('{0} keine|[1,*] :count Artikel')
        ->and($walker->inject($field, '{0} No rooms|{1} One room|[2,*] :count rooms', ['Keine Zimmer', 'Ein Zimmer', '<x1/> Zimmer']))
        ->toBe('{0} Keine Zimmer|{1} Ein Zimmer|[2,*] :count Zimmer')
        ->and($walker->inject($field, 'apple|apples', ['Apfel', 'Äpfel']))->toBe('Apfel|Äpfel')
        ->and($walker->inject($field, '  Book now  ', ['Jetzt buchen']))->toBe('  Jetzt buchen  ');
});

it('returns the value itself when every leaf keeps its text, and leaves variants without text alone', function () {
    $walker = app(ContentWalker::class);
    $value = '{0} :count|[1,*] :count rooms';

    expect(langStringLeaves($value))->toHaveCount(1)
        ->and($walker->inject(langStringField(), $value, ['<x1/> rooms']))->toBe($value)
        ->and($walker->inject(langStringField(), $value, ['<x1/> Zimmer']))->toBe('{0} :count|[1,*] :count Zimmer')
        ->and(langStringLeaves(':count'))->toBe([])
        ->and(langStringLeaves('https://example.com'))->toBe([]);
});

it('leaves values that are not strings alone', function () {
    expect(app(ContentWalker::class)->leaves(langStringField(), ['a' => 'b']))->toBe([]);
});

it('escapes markup in the text and carries Antlers tags as atoms', function () {
    $leaf = langStringLeaves('Kids < 12 & {{ site:name }} for :name')[0];

    expect($leaf->text)->toBe('Kids &lt; 12 &amp; <x1/> for <x2/>')
        ->and($leaf->placeholders)->toBe(['x1' => '{{ site:name }}', 'x2' => ':name'])
        ->and(app(ContentWalker::class)->inject(langStringField(), 'Kids < 12 & {{ site:name }} for :name', ['Kinder &lt; 12 &amp; <x1/> für <x2/>']))
        ->toBe('Kinder < 12 & {{ site:name }} für :name');
});
