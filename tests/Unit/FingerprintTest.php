<?php

use Reach\StatamicTolk\Content\Fingerprint;
use Statamic\Facades\YAML;

function sha256Prefix(string $payload, int $length): string
{
    return substr(hash('sha256', $payload), 0, $length);
}

it('hashes the canonical JSON of a value to 16 hex characters', function () {
    expect(Fingerprint::of('Home'))->toBe(sha256Prefix('"Home"', 16))
        ->and(Fingerprint::of(['b' => ['x', 1], 'a' => 'Καλημέρα / a']))->toBe(sha256Prefix('{"a":"Καλημέρα / a","b":["x","1"]}', 16))
        ->and(Fingerprint::of(null))->toBe(sha256Prefix('null', 16))
        ->and(Fingerprint::of([]))->toBe(sha256Prefix('[]', 16))
        ->and(Fingerprint::of(['a' => 'b']))->toMatch('/^[0-9a-f]{16}$/');
});

it('ignores the key order of maps at every depth', function () {
    $value = ['type' => 'text', 'text' => 'Hello', 'attrs' => ['level' => 2, 'align' => 'left']];
    $reordered = ['attrs' => ['align' => 'left', 'level' => 2], 'text' => 'Hello', 'type' => 'text'];

    expect(Fingerprint::of([$value]))->toBe(Fingerprint::of([$reordered]))
        ->and(Fingerprint::of([10 => 'a', 9 => 'b', 'x' => 'c']))->toBe(Fingerprint::of(['x' => 'c', 9 => 'b', 10 => 'a']));
});

it('keeps the order and every item of lists', function () {
    expect(Fingerprint::of(['One', 'Two']))->not->toBe(Fingerprint::of(['Two', 'One']))
        ->and(Fingerprint::of([['text' => 'One'], ['text' => 'Two']]))->not->toBe(Fingerprint::of([['text' => 'Two'], ['text' => 'One']]))
        ->and(Fingerprint::of(['One', null]))->not->toBe(Fingerprint::of(['One']))
        ->and(Fingerprint::of(['One', '']))->not->toBe(Fingerprint::of(['One']))
        ->and(Fingerprint::of(['One', []]))->not->toBe(Fingerprint::of(['One']));
});

it('ignores null, empty string and empty array map entries at every depth', function () {
    $clean = [
        'title' => 'Rooms',
        'blocks' => [
            ['id' => 'aB3dE9xZ', 'type' => 'text', 'text' => 'Hello'],
            ['id' => 'cD4eF0yA', 'type' => 'quote', 'cite' => ['name' => 'Ann']],
        ],
    ];

    $noisy = [
        'title' => 'Rooms',
        'subtitle' => null,
        'intro' => '',
        'tags' => [],
        'blocks' => [
            ['id' => 'aB3dE9xZ', 'type' => 'text', 'text' => 'Hello', 'kicker' => null, 'items' => []],
            ['id' => 'cD4eF0yA', 'type' => 'quote', 'cite' => ['name' => 'Ann', 'role' => ''], 'meta' => ['note' => null, 'links' => []]],
        ],
        'seo' => ['title' => null, 'og' => ['image' => '']],
    ];

    expect(Fingerprint::of($noisy))->toBe(Fingerprint::of($clean))
        ->and(Fingerprint::canonical($noisy))->toBe(Fingerprint::canonical($clean));
});

it('turns scalars into strings', function (mixed $value, string $string) {
    expect(Fingerprint::canonical($value))->toBe($string)
        ->and(Fingerprint::of($value))->toBe(Fingerprint::of($string))
        ->and(Fingerprint::of(['a' => [$value]]))->toBe(Fingerprint::of(['a' => [$string]]));
})->with([
    'an integer' => [1, '1'],
    'a negative integer' => [-42, '-42'],
    'zero' => [0, '0'],
    'true' => [true, 'true'],
    'false' => [false, 'false'],
    'a float' => [9.99, '9.99'],
    'a negative float' => [-2.5, '-2.5'],
    'a whole float' => [1.0, '1'],
    'negative zero' => [-0.0, '0'],
    'a small float' => [1.5e-7, '0.00000015'],
    'a large float' => [1e20, '100000000000000000000'],
    'an inexact sum' => [0.1 + 0.2, '0.30000000000000004'],
]);

it('keeps zero and false map entries', function () {
    expect(Fingerprint::canonical(['count' => 0, 'enabled' => false, 'label' => '0']))
        ->toBe(['count' => '0', 'enabled' => 'false', 'label' => '0']);
});

it('treats CRLF line breaks as LF', function () {
    expect(Fingerprint::of("One\r\nTwo\r\n"))->toBe(Fingerprint::of("One\nTwo\n"))
        ->and(Fingerprint::of(['text' => "One\r\nTwo"]))->toBe(Fingerprint::of(['text' => "One\nTwo"]))
        ->and(Fingerprint::of("One\nTwo"))->not->toBe(Fingerprint::of('One Two'));
});

it('gives a value the same fingerprint after a YAML round trip', function () {
    $value = [
        'title' => 'Rooms & suites',
        'subtitle' => null,
        'empty' => '',
        'none' => [],
        'count' => 3,
        'price' => 9.99,
        'whole' => 2.0,
        'enabled' => true,
        'hidden' => false,
        'numeric' => '1',
        'boolean' => 'true',
        'date' => '2026-10-01',
        'url' => 'https://example.com/a/b?c=d#e',
        'greek' => 'Καλημέρα, κόσμε',
        'multiline' => "First line\nSecond line\n",
        'windows' => "First line\r\nSecond line",
        'antlers' => '{{ site:name }} — {{# a comment #}}',
        'blocks' => [
            [
                'id' => 'aB3dE9xZ',
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Bold ', 'marks' => [['type' => 'bold']]],
                    ['type' => 'text', 'text' => 'link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::abc', 'target' => null]]]],
                ],
            ],
            ['id' => 'cD4eF0yA', 'type' => 'set', 'attrs' => ['values' => ['type' => 'quote', 'cite' => 'Source', 'note' => null]]],
        ],
        'table' => [['cells' => ['a', 'b']], ['cells' => ['', '1']]],
        'list' => ['One', 2, 3.5, 'Four'],
    ];

    $parsed = YAML::parse(YAML::dump($value));

    expect(Fingerprint::of($parsed))->toBe(Fingerprint::of($value))
        ->and(Fingerprint::of($parsed['blocks']))->toBe(Fingerprint::of($value['blocks']));
});

it('ignores how the YAML that stores a value is written', function () {
    $written = YAML::parse(str_replace("\n", "\r\n", <<<'YAML'
        blocks:
          -
            type: quote
            cite: { role: '', name: Ann }
            id: cD4eF0yA
            note: ~
            text: |
              First line
              Second line
        count: '3'
        enabled: 'true'
        price: 9.99
        YAML));

    expect(Fingerprint::of($written))->toBe(Fingerprint::of([
        'count' => 3,
        'enabled' => true,
        'price' => '9.99',
        'blocks' => [['id' => 'cD4eF0yA', 'type' => 'quote', 'cite' => ['name' => 'Ann'], 'text' => "First line\r\nSecond line\r\n"]],
    ]));
});

it('fingerprints leaf texts in walk order', function () {
    expect(Fingerprint::ofLeaves(['One', 'Two']))->toBe(sha256Prefix('["One","Two"]', 16))
        ->and(Fingerprint::ofLeaves(['One', 'Two']))->not->toBe(Fingerprint::ofLeaves(['Two', 'One']))
        ->and(Fingerprint::ofLeaves(['Καλημέρα <1>κόσμε</1> / <x1/>']))->toBe(sha256Prefix('["Καλημέρα <1>κόσμε</1> / <x1/>"]', 16))
        ->and(Fingerprint::ofLeaves(['One']))->not->toBe(Fingerprint::ofLeaves(['One', '']));
});

it('gives the empty leaf list a fixed fingerprint', function () {
    expect(Fingerprint::ofLeaves([]))->toBe(sha256Prefix('[]', 16))
        ->and(Fingerprint::ofLeaves([]))->toBe('4f53cda18c2baa0c');
});

it('fingerprints one leaf text to 12 hex characters', function () {
    expect(Fingerprint::ofText('Home'))->toBe(sha256Prefix('Home', 12))
        ->and(Fingerprint::ofText('Home'))->toMatch('/^[0-9a-f]{12}$/')
        ->and(Fingerprint::ofText('Home'))->not->toBe(Fingerprint::ofText('home'));
});
