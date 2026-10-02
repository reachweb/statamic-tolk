<?php

use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;

beforeEach(function () {
    $this->makeCollection('articles', ['en', 'el'], [
        'title' => ['type' => 'text', 'localizable' => true],
        'body' => ['type' => 'replicator', 'localizable' => true, 'sets' => ['main' => ['sets' => [
            'text' => ['fields' => [
                ['handle' => 'heading', 'field' => ['type' => 'text']],
                ['handle' => 'featured', 'field' => ['type' => 'toggle', 'default' => true]],
                ['handle' => 'kicker', 'field' => ['type' => 'text', 'default' => 'Read this']],
            ]],
            'cta' => ['fields' => [
                ['handle' => 'label', 'field' => ['type' => 'text']],
                ['handle' => 'open_in_new_tab', 'field' => ['type' => 'toggle']],
            ]],
        ]]]],
        'faq' => ['type' => 'grid', 'localizable' => true, 'fields' => [
            ['handle' => 'question', 'field' => ['type' => 'text']],
            ['handle' => 'answer', 'field' => ['type' => 'textarea']],
        ]],
        'content' => ['type' => 'bard', 'localizable' => true, 'buttons' => ['bold', 'anchor', 'h2'], 'sets' => ['main' => ['sets' => [
            'quote' => ['fields' => [
                ['handle' => 'text', 'field' => ['type' => 'textarea']],
                ['handle' => 'cite', 'field' => ['type' => 'text', 'default' => 'Source']],
                ['handle' => 'wide', 'field' => ['type' => 'toggle']],
            ]],
        ]]]],
        'meta' => ['type' => 'group', 'localizable' => true, 'fields' => [
            ['handle' => 'subtitle', 'field' => ['type' => 'text']],
            ['handle' => 'badge', 'field' => ['type' => 'text']],
        ]],
    ]);

    $origin = $this->makeEntry('articles', 'en', ['title' => 'Hello']);
    $this->entry = Entry::find($origin->id())->makeLocalization('el');
});

function roundTrip(EntryContract $entry, string $handle, mixed $value): mixed
{
    return $entry->blueprint()->setParent($entry)->fields()
        ->addValues([$handle => $value])
        ->preProcess()
        ->process()
        ->values()[$handle];
}

function withoutIds(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    unset($value['id']);

    return array_map(withoutIds(...), $value);
}

dataset('values', [
    'replicator without row ids' => ['body', [
        ['type' => 'text', 'heading' => 'First'],
        ['type' => 'cta', 'label' => 'Book now'],
        ['id' => 'keepme12', 'type' => 'text', 'heading' => 'Third', 'enabled' => false],
    ]],
    'grid' => ['faq', [
        ['question' => 'Q1', 'answer' => 'A1'],
        ['id' => 'gridrow1', 'question' => 'Q2'],
    ]],
    'bard with a set and empty paragraphs' => ['content', [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello '], ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'world']]],
        ['type' => 'paragraph'],
        ['type' => 'paragraph', 'attrs' => ['textAlign' => 'right']],
        ['type' => 'set', 'attrs' => ['values' => ['type' => 'quote', 'text' => 'Quote text']]],
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Head']]],
    ]],
    'group' => ['meta', ['subtitle' => 'Sub']],
    'empty replicator' => ['body', []],
    'null bard' => ['content', null],
    'null group' => ['meta', null],
]);

it('is idempotent', function (string $handle, mixed $value) {
    $once = roundTrip($this->entry, $handle, $value);
    $twice = roundTrip($this->entry, $handle, $once);

    expect(withoutIds($twice))->toBe(withoutIds($once))
        ->and($twice)->toBe($once);
})->with('values');

it('gives replicator rows an id, enabled and the defaults of missing set fields, text defaults included', function () {
    $value = roundTrip($this->entry, 'body', [
        ['type' => 'text', 'heading' => 'First'],
        ['type' => 'cta', 'label' => 'Book now'],
        ['id' => 'keepme12', 'type' => 'text', 'heading' => 'Third', 'enabled' => false],
    ]);

    expect($value[0]['id'])->toMatch('/^[A-Za-z0-9]{8}$/')
        ->and($value[1]['id'])->toMatch('/^[A-Za-z0-9]{8}$/')
        ->and(withoutIds($value))->toBe([
            ['type' => 'text', 'heading' => 'First', 'featured' => true, 'kicker' => 'Read this', 'enabled' => true],
            ['type' => 'cta', 'label' => 'Book now', 'open_in_new_tab' => false, 'enabled' => true],
            ['type' => 'text', 'heading' => 'Third', 'enabled' => false, 'featured' => true, 'kicker' => 'Read this'],
        ])
        ->and($value[2]['id'])->toBe('keepme12');
});

it('gives grid rows an id and nothing else', function () {
    $value = roundTrip($this->entry, 'faq', [
        ['question' => 'Q1', 'answer' => 'A1'],
        ['id' => 'gridrow1', 'question' => 'Q2'],
    ]);

    expect($value[0]['id'])->toMatch('/^[A-Za-z0-9]{8}$/')
        ->and(withoutIds($value))->toBe([
            ['question' => 'Q1', 'answer' => 'A1'],
            ['question' => 'Q2'],
        ])
        ->and($value[1]['id'])->toBe('gridrow1');
});

it('gives bard sets an id and the defaults of missing set fields, and keeps every node and attribute', function () {
    $input = [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello '], ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'world']]],
        ['type' => 'paragraph'],
        ['type' => 'paragraph', 'attrs' => ['textAlign' => 'right']],
        ['type' => 'set', 'attrs' => ['values' => ['type' => 'quote', 'text' => 'Quote text']]],
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Head']]],
    ];

    $value = roundTrip($this->entry, 'content', $input);

    $expected = $input;
    $expected[3]['attrs'] = ['values' => ['type' => 'quote', 'text' => 'Quote text', 'cite' => 'Source', 'wide' => false]];

    expect($value[3]['attrs']['id'])->toMatch('/^[A-Za-z0-9]{8}$/')
        ->and(withoutIds($value))->toBe($expected)
        ->and(array_keys($value[3]['attrs']))->toBe(['id', 'values']);
});

it('leaves a group as it is and turns an empty one into an empty array', function () {
    expect(roundTrip($this->entry, 'meta', ['subtitle' => 'Sub']))->toBe(['subtitle' => 'Sub'])
        ->and(roundTrip($this->entry, 'meta', null))->toBe([])
        ->and(roundTrip($this->entry, 'faq', null))->toBe([])
        ->and(roundTrip($this->entry, 'content', null))->toBeNull()
        ->and(roundTrip($this->entry, 'title', ''))->toBe('');
});

it('shares one blueprint object between the entries of a collection, so the parent must be set right before fields()', function () {
    $other = Entry::find($this->makeEntry('articles', 'en', ['title' => 'Other'])->id())->makeLocalization('el')->id(Stache::generateId());

    $blueprint = $this->entry->blueprint();

    expect($other->blueprint())->toBe($blueprint)
        ->and($blueprint->parent())->toBe($other)
        ->and($this->entry->blueprint()->setParent($this->entry)->fields()->get('body')->parent())->toBe($this->entry);
});
