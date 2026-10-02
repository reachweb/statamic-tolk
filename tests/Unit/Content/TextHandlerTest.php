<?php

use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Content\Handlers\TextHandler;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Enums\SegmentKind;
use Reach\StatamicTolk\Tests\Support\Handlers\FakeGroupHandler;
use Statamic\Fields\Field;

function textWalker(): ContentWalker
{
    return app(ContentWalker::class);
}

function marked(Leaf $leaf): string
{
    return "[el] {$leaf->text}";
}

it('is registered for text and textarea at boot', function () {
    $registry = app(FieldtypeRegistry::class);

    expect($registry->for(new Field('title', ['type' => 'text'])))->toBeInstanceOf(TextHandler::class)
        ->and($registry->for(new Field('intro', ['type' => 'textarea'])))->toBeInstanceOf(TextHandler::class);
});

it('returns an identical value when the leaf keeps its text', function (string $type, string $value) {
    $field = new Field('intro', ['type' => $type]);
    $leaves = textWalker()->leaves($field, $value);

    expect($leaves)->toHaveCount(1)
        ->and(textWalker()->mapField($field, $value, fn (Leaf $leaf) => $leaf->text))->toBe($value)
        ->and(textWalker()->inject($field, $value, [$leaves[0]->text]))->toBe($value);
})->with(['text', 'textarea'])->with([
    'plain' => 'Rooms by the sea',
    'antlers' => 'Welcome to {{ site:name }}, {{ user:name }}!',
    'literal characters' => 'Kids < 12 & seniors > 65 stay free',
    'entities' => 'Tom &amp; Jerry &lt;3',
    'surrounding whitespace' => "  Spaced out\n",
    'windows line breaks' => "Line one\r\nLine two",
]);

it('builds one plain leaf with the path, label and placeholders', function () {
    $field = new Field('intro', ['type' => 'textarea', 'display' => 'Introduction']);

    $leaves = textWalker()->leaves($field, 'Hi {{ name }} & welcome');

    expect($leaves)->toHaveCount(1)
        ->and($leaves[0]->text)->toBe('Hi <x1/> &amp; welcome')
        ->and($leaves[0]->kind)->toBe(SegmentKind::Plain)
        ->and($leaves[0]->path)->toBe(['intro'])
        ->and($leaves[0]->label)->toBe('Introduction')
        ->and($leaves[0]->hint)->toBeNull()
        ->and($leaves[0]->maxLength)->toBeNull()
        ->and($leaves[0]->placeholders)->toBe(['x1' => '{{ name }}']);
});

it('takes the label trail and path of a nested field from the context', function () {
    $registry = new FieldtypeRegistry(app());
    $registry->register('text', TextHandler::class);
    $registry->register('group', FakeGroupHandler::class);
    $field = new Field('hero', ['type' => 'group', 'display' => 'Hero', 'fields' => [
        ['handle' => 'heading', 'field' => ['type' => 'text', 'display' => 'Heading']],
    ]]);

    $leaves = (new ContentWalker($registry))->leaves($field, ['heading' => 'Welcome']);

    expect($leaves)->toHaveCount(1)
        ->and($leaves[0]->path)->toBe(['hero', 'heading'])
        ->and($leaves[0]->label)->toBe('Hero › Heading');
});

it('passes the character limit and the translation hint to the leaf', function () {
    $field = new Field('seo_title', ['type' => 'text', 'character_limit' => 60, 'tolk_hint' => '  Keep the brand name in English ']);

    $leaf = textWalker()->leaves($field, 'Sea view rooms')[0];

    expect($leaf->maxLength)->toBe(60)
        ->and($leaf->hint)->toBe('Keep the brand name in English');
});

it('ignores a character limit or hint that is empty', function (mixed $limit, mixed $hint) {
    $field = new Field('seo_title', ['type' => 'textarea', 'character_limit' => $limit, 'tolk_hint' => $hint]);

    $leaf = textWalker()->leaves($field, 'Sea view rooms')[0];

    expect($leaf->maxLength)->toBeNull()
        ->and($leaf->hint)->toBeNull();
})->with([
    'null' => [null, null],
    'zero and empty' => [0, ''],
    'blank strings' => ['', '   '],
]);

it('reads a character limit stored as a string', function () {
    $leaf = textWalker()->leaves(new Field('title', ['type' => 'text', 'character_limit' => '70']), 'Sea view rooms')[0];

    expect($leaf->maxLength)->toBe(70);
});

it('restores Antlers tags and literal characters in a marked translation', function () {
    $field = new Field('intro', ['type' => 'text']);

    expect(textWalker()->mapField($field, 'Welcome to {{ site:name }} & {{# note #}}enjoy < 5 km', marked(...)))
        ->toBe('[el] Welcome to {{ site:name }} & {{# note #}}enjoy < 5 km');
});

it('restores Antlers tags that the translation moved', function () {
    $field = new Field('intro', ['type' => 'textarea']);

    expect(textWalker()->inject($field, '{{ name }} welcomes you to {{ city }}', ['Το <x2/> σας καλωσορίζει, <x1/>']))
        ->toBe('Το {{ city }} σας καλωσορίζει, {{ name }}');
});

it('leaves a field whose input type is not text untouched', function (string $inputType, string $value) {
    $field = new Field('contact', ['type' => 'text', 'input_type' => $inputType]);

    expect(textWalker()->leaves($field, $value))->toBe([])
        ->and(textWalker()->mapField($field, $value, marked(...)))->toBe($value);
})->with([
    'email' => ['email', 'Reservations desk'],
    'url' => ['url', 'Our booking page'],
    'tel' => ['tel', 'Front desk'],
    'number' => ['number', 'Twelve'],
]);

it('translates a text field whose input type is set to text', function () {
    $field = new Field('title', ['type' => 'text', 'input_type' => 'text']);

    expect(textWalker()->mapField($field, 'Home', marked(...)))->toBe('[el] Home');
});

it('leaves values that are not strings untouched', function (mixed $value) {
    $field = new Field('title', ['type' => 'text']);

    expect(textWalker()->leaves($field, $value))->toBe([])
        ->and(textWalker()->mapField($field, $value, marked(...)))->toBe($value);
})->with([
    'null' => null,
    'an integer' => 2026,
    'a float' => 1.5,
    'a boolean' => true,
    'an array' => [['text' => 'Home']],
]);

it('leaves strings that the leaf rules reject untouched', function (string $value) {
    $field = new Field('anchor', ['type' => 'text']);
    $walker = textWalker();

    expect($walker->leaves($field, $value))->toBe([])
        ->and($walker->warnings())->toBe([])
        ->and($walker->mapField($field, $value, marked(...)))->toBe($value);
})->with(['', '   ', '2026', 'https://example.com', '/contact', 'text-center', '{{ partial:hero }}']);

it('leaves a string with HTML untouched and warns, naming the field', function (string $value) {
    $field = new Field('intro', ['type' => 'textarea', 'display' => 'Introduction']);
    $walker = textWalker();

    expect($walker->leaves($field, $value))->toBe([])
        ->and($walker->warnings())->toBe(['Introduction (intro) contains HTML and was left untouched.'])
        ->and($walker->mapField($field, $value, marked(...)))->toBe($value);
})->with([
    'an element' => 'Rooms <strong>by the sea</strong>',
    'a void tag' => 'Line one<br>Line two',
    'a self-closing tag' => 'Line one<br />Line two',
    'a tag with attributes' => 'Read <a href="/rooms" class="link">more</a>',
    'a comment' => 'Visible <!-- hidden --> text',
]);

it('does not mistake comparisons or Antlers for HTML', function (string $value) {
    $field = new Field('intro', ['type' => 'textarea']);
    $walker = textWalker();

    expect($walker->leaves($field, $value))->toHaveCount(1)
        ->and($walker->warnings())->toBe([]);
})->with([
    'spaced comparisons' => 'Kids < 12 & seniors > 65 stay free',
    'an unclosed angle bracket' => 'Prices from <100 euros',
    'html inside an antlers tag' => 'Hello {{ name | wrap("<b>") }} there',
]);
