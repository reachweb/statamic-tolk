<?php

use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\Markup\Renumber;
use Reach\StatamicTolk\Content\Markup\Tokenizer;
use Reach\StatamicTolk\Enums\SegmentKind;

const RENUMBER_BOLD = '[{"type":"bold"}]';
const RENUMBER_ITALIC = '[{"type":"italic"}]';
const RENUMBER_LINK = '[{"attrs":{"href":"statamic://entry::rooms"},"type":"link"}]';

function renumberLeaf(string $text, array $placeholders, SegmentKind $kind = SegmentKind::Inline): Leaf
{
    return new Leaf($text, $kind, ['content', 0], 'Content', placeholders: $placeholders);
}

function tokenizedLeaf(string $text): Leaf
{
    $tokenized = Tokenizer::tokenize($text, SegmentKind::Plain);

    return renumberLeaf($tokenized->text, $tokenized->placeholders, SegmentKind::Plain);
}

it('gives two runs that the translation swapped the ids of the source', function () {
    $source = renumberLeaf('<1>Book</1> a <2>sea view suite</2>.', [1 => RENUMBER_BOLD, 2 => RENUMBER_LINK]);
    $target = renumberLeaf('Μια <1>σουίτα με θέα</1> <2>κλείστε</2>.', [1 => RENUMBER_LINK, 2 => RENUMBER_BOLD]);

    expect(Renumber::like($target, $source))->toBe('Μια <2>σουίτα με θέα</2> <1>κλείστε</1>.');
});

it('assigns identical descriptors in order', function () {
    $source = renumberLeaf('<1>a</1> <2>b</2> <3>c</3>', [1 => RENUMBER_BOLD, 2 => RENUMBER_ITALIC, 3 => RENUMBER_BOLD]);
    $target = renumberLeaf('<1>β</1> <2>α</2> <3>γ</3>', [1 => RENUMBER_ITALIC, 2 => RENUMBER_BOLD, 3 => RENUMBER_BOLD]);

    expect(Renumber::like($target, $source))->toBe('<2>β</2> <1>α</1> <3>γ</3>');
});

it('renumbers every occurrence of a split run and leaves breaks alone', function () {
    $source = renumberLeaf('<1>Free parking</1> for guests.<br/><2>Call</2> us.', [1 => RENUMBER_BOLD, 2 => RENUMBER_LINK]);
    $target = renumberLeaf('<1>Καλέστε</1> μας.<br/>Δωρεάν <2>στάθμευση</2> για <2>επισκέπτες</2>.', [1 => RENUMBER_LINK, 2 => RENUMBER_BOLD]);

    expect(Renumber::like($target, $source))->toBe('<2>Καλέστε</2> μας.<br/>Δωρεάν <1>στάθμευση</1> για <1>επισκέπτες</1>.');
});

it('matches atoms by descriptor and never pairs an atom with a paired tag', function () {
    $source = renumberLeaf('<1>Spa</1> <x1/> <x2/>', [1 => '<b>', 'x1' => '<b>', 'x2' => '<img src="a.jpg">'], SegmentKind::Html);
    $target = renumberLeaf('<x1/> <x2/> <1>Σπα</1>', ['x1' => '<img src="a.jpg">', 'x2' => '<b>', 1 => '<b>'], SegmentKind::Html);

    expect(Renumber::like($target, $source))->toBe('<x2/> <x1/> <1>Σπα</1>');
});

it('renumbers the Antlers atoms of plain leaves', function () {
    $source = tokenizedLeaf('Welcome to {{ site:name }} in {{ city }}');
    $target = tokenizedLeaf('Στην πόλη {{ city }}, καλώς ήρθατε στο {{ site:name }}');

    expect(Renumber::like($target, $source))->toBe('Στην πόλη <x2/>, καλώς ήρθατε στο <x1/>');
});

it('gives null for a placeholder with no counterpart in the source', function () {
    $source = renumberLeaf('<1>Book</1> now', [1 => RENUMBER_BOLD]);

    expect(Renumber::like(renumberLeaf('<1>Κλείστε</1> τώρα', [1 => RENUMBER_ITALIC]), $source))->toBeNull()
        ->and(Renumber::like(renumberLeaf('<1>Κλείστε</1> <2>τώρα</2>', [1 => RENUMBER_BOLD, 2 => RENUMBER_BOLD]), $source))->toBeNull()
        ->and(Renumber::like(renumberLeaf('<1>Κλείστε</1> <3>τώρα</3>', [1 => RENUMBER_BOLD]), $source))->toBeNull()
        ->and(Renumber::like(tokenizedLeaf('Γεια {{ name }}'), tokenizedLeaf('Hello {{ title }}')))->toBeNull();
});

it('keeps a target with fewer placeholders than the source', function () {
    $source = renumberLeaf('<1>Book</1> a <2>suite</2>', [1 => RENUMBER_BOLD, 2 => RENUMBER_LINK]);

    expect(Renumber::like(renumberLeaf('Κλείστε μια <1>σουίτα</1>', [1 => RENUMBER_LINK]), $source))->toBe('Κλείστε μια <2>σουίτα</2>')
        ->and(Renumber::like(renumberLeaf('Κλείστε', []), $source))->toBe('Κλείστε');
});

it('turns the renumbered text back into the target numbering', function (Leaf $target, Leaf $source) {
    expect(Renumber::back(Renumber::like($target, $source), $target, $source))->toBe($target->text);
})->with([
    'swapped runs' => fn () => [
        renumberLeaf('Μια <1>σουίτα</1> <2>κλείστε</2> <x1/>.<br/>', [1 => RENUMBER_LINK, 2 => RENUMBER_BOLD, 'x1' => '{"type":"image"}']),
        renumberLeaf('<1>Book</1> a <2>suite</2> <x1/>.<br/>', [1 => RENUMBER_BOLD, 2 => RENUMBER_LINK, 'x1' => '{"type":"image"}']),
    ],
    'identical descriptors' => fn () => [
        renumberLeaf('<1>β</1> <2>α</2> <3>γ</3>', [1 => RENUMBER_ITALIC, 2 => RENUMBER_BOLD, 3 => RENUMBER_BOLD]),
        renumberLeaf('<1>a</1> <2>b</2> <3>c</3>', [1 => RENUMBER_BOLD, 2 => RENUMBER_ITALIC, 3 => RENUMBER_BOLD]),
    ],
    'antlers atoms' => fn () => [
        tokenizedLeaf('{{ b }} και {{ a }} & {{ a }}'),
        tokenizedLeaf('{{ a }} and {{ b }} & {{ a }}'),
    ],
]);

it('expresses a correction in the source numbering in the target numbering', function () {
    $source = renumberLeaf('<1>Book</1> a <2>suite</2>.', [1 => RENUMBER_BOLD, 2 => RENUMBER_LINK]);
    $target = renumberLeaf('Μια <1>σουίτα</1> <2>κλείστε</2>.', [1 => RENUMBER_LINK, 2 => RENUMBER_BOLD]);

    expect(Renumber::back('Μια <2>σουίτα</2> <1>κλείστε τώρα</1>.', $target, $source))->toBe('Μια <1>σουίτα</1> <2>κλείστε τώρα</2>.')
        ->and(Renumber::back('Μια <3>σουίτα</3>.', $target, $source))->toBeNull()
        ->and(Renumber::back('Text', renumberLeaf('<1>x</1>', [1 => RENUMBER_ITALIC]), $source))->toBeNull();
});

it('gives back null for a source placeholder the target does not have', function () {
    $source = renumberLeaf('<1>Book</1> a <2>suite</2>', [1 => RENUMBER_BOLD, 2 => RENUMBER_LINK]);
    $target = renumberLeaf('Κλείστε μια <1>σουίτα</1>', [1 => RENUMBER_LINK]);

    expect(Renumber::back('<1>Κλείστε</1> μια <2>σουίτα</2>', $target, $source))->toBeNull();
});
