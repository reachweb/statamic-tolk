<?php

use Reach\StatamicTolk\Content\Markup\Tokenized;
use Reach\StatamicTolk\Content\Markup\Tokenizer;
use Reach\StatamicTolk\Enums\SegmentKind;

it('restores what it tokenized', function (string $text) {
    $tokenized = Tokenizer::tokenize($text, SegmentKind::Plain);

    expect(Tokenizer::restore($tokenized->text, $tokenized))->toBe($text);
})->with([
    'plain text' => 'Rooms by the sea',
    'empty' => '',
    'unicode' => 'Καλημέρα από τα Χανιά — €30',
    'antlers tag' => 'Welcome to {{ site:name }}!',
    'antlers tags back to back' => '{{ first }}{{ second }}',
    'antlers with modifiers and quotes' => 'Call {{ phone | upper }} or "{{ email }}"',
    'antlers comment' => 'Open {{# seasonal hours #}} daily',
    'multi-line comment containing a tag' => "Before {{# note:\n{{ old }} was removed\n#}} after",
    'multi-line tag' => "Hello {{ partial:card\n    title=\"Spa\" }} there",
    'escaped antlers' => 'Write @{{ title }} to show the title',
    'php block' => 'Total: {{? echo "}}"; ?}} euros',
    'literal less-than and ampersand' => 'Kids < 12 & seniors > 65 stay free',
    'entities already in the text' => 'Tom &amp; Jerry &lt;3 &gt;',
    'text that looks like a placeholder' => 'Type <x1/> or <1>bold</1> or <br/> here',
    'tags and escapes mixed' => 'A < B {{ if x < y }} & {{ /if }} <b>bold</b>',
    'unclosed antlers' => 'Price {{ amount',
    'windows line breaks' => "Line one\r\nLine two {{ tag }}",
]);

it('turns every Antlers tag into a numbered atom whose descriptor is its text', function () {
    $tokenized = Tokenizer::tokenize("Hi {{ name }}, {{# note #}}see {{ partial:x\n}} & {{ name }}", SegmentKind::Plain);

    expect($tokenized)->toBeInstanceOf(Tokenized::class)
        ->and($tokenized->kind)->toBe(SegmentKind::Plain)
        ->and($tokenized->text)->toBe('Hi <x1/>, <x2/>see <x3/> &amp; <x4/>')
        ->and($tokenized->map)->toBe([
            'x1' => '{{ name }}',
            'x2' => '{{# note #}}',
            'x3' => "{{ partial:x\n}}",
            'x4' => '{{ name }}',
        ])
        ->and($tokenized->placeholders)->toBe($tokenized->map);
});

it('keeps comments and PHP blocks whole, and the @ with the tag it escapes', function () {
    expect(Tokenizer::tokenize('{{# a }} b #}} {{? echo "}}"; ?}} @{{ c }}', SegmentKind::Plain)->map)->toBe([
        'x1' => '{{# a }} b #}}',
        'x2' => '{{? echo "}}"; ?}}',
        'x3' => '@{{ c }}',
    ]);
});

it('matches Antlers tags non-greedily', function () {
    expect(Tokenizer::tokenize('{{ a }} and {{ b }}', SegmentKind::Plain)->map)->toBe(['x1' => '{{ a }}', 'x2' => '{{ b }}']);
});

it('escapes literal <, > and & outside the atoms', function () {
    $tokenized = Tokenizer::tokenize('Kids < 12 & <b>teens</b> {{ if a > b }}', SegmentKind::Plain);

    expect($tokenized->text)->toBe('Kids &lt; 12 &amp; &lt;b&gt;teens&lt;/b&gt; <x1/>')
        ->and($tokenized->map)->toBe(['x1' => '{{ if a > b }}']);
});

it('leaves text without Antlers or special characters as it is', function () {
    $tokenized = Tokenizer::tokenize('Check-in from 14:00', SegmentKind::Plain);

    expect($tokenized->text)->toBe('Check-in from 14:00')
        ->and($tokenized->map)->toBe([])
        ->and($tokenized->placeholders)->toBe([]);
});

it('restores a translation with reordered atoms and unescapes it', function () {
    $tokenized = Tokenizer::tokenize('{{ name }} & {{ city }} < 5 km', SegmentKind::Plain);

    expect(Tokenizer::restore('<x2/> &lt; 5 χλμ &amp; <x1/>', $tokenized))->toBe('{{ city }} < 5 χλμ & {{ name }}')
        ->and(Tokenizer::restore('&amp;lt; stays &amp;amp;', $tokenized))->toBe('&lt; stays &amp;');
});

it('leaves an atom it does not know in place', function () {
    $tokenized = Tokenizer::tokenize('Hi {{ name }}', SegmentKind::Plain);

    expect(Tokenizer::restore('Γεια <x1/> <x7/>', $tokenized))->toBe('Γεια {{ name }} <x7/>');
});

it('rejects the kinds that arrive later', function (SegmentKind $kind) {
    Tokenizer::tokenize('Text', $kind);
})->with([SegmentKind::Inline])->throws(InvalidArgumentException::class);
