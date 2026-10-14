<?php

use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Content\Handlers\BardHandler;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Enums\SegmentKind;
use Reach\StatamicTolk\Tests\Support\ContentFixtures;
use Statamic\Fields\Field;

const BARD_QUOTE_SET = ['quote' => ['display' => 'Quote', 'fields' => [
    ['handle' => 'text', 'field' => ['type' => 'textarea', 'display' => 'Text']],
    ['handle' => 'label', 'field' => ['type' => 'text', 'display' => 'Label', 'default' => 'Read more']],
]]];

function bardField(array $config = []): Field
{
    return new Field('content', ['type' => 'bard', 'display' => 'Content', ...$config]);
}

function bardWalker(): ContentWalker
{
    return app(ContentWalker::class);
}

/**
 * @return list<array{0: string, 1: string, 2: string, 3: ?string}>
 */
function bardLeaves(Field $field, mixed $value, bool $target = false): array
{
    return array_map(
        fn (Leaf $leaf) => [$leaf->kind->value, $leaf->text, $leaf->label, $leaf->hint],
        bardWalker()->leaves($field, $value, $target),
    );
}

/**
 * Translates the leaves the map names and keeps every other leaf's text.
 */
function bardTranslated(Field $field, mixed $value, array $translations): mixed
{
    return bardWalker()->mapField($field, $value, fn (Leaf $leaf) => $translations[$leaf->text] ?? $leaf->text);
}

// Upper-cases the text between placeholders and character references, so every leaf changes and nothing else does.
function bardUpper(Leaf $leaf): string
{
    $parts = preg_split('/(<[^>]*>|&#?\w+;)/', $leaf->text, -1, PREG_SPLIT_DELIM_CAPTURE);

    return implode('', array_map(fn (string $part, int $index) => $index % 2 === 0 ? mb_strtoupper($part) : $part, $parts, array_keys($parts)));
}

// The value without the texts that are leaves of bard-rich: text nodes' text, link titles, image alts and the sets' text fields.
function bardSkeleton(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    match ($value['type'] ?? null) {
        'text' => $value = array_diff_key($value, ['text' => true]),
        'link' => $value['attrs'] = array_diff_key($value['attrs'], ['title' => true]),
        'image' => $value['attrs'] = array_diff_key($value['attrs'], ['alt' => true]),
        'set' => $value['attrs']['values'] = array_diff_key($value['attrs']['values'], ['text' => true, 'author' => true, 'caption' => true]),
        default => null,
    };

    return array_map(bardSkeleton(...), $value);
}

it('is registered for bard at boot', function () {
    expect(app(FieldtypeRegistry::class)->for(bardField()))->toBeInstanceOf(BardHandler::class);
});

it('returns an identical value when every leaf keeps its text', function (string $fixture) {
    $field = ContentFixtures::field($fixture);
    $value = ContentFixtures::value($fixture);
    $texts = array_map(fn (Leaf $leaf) => $leaf->text, bardWalker()->leaves($field, $value));

    expect($texts)->not->toBe([])
        ->and(bardWalker()->mapField($field, $value, fn (Leaf $leaf) => $leaf->text))->toBe($value)
        ->and(bardWalker()->inject($field, $value, $texts))->toBe($value)
        ->and(bardWalker()->inject($field, $value, $texts, target: true))->toBe($value);
})->with(['bard-rich', 'bard-html-string', 'bard-inline']);

it('leaves empty and other values untouched', function (mixed $value) {
    expect(bardLeaves(bardField(), $value))->toBe([])
        ->and(bardWalker()->mapField(bardField(), $value, fn (Leaf $leaf) => 'X'))->toBe($value);
})->with([
    'null' => null,
    'an empty string' => '',
    'an empty list' => [[]],
    'an empty paragraph' => [[['type' => 'paragraph']]],
    'a map' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]]]]],
    'an integer' => 12,
    'a rule and a paragraph of digits' => [[['type' => 'horizontalRule'], ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '10:00–20:00']]]]],
    'a code block' => [[['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [['type' => 'text', 'text' => 'echo "Hello";']]]]],
    'nodes that are not arrays' => [['paragraph', 12]],
]);

it('lists the leaves of bard-rich block by block', function () {
    expect(bardLeaves(ContentFixtures::field('bard-rich'), ContentFixtures::value('bard-rich')))->toBe([
        ['inline', 'Welcome to Kyma Bay', 'Content', null],
        ['inline', "Since 1987 the Markakis family has welcomed guests to the <1>old harbour</1> of Chania. Breakfast is served until <2>eleven o'clock</2>, <3>always</3> with bread from the oven next door.", 'Content', null],
        ['plain', 'Ferry timetable of the port authority', 'Content', 'link title'],
        ['inline', 'Browse our <1>rooms and suites</1>, check the <2>ferry timetable</2> or download the <3>price list</3>.', 'Content', null],
        ['inline', 'Kyma Bay Hotel<br/>Akti Koundourioti 12, Chania<br/>Wi-Fi password: <x1/> (kids &lt; 12 &amp; pets stay free)', 'Content', null],
        ['inline', 'Look for this sign at the harbour: <x1/>', 'Content', null],
        ['plain', 'A blue wave on a white sign', 'Content', 'image alt text'],
        ['plain', 'The saltwater pool and sun terrace, with the harbour lighthouse in the background', 'Content', 'image alt text'],
        ['plain', 'We came for a week and stayed for three. The staff remembered our names from the first morning.', 'Content › Quote › Text', null],
        ['plain', 'Hannah, guest from Hamburg', 'Content › Quote › Author', null],
        ['inline', 'Good to know', 'Content', null],
        ['inline', 'Check-in from 15:00, check-out until 11:00', 'Content', null],
        ['inline', 'Getting here from the airport:', 'Content', null],
        ['inline', 'Take the airport bus to the <1>central station</1>', 'Content', null],
        ['inline', 'Walk ten minutes down to the harbour', 'Content', null],
        ['inline', '<1>Pets on request.</1> Pets are welcome in the garden rooms.', 'Content', null],
        ['inline', 'Kyma means wave in Greek.', 'Content', null],
        ['inline', 'Room', 'Content', null],
        ['inline', 'Size', 'Content', null],
        ['inline', 'Price per night', 'Content', null],
        ['inline', 'Garden double', 'Content', null],
        ['inline', '22 m<1>2</1>', 'Content', null],
        ['inline', '<1>Sea view suite</1>', 'Content', null],
        ['inline', '45 m<1>2</1>', 'Content', null],
        ['inline', 'from €240', 'Content', null],
        ['plain', 'The pool terrace, and the garden at breakfast time', 'Content › Gallery › Caption', null],
        ['inline', '<1>Prices include VAT and the city tax of €1.50 per night.</1>', 'Content', null],
    ]);
});

it('gives leaves the field path, and set fields the path of their data', function () {
    $leaves = bardWalker()->leaves(ContentFixtures::field('bard-rich'), ContentFixtures::value('bard-rich'));

    expect($leaves[0]->path)->toBe(['bard_rich'])
        ->and($leaves[2]->path)->toBe(['bard_rich'])
        ->and($leaves[8]->path)->toBe(['bard_rich', 6, 'attrs', 'values', 'text'])
        ->and($leaves[25]->path)->toBe(['bard_rich', 13, 'attrs', 'values', 'caption'])
        ->and($leaves[1]->placeholders)->toBe(['1' => '[{"type":"bold"}]', '2' => '[{"type":"italic"}]', '3' => '[{"type":"bold"},{"type":"italic"}]'])
        ->and($leaves[3]->placeholders[2])->toBe('[{"attrs":{"href":"https://www.chania-port.example/ferries","rel":"noopener noreferrer","target":"_blank"},"type":"link"}]')
        ->and($leaves[5]->placeholders)->toBe(['x1' => '{"attrs":{"src":"asset::assets::sign.png"},"type":"image"}'])
        ->and($leaves[0]->maxLength)->toBeNull();
});

it('changes only leaf texts when every leaf is translated', function () {
    $field = ContentFixtures::field('bard-rich');
    $value = ContentFixtures::value('bard-rich');

    $translated = bardWalker()->mapField($field, $value, bardUpper(...));
    $paragraph = $translated[2]['content'];

    expect(bardSkeleton($translated))->toBe(bardSkeleton($value))
        ->and($translated[0]['content'][0]['text'])->toBe('WELCOME TO KYMA BAY')
        ->and($paragraph[3]['marks'][0]['attrs'])->toBe(['href' => 'https://www.chania-port.example/ferries', 'rel' => 'noopener noreferrer', 'target' => '_blank', 'title' => 'FERRY TIMETABLE OF THE PORT AUTHORITY'])
        ->and($paragraph[1]['marks'][0]['attrs']['href'])->toBe('statamic://entry::7fa871f4-da60-46fe-9053-a94e5eb8e212')
        ->and($translated[3]['content'][5])->toBe(['type' => 'text', 'marks' => [['type' => 'code']], 'text' => 'kyma-guest-2026'])
        ->and($translated[3]['content'][6]['text'])->toBe(' (KIDS < 12 & PETS STAY FREE)')
        ->and($translated[4]['content'][1])->toBe(['type' => 'image', 'attrs' => ['src' => 'asset::assets::sign.png', 'alt' => 'A BLUE WAVE ON A WHITE SIGN']])
        ->and($translated[5]['attrs'])->toBe(['src' => 'asset::assets::pool-terrace.jpg', 'alt' => 'THE SALTWATER POOL AND SUN TERRACE, WITH THE HARBOUR LIGHTHOUSE IN THE BACKGROUND'])
        ->and($translated[6]['attrs'])->toBe(['id' => 'p6rkq4uy', 'values' => [
            'type' => 'quote',
            'text' => 'WE CAME FOR A WEEK AND STAYED FOR THREE. THE STAFF REMEMBERED OUR NAMES FROM THE FIRST MORNING.',
            'author' => 'HANNAH, GUEST FROM HAMBURG',
        ]])
        ->and($translated[11])->toBe($value[11])
        ->and($translated[13]['attrs'])->toBe(['id' => 'w2nd8qhe', 'enabled' => false, 'values' => [
            'type' => 'gallery',
            'images' => ['pool-terrace.jpg', 'garden-breakfast.jpg'],
            'caption' => 'THE POOL TERRACE, AND THE GARDEN AT BREAKFAST TIME',
        ]])
        ->and($translated[14])->toBe($value[14]);
});

it('walks a translated value into the same leaves, in the same order', function () {
    $field = ContentFixtures::field('bard-rich');
    $value = ContentFixtures::value('bard-rich');
    $source = bardWalker()->leaves($field, $value);

    $target = bardWalker()->leaves($field, bardWalker()->mapField($field, $value, bardUpper(...)), target: true);

    expect(array_map(fn (Leaf $leaf) => $leaf->text, $target))->toBe(array_map(bardUpper(...), $source))
        ->and(array_map(fn (Leaf $leaf) => $leaf->placeholders, $target))->toBe(array_map(fn (Leaf $leaf) => $leaf->placeholders, $source));
});

it('rebuilds a block from a translation that reorders its runs, with the translated link title', function () {
    $value = ContentFixtures::value('bard-rich');

    $translated = bardTranslated(ContentFixtures::field('bard-rich'), $value, [
        'Ferry timetable of the port authority' => 'Δρομολόγια πλοίων του λιμεναρχείου',
        'Browse our <1>rooms and suites</1>, check the <2>ferry timetable</2> or download the <3>price list</3>.' => 'Κατεβάστε τον <3>τιμοκατάλογο</3>, δείτε τα <1>δωμάτια</1> και τα <1>σουίτες</1> ή τα <2>δρομολόγια</2>.',
    ]);

    $link = fn (int $run) => $value[2]['content'][$run]['marks'];
    $ferry = $link(3);
    $ferry[0]['attrs']['title'] = 'Δρομολόγια πλοίων του λιμεναρχείου';

    expect($translated[2])->toBe(['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'Κατεβάστε τον '],
        ['type' => 'text', 'marks' => $link(5), 'text' => 'τιμοκατάλογο'],
        ['type' => 'text', 'text' => ', δείτε τα '],
        ['type' => 'text', 'marks' => $link(1), 'text' => 'δωμάτια'],
        ['type' => 'text', 'text' => ' και τα '],
        ['type' => 'text', 'marks' => $link(1), 'text' => 'σουίτες'],
        ['type' => 'text', 'text' => ' ή τα '],
        ['type' => 'text', 'marks' => $ferry, 'text' => 'δρομολόγια'],
        ['type' => 'text', 'text' => '.'],
    ]])->and(array_diff_key($translated, [2 => true]))->toBe(array_diff_key($value, [2 => true]));
});

it('keeps hard breaks, code runs and inline images, and writes a translated alt into the image', function () {
    $value = ContentFixtures::value('bard-rich');

    $translated = bardTranslated(ContentFixtures::field('bard-rich'), $value, [
        'Kyma Bay Hotel<br/>Akti Koundourioti 12, Chania<br/>Wi-Fi password: <x1/> (kids &lt; 12 &amp; pets stay free)' => 'Ξενοδοχείο Kyma Bay<br/>Ακτή Κουντουριώτη 12, Χανιά<br/>Κωδικός Wi-Fi: <x1/> (παιδιά &lt; 12 &amp; κατοικίδια δωρεάν)',
        'Look for this sign at the harbour: <x1/>' => '<x1/> Αναζητήστε αυτή την πινακίδα στο λιμάνι.',
        'A blue wave on a white sign' => 'Ένα μπλε κύμα σε λευκή πινακίδα',
    ]);

    expect($translated[3]['content'])->toBe([
        ['type' => 'text', 'text' => 'Ξενοδοχείο Kyma Bay'],
        ['type' => 'hardBreak'],
        ['type' => 'text', 'text' => 'Ακτή Κουντουριώτη 12, Χανιά'],
        ['type' => 'hardBreak'],
        ['type' => 'text', 'text' => 'Κωδικός Wi-Fi: '],
        ['type' => 'text', 'marks' => [['type' => 'code']], 'text' => 'kyma-guest-2026'],
        ['type' => 'text', 'text' => ' (παιδιά < 12 & κατοικίδια δωρεάν)'],
    ])->and($translated[4]['content'])->toBe([
        ['type' => 'image', 'attrs' => ['src' => 'asset::assets::sign.png', 'alt' => 'Ένα μπλε κύμα σε λευκή πινακίδα']],
        ['type' => 'text', 'text' => ' Αναζητήστε αυτή την πινακίδα στο λιμάνι.'],
    ]);
});

it('translates an alt when the block text stays the same', function () {
    $value = [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'The pool: '],
        ['type' => 'image', 'attrs' => ['src' => 'asset::assets::pool.jpg', 'alt' => 'The pool']],
    ]]];

    expect(bardTranslated(bardField(), $value, ['The pool' => 'Η πισίνα']))->toBe([['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'The pool: '],
        ['type' => 'image', 'attrs' => ['src' => 'asset::assets::pool.jpg', 'alt' => 'Η πισίνα']],
    ]]]);
});

it('offers a title once for a link split by another mark, and writes it into every part', function () {
    $link = fn (string $title) => ['type' => 'link', 'attrs' => ['href' => '/spa', 'title' => $title]];
    $value = [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'marks' => [$link('Our spa')], 'text' => 'The '],
        ['type' => 'text', 'marks' => [$link('Our spa'), ['type' => 'bold']], 'text' => 'spa'],
    ]]];

    expect(bardLeaves(bardField(), $value))->toBe([
        ['plain', 'Our spa', 'Content', 'link title'],
        ['inline', '<1>The </1><2>spa</2>', 'Content', null],
    ])->and(bardTranslated(bardField(), $value, ['Our spa' => 'Το σπα μας']))->toBe([['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'marks' => [$link('Το σπα μας')], 'text' => 'The '],
        ['type' => 'text', 'marks' => [$link('Το σπα μας'), ['type' => 'bold']], 'text' => 'spa'],
    ]]]);
});

it('makes Antlers tags in text, titles and alts atoms', function () {
    $value = [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'Welcome to {{ site:name }} '],
        ['type' => 'image', 'attrs' => ['src' => 'asset::assets::logo.svg', 'alt' => 'Logo of {{ site:name }}']],
    ]]];

    expect(bardLeaves(bardField(), $value))->toBe([
        ['inline', 'Welcome to <x1/> <x2/>', 'Content', null],
        ['plain', 'Logo of <x1/>', 'Content', 'image alt text'],
    ])->and(bardWalker()->inject(bardField(), $value, ['<x2/> Καλώς ήρθατε στο <x1/>', 'Λογότυπο του <x1/>']))->toBe([['type' => 'paragraph', 'content' => [
        ['type' => 'image', 'attrs' => ['src' => 'asset::assets::logo.svg', 'alt' => 'Λογότυπο του {{ site:name }}']],
        ['type' => 'text', 'text' => ' Καλώς ήρθατε στο {{ site:name }}'],
    ]]]);
});

it('leaves titles and alts that are not text untouched', function () {
    $value = [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/a', 'title' => 'https://kyma-bay.example']]], 'text' => 'Home'],
        ['type' => 'image', 'attrs' => ['src' => 'asset::assets::a.jpg', 'alt' => '']],
        ['type' => 'image', 'attrs' => ['src' => 'asset::assets::b.jpg', 'alt' => null]],
        ['type' => 'image', 'attrs' => ['src' => 'asset::assets::c.jpg']],
    ]]];

    expect(bardLeaves(bardField(), $value))->toBe([['inline', '<1>Home</1><x1/><x2/><x3/>', 'Content', null]]);
});

it('gives the field hint to text leaves and fixed hints to titles and alts', function () {
    $value = [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/spa', 'title' => 'Our spa']]], 'text' => 'Spa'],
        ]],
        ['type' => 'image', 'attrs' => ['src' => 'asset::assets::spa.jpg', 'alt' => 'The spa']],
    ];

    expect(bardLeaves(bardField(['tolk_hint' => ' Formal tone ']), $value))->toBe([
        ['plain', 'Our spa', 'Content', 'link title'],
        ['inline', '<1>Spa</1>', 'Content', 'Formal tone'],
        ['plain', 'The spa', 'Content', 'image alt text'],
    ])->and(bardLeaves(bardField(['save_html' => true, 'tolk_hint' => 'Formal tone']), '<p>Spa <em>daily</em></p>'))->toBe([
        ['html', 'Spa <1>daily</1>', 'Content', 'Formal tone'],
    ]);
});

it('recurses into custom block nodes and carries a custom inline node as an atom', function () {
    $value = [
        ['type' => 'details', 'content' => [
            ['type' => 'detailsSummary', 'content' => [['type' => 'text', 'text' => 'Opening hours']]],
            ['type' => 'detailsContent', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Daily from 10:00']]]]],
        ]],
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Ask '],
            ['type' => 'mention', 'attrs' => ['id' => 7], 'content' => [['type' => 'text', 'text' => 'Maria']]],
        ]],
    ];

    expect(bardLeaves(bardField(), $value))->toBe([
        ['inline', 'Opening hours', 'Content', null],
        ['inline', 'Daily from 10:00', 'Content', null],
        ['inline', 'Ask <x1/>', 'Content', null],
    ])->and(bardWalker()->inject(bardField(), $value, ['Ώρες', 'Καθημερινά από τις 10:00', 'Ρωτήστε τη <x1/>'])[1]['content'])->toBe([
        ['type' => 'text', 'text' => 'Ρωτήστε τη '],
        ['type' => 'mention', 'attrs' => ['id' => 7], 'content' => [['type' => 'text', 'text' => 'Maria']]],
    ]);
});

it('makes one leaf of inline nodes stored in a field without the inline option', function () {
    $value = [['type' => 'text', 'text' => 'Rooms '], ['type' => 'text', 'marks' => [['type' => 'italic']], 'text' => 'by the sea']];

    expect(bardLeaves(bardField(), $value))->toBe([['inline', 'Rooms <1>by the sea</1>', 'Content', null]]);
});

it('maps set values with the fields of their set and never touches the set itself', function () {
    $field = bardField(['sets' => BARD_QUOTE_SET]);
    $value = [
        ['type' => 'set', 'attrs' => ['id' => 'a1', 'enabled' => false, 'values' => ['type' => 'quote', 'text' => 'Lovely stay', 'label' => 'Read more']]],
        ['type' => 'set', 'attrs' => ['id' => 'a2', 'values' => ['type' => 'unknown', 'text' => 'Not mine']]],
        ['type' => 'set', 'attrs' => ['id' => 'a3']],
        ['type' => 'set', 'attrs' => ['id' => 'a4', 'values' => ['text' => 'No type']]],
    ];

    expect(bardLeaves($field, $value))->toBe([
        ['plain', 'Lovely stay', 'Content › Quote › Text', null],
        ['plain', 'Read more', 'Content › Quote › Label', null],
    ])->and(bardWalker()->mapField($field, $value, fn (Leaf $leaf) => "[el] {$leaf->text}"))->toBe([
        ['type' => 'set', 'attrs' => ['id' => 'a1', 'enabled' => false, 'values' => ['type' => 'quote', 'text' => '[el] Lovely stay', 'label' => '[el] Read more']]],
        ...array_slice($value, 1),
    ]);
});

it('leaves a set field holding its default out of a target walk', function () {
    $field = bardField(['sets' => BARD_QUOTE_SET]);
    $value = [['type' => 'set', 'attrs' => ['id' => 'a1', 'values' => ['type' => 'quote', 'text' => 'Lovely stay', 'label' => 'Read more']]]];

    expect(array_column(bardLeaves($field, $value), 1))->toBe(['Lovely stay', 'Read more'])
        ->and(array_column(bardLeaves($field, $value, target: true), 1))->toBe(['Lovely stay']);
});

it('labels a set without a display by its handle', function () {
    $field = bardField(['sets' => ['main' => ['sets' => ['call_to_action' => ['fields' => [
        ['handle' => 'label', 'field' => ['type' => 'text']],
    ]]]]]]);

    expect(bardLeaves($field, [['type' => 'set', 'attrs' => ['id' => 'a1', 'values' => ['type' => 'call_to_action', 'label' => 'Book now']]]]))
        ->toBe([['plain', 'Book now', 'Content › Call To Action › Label', null]]);
});

it('leaves legacy data untouched and warns', function () {
    $field = bardField(['sets' => BARD_QUOTE_SET]);
    $value = [['type' => 'text', 'text' => '<p>Welcome to our hotel.</p>'], ['type' => 'quote', 'text' => 'Lovely stay']];

    $walker = bardWalker();

    expect($walker->leaves($field, $value))->toBe([])
        ->and($walker->warnings())->toBe(['Content (content) is stored in the Bard format of Statamic 2 and was left untouched. Saving it once in the control panel converts it.'])
        ->and($walker->mapField($field, $value, fn (Leaf $leaf) => 'X'))->toBe($value);
});

it('does not read inline content or a value without sets as legacy data', function () {
    $text = [['type' => 'text', 'text' => 'Welcome']];

    expect(bardLeaves(bardField(['sets' => BARD_QUOTE_SET, 'inline' => true]), $text))->toBe([['inline', 'Welcome', 'Content', null]])
        ->and(bardLeaves(bardField(), $text))->toBe([['inline', 'Welcome', 'Content', null]]);
});

it('reads the snake-case types of Tiptap 1 as Bard does, so a code_block stays untouched and a hard_break is a break', function () {
    $field = bardField(['sets' => ['call_to_action' => ['display' => 'Call to action', 'fields' => [
        ['handle' => 'label', 'field' => ['type' => 'text', 'display' => 'Label']],
    ]]]]);
    $nodes = fn (Closure $type) => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'Check-in'],
            ['type' => $type('hard_break')],
            ['type' => 'text', 'text' => 'from 15:00'],
        ]],
        ['type' => $type('code_block'), 'attrs' => ['language' => 'php'], 'content' => [['type' => 'text', 'text' => 'echo $foo;']]],
        ['type' => $type('bullet_list'), 'content' => [['type' => $type('list_item'), 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Pool']]],
        ]]]],
        ['type' => $type('horizontal_rule')],
        ['type' => 'set', 'attrs' => ['id' => 'a1', 'values' => ['type' => 'call_to_action', 'label' => 'Book now']]],
    ];
    $legacy = $nodes(fn (string $type) => $type);
    $current = $nodes(fn (string $type) => lcfirst(str_replace('_', '', ucwords($type, '_'))));
    $translate = fn (Leaf $leaf) => "[el] {$leaf->text}";

    expect(bardLeaves($field, $legacy))->toBe([
        ['inline', '<1>Check-in</1><br/>from 15:00', 'Content', null],
        ['inline', 'Pool', 'Content', null],
        ['plain', 'Book now', 'Content › Call to action › Label', null],
    ])->and(bardLeaves($field, $legacy))->toBe(bardLeaves($field, $current))
        ->and(bardWalker()->mapField($field, $legacy, $translate))->toBe(bardWalker()->mapField($field, $current, $translate))
        ->and(bardWalker()->mapField($field, $legacy, $translate)[1])->toBe($current[1]);
});

it('makes one html leaf per block of a value saved as HTML, of the content of a block element', function () {
    $field = ContentFixtures::field('bard-html-string');
    $leaves = bardWalker()->leaves($field, ContentFixtures::value('bard-html-string'));

    expect(array_map(fn (Leaf $leaf) => $leaf->text, $leaves))->toBe([
        'Our new spa',
        'The spa opens on <1>1 April</1> in the old olive press. Book a treatment at <2>the front desk</2> or read the <3>price list</3>.',
        '<1>Open daily</1><x1/>10:00–20:00, kids &lt; 12 &amp; guests over 70 with a companion',
        'Treatments',
        '<1><2>Olive oil massage, 60 minutes</2></1><3><4>Sea salt scrub</4><5><6><7>with <8><9>Cretan</9></8> honey</7></6><10><11>with thyme</11></10></5></3>',
        '<1>The best hour of our holiday. <2>Anna, Vienna</2></1>',
        '<1><2><3><4>Treatment</4></3><5><6>Price</6></5></2><7><8><9>Massage</9></8><10><11>€80</11></10></7></1>',
        'Room size 45 m<1>2</1>, code <x1/> for 10% off, and <2>eleven o&#039;clock</2> is never too late for coffee.',
    ])->and(array_unique(array_map(fn (Leaf $leaf) => $leaf->kind, $leaves), SORT_REGULAR))->toBe([SegmentKind::Html])
        ->and($leaves[1]->placeholders[3])->toBe('<a target="_blank" href="statamic://asset::assets::spa-prices.pdf" title="Spa prices for 2026 (PDF)">')
        ->and($leaves[2]->placeholders)->toBe([1 => '<em>', 'x1' => '<br>'])
        ->and($leaves[0]->path)->toBe(['bard_html_string'])
        ->and($leaves[0]->label)->toBe('Body');
});

it('writes a translated HTML block back with its tags', function () {
    $value = ContentFixtures::value('bard-html-string');
    $source = '<p>The spa opens on <strong>1 April</strong> in the old olive press. Book a treatment at <a href="statamic://entry::51c8a58f-8a3d-47fa-90cc-91f543aa618f">the front desk</a> or read the <a target="_blank" href="statamic://asset::assets::spa-prices.pdf" title="Spa prices for 2026 (PDF)">price list</a>.</p>';

    $translated = bardTranslated(ContentFixtures::field('bard-html-string'), $value, [
        'The spa opens on <1>1 April</1> in the old olive press. Book a treatment at <2>the front desk</2> or read the <3>price list</3>.' => 'Διαβάστε τον <3>τιμοκατάλογο</3> ή κλείστε στη <2>ρεσεψιόν</2>. Το σπα ανοίγει την <1>1η Απριλίου</1>.',
    ]);

    expect($value)->toContain($source)
        ->and($translated)->toBe(str_replace($source, '<p>Διαβάστε τον <a target="_blank" href="statamic://asset::assets::spa-prices.pdf" title="Spa prices for 2026 (PDF)">τιμοκατάλογο</a> ή κλείστε στη <a href="statamic://entry::51c8a58f-8a3d-47fa-90cc-91f543aa618f">ρεσεψιόν</a>. Το σπα ανοίγει την <strong>1η Απριλίου</strong>.</p>', $value));
});

it('keeps whitespace between HTML blocks outside the leaves', function () {
    $field = bardField(['save_html' => true]);

    expect(bardLeaves($field, "<p>One</p>\n<p>Two</p>\n"))->toBe([['html', 'One', 'Content', null], ['html', 'Two', 'Content', null]])
        ->and(bardWalker()->mapField($field, "<p>One</p>\n<p>Two</p>\n", fn (Leaf $leaf) => "[el] {$leaf->text}"))->toBe("<p>[el] One</p>\n<p>[el] Two</p>\n");
});

it('keeps the tags of a block element and the whitespace inside it outside its leaf', function () {
    $field = bardField(['save_html' => true]);
    $value = "<blockquote class=\"note\">\n<p>Calm</p>\n</blockquote><hr><p><img src=\"/a.jpg\"></p>Loose <b>text</b><h2>Spa</h2>";

    expect(bardLeaves($field, $value))->toBe([
        ['html', '<1>Calm</1>', 'Content', null],
        ['html', 'Loose <1>text</1><2>Spa</2>', 'Content', null],
    ])->and(bardWalker()->mapField($field, $value, fn (Leaf $leaf) => "«{$leaf->text}»"))
        ->toBe("<blockquote class=\"note\">\n«<p>Calm</p>»\n</blockquote><hr><p><img src=\"/a.jpg\"></p>«Loose <b>text</b><h2>Spa</h2>»");
});

it('makes one inline leaf of a field with the inline option', function () {
    $field = ContentFixtures::field('bard-inline');
    $value = ContentFixtures::value('bard-inline');

    expect(bardLeaves($field, $value))->toBe([['inline', 'Rooms <1>by the sea</1> in <2>Chania</2><br/><3>since 1987</3>', 'Tagline', null]])
        ->and(bardWalker()->inject($field, $value, ['<3>Από το 1987</3><br/>Δωμάτια <1>δίπλα στη θάλασσα</1> στα <2>Χανιά</2>']))->toBe([
            ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'Από το 1987'],
            ['type' => 'hardBreak'],
            ['type' => 'text', 'text' => 'Δωμάτια '],
            ['type' => 'text', 'marks' => [['type' => 'italic']], 'text' => 'δίπλα στη θάλασσα'],
            ['type' => 'text', 'text' => ' στα '],
            ['type' => 'text', 'marks' => $value[3]['marks'], 'text' => 'Χανιά'],
        ]);
});
