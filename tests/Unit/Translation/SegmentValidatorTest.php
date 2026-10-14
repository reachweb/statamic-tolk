<?php

use Reach\StatamicTolk\Translation\SegmentValidator;

function validatorSegment(string $text, ?int $maxLength = null, string $kind = 'plain'): array
{
    return ['text' => $text, 'kind' => $kind, 'max_length' => $maxLength];
}

it('accepts a valid translation', function () {
    expect((new SegmentValidator)->validate(validatorSegment('Call <x1/> <1>today</1>.', 30), 'Rufen Sie <1>heute</1> <x1/> an.'))->toBeNull();
});

it('names a placeholder problem', function () {
    $validator = new SegmentValidator;
    $segment = validatorSegment('Call <x1/> today.');

    expect($validator->validate($segment, 'Rufen Sie heute an.'))->toBe('<x1/> is missing.')
        ->and($validator->placeholders($segment, 'Rufen Sie heute an.'))->toBe('<x1/> is missing.')
        ->and($validator->length($segment, 'Rufen Sie heute an.'))->toBeNull();
});

it('names a translation over its max_length', function () {
    $validator = new SegmentValidator;

    expect($validator->validate(validatorSegment('Hello', 10), 'Guten Morgen'))->toBe('The translation has 12 characters; it must not exceed 10.')
        ->and($validator->placeholders(validatorSegment('Hello', 10), 'Guten Morgen'))->toBeNull()
        ->and($validator->validate(validatorSegment('Hello'), str_repeat('a', 100)))->toBeNull();
});

it('counts what a reader sees: placeholders do not count and an escaped character counts once', function () {
    $validator = new SegmentValidator;

    expect($validator->length(validatorSegment('<1>A</1> &amp; <x1/>B', 5), '<1>Ab</1> &amp; <x1/>'))->toBeNull()
        ->and($validator->length(validatorSegment('<1>A</1> &amp; <x1/>B', 4), '<1>Ab</1> &amp; <x1/>'))->toBe('The translation has 5 characters; it must not exceed 4.')
        ->and($validator->length(validatorSegment('A &amp; B', 5, 'markdown'), 'A &amp; B'))->toBe('The translation has 9 characters; it must not exceed 5.')
        ->and($validator->length(validatorSegment('<1>A &amp; B</1>', 5, 'html'), '<1>A &amp; B</1><x1/>'))->toBeNull();
});

it('rejects a | in the translation of a plural form, which would add a form, and accepts one elsewhere', function () {
    $validator = new SegmentValidator;
    $form = [...validatorSegment('Many apples'), 'plural_form' => true];
    $note = 'The translation contains |, which divides this string into its parts. Translate only this part, without a | character.';

    expect($validator->validate($form, 'Viele Äpfel | Stück'))->toBe($note)
        ->and($validator->structure($form, 'Viele Äpfel | Stück'))->toBe($note)
        ->and($validator->validate($form, 'Viele Äpfel'))->toBeNull()
        ->and($validator->validate(validatorSegment('Hello | Example'), 'Hallo | Beispiel'))->toBeNull()
        ->and($validator->validate([...validatorSegment('Many apples'), 'plural_form' => false], 'Viele | Äpfel'))->toBeNull();
});

it('names both problems in one note', function () {
    expect((new SegmentValidator)->validate(validatorSegment('Call <x1/>.', 5), 'Rufen Sie an.'))
        ->toBe('<x1/> is missing. The translation has 13 characters; it must not exceed 5.');
});
