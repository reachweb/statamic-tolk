<?php

use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Content\Handlers\TextHandler;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Enums\SegmentKind;
use Reach\StatamicTolk\Tests\Support\ContentFixtures;
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

it('builds one html leaf for a string with HTML, without a warning', function () {
    $field = new Field('intro', ['type' => 'textarea', 'display' => 'Introduction', 'character_limit' => 300]);
    $walker = textWalker();

    $leaves = $walker->leaves($field, '<p>Rooms <a href="{{ link to="sea" }}">by the sea</a>.<br>{{ site:name }}</p>');

    expect($leaves)->toHaveCount(1)
        ->and($walker->warnings())->toBe([])
        ->and($leaves[0]->text)->toBe('<1>Rooms <2>by the sea</2>.<x1/><x2/></1>')
        ->and($leaves[0]->kind)->toBe(SegmentKind::Html)
        ->and($leaves[0]->path)->toBe(['intro'])
        ->and($leaves[0]->label)->toBe('Introduction')
        ->and($leaves[0]->maxLength)->toBe(300)
        ->and($leaves[0]->placeholders)->toBe([1 => '<p>', 2 => '<a href="{{ link to="sea" }}">', 'x1' => '<br>', 'x2' => '{{ site:name }}']);
});

it('makes an html leaf of every kind of HTML and returns the value identical when it keeps its text', function (string $type, string $value) {
    $field = new Field('intro', ['type' => $type]);
    $walker = textWalker();
    $leaves = $walker->leaves($field, $value);

    expect($leaves)->toHaveCount(1)
        ->and($leaves[0]->kind)->toBe(SegmentKind::Html)
        ->and($walker->warnings())->toBe([])
        ->and($walker->mapField($field, $value, fn (Leaf $leaf) => $leaf->text))->toBe($value)
        ->and($walker->inject($field, $value, [$leaves[0]->text]))->toBe($value);
})->with(['text', 'textarea'])->with([
    'an element' => 'Rooms <strong>by the sea</strong>',
    'a void tag' => 'Line one<br>Line two',
    'a self-closing tag' => 'Line one<br />Line two',
    'a tag with attributes' => 'Read <a href="/rooms" class="link">more</a>',
    'a comment' => 'Visible <!-- hidden --> text',
    'literal characters and entities' => '<p>Kids < 12 &amp; seniors &gt; 65</p>',
]);

it('restores the tags of a marked translation of the textarea-html fixture', function () {
    $field = ContentFixtures::field('textarea-html');
    $value = ContentFixtures::value('textarea-html');

    expect(textWalker()->leaves($field, $value)[0]->text)
        ->toBe("<1>Our <2>spa</2> opens at <3>9:00</3> every day.</1>\n<4>Book a treatment at <5>the front desk</5>.<x1/>Kids &lt; 12 swim free.</4>\n<x2/>")
        ->and(textWalker()->inject($field, $value, ["<1>Το <2>σπα</2> μας ανοίγει στις <3>9:00</3> κάθε μέρα.</1>\n<4>Κλείστε μια θεραπεία στη <5>ρεσεψιόν</5>.<x1/>Παιδιά &lt; 12 κολυμπούν δωρεάν.</4>\n<x2/>"]))
        ->toBe("<p>Το <strong>σπα</strong> μας ανοίγει στις <em>9:00</em> κάθε μέρα.</p>\n<p>Κλείστε μια θεραπεία στη <a href=\"/spa\" title=\"Spa bookings\">ρεσεψιόν</a>.<br>Παιδιά &lt; 12 κολυμπούν δωρεάν.</p>\n<!-- Opening hours checked in March 2026 -->");
});

it('leaves HTML whose visible text the leaf rules reject untouched, without a warning', function (string $value) {
    $field = new Field('intro', ['type' => 'textarea']);
    $walker = textWalker();

    expect($walker->leaves($field, $value))->toBe([])
        ->and($walker->warnings())->toBe([])
        ->and($walker->mapField($field, $value, marked(...)))->toBe($value);
})->with([
    'only an image' => '<img src="/img/pool.jpg" alt="The pool">',
    'only a comment' => '<!-- Rooms by the sea -->',
    'only a script' => '<script>document.write("Rooms")</script>',
    'a linked URL' => '<a href="https://kyma-bay.example">https://kyma-bay.example</a>',
    'an entity' => '<p>&amp;</p>',
    'tags around Antlers' => '<p>{{ partial:hero }}</p>',
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
