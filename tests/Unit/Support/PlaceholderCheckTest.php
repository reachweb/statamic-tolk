<?php

use Reach\StatamicTolk\Tests\Support\PlaceholderCheck;

$source = '<1>Check-in</1> starts at <2>15:00</2>.<br/>See the map<x1/>.';

it('accepts a translation that keeps every placeholder', function (string $syntax) use ($source) {
    $translation = PlaceholderCheck::convert('Το <1>check-in</1> ξεκινά στις <2>15:00</2>.<br/>Δείτε τον χάρτη<x1/>.', $syntax);

    expect(PlaceholderCheck::problems(PlaceholderCheck::convert($source, $syntax), $translation, $syntax))->toBe([]);
})->with(['numeric', 'g']);

it('accepts reordered pairs and a run split into two occurrences', function () use ($source) {
    $translation = 'Στις <2>15:00</2> ξεκινά το <1>check</1>-<1>in</1>.<br/>Δείτε τον χάρτη<x1/>.';

    expect(PlaceholderCheck::problems($source, $translation, 'numeric'))->toBe([]);
});

it('reports what breaks §A3', function (string $translation, array $problems) use ($source) {
    expect(PlaceholderCheck::problems($source, $translation, 'numeric'))->toBe($problems);
})->with([
    'empty' => ['  ', ['empty']],
    'missing pair' => ['Το check-in ξεκινά στις <2>15:00</2>.<br/>Δείτε<x1/>.', ['pair 1 missing']],
    'unknown id' => ['<1>Check-in</1> <3>στις</3> <2>15:00</2>.<br/>Δείτε<x1/>.', ['pair 3 not in the source']],
    'empty pair' => ['<1> </1> στις <2>15:00</2>.<br/>Δείτε<x1/>.', ['pair 1 is empty']],
    'nested pairs' => ['<1>Check-in <2>15:00</2></1>.<br/>Δείτε<x1/>.', ['pair 2 nested in 1', 'unbalanced close 1']],
    'unclosed pair' => ['<1>Check-in στις <2>15:00</2>.<br/>Δείτε<x1/>.', ['pair 2 nested in 1']],
    'duplicated atom' => ['<1>Check-in</1> <2>15:00</2>.<br/>Δείτε<x1/><x1/>.', ['atom 1 appears 2 times']],
    'missing atom' => ['<1>Check-in</1> <2>15:00</2>.<br/>Δείτε.', ['atom 1 appears 0 times']],
    'line breaks' => ['<1>Check-in</1> <2>15:00</2>. Δείτε<x1/>.', ['0 line breaks instead of 1']],
    'other syntax' => ['<g1>Check-in</g1> <2>15:00</2>.<br/>Δείτε<x1/>.', ['unknown tag <g1>', 'unknown tag </g1>', 'pair 1 missing']],
    'other tag' => ['<1>Check-in</1> <b>στις</b> <2>15:00</2>.<br/>Δείτε<x1/>.', ['unknown tag <b>', 'unknown tag </b>']],
]);

it('reports a translation that is far too long', function () {
    expect(PlaceholderCheck::problems('Hi', str_repeat('Γεια ', 50), 'numeric'))->toBe(['too long']);
});

it('lists the failing segments of a batch, a missing translation included', function () use ($source) {
    $segments = [['id' => 's1', 'text' => $source], ['id' => 's2', 'text' => 'Hello'], ['id' => 's3', 'text' => 'Bye']];

    expect(PlaceholderCheck::failures($segments, ['s1' => 'Το check-in<x1/>', 's2' => 'Γεια'], 'numeric'))->toBe([
        's1' => [
            'problems' => ['pair 1 missing', 'pair 2 missing', '0 line breaks instead of 1'],
            'source' => $source,
            'translation' => 'Το check-in<x1/>',
        ],
        's3' => ['problems' => ['missing'], 'source' => 'Bye', 'translation' => null],
    ]);
});
