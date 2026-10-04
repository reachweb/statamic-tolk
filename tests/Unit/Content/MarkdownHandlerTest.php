<?php

use Reach\StatamicTolk\Content\ContentWalker;
use Reach\StatamicTolk\Content\FieldtypeRegistry;
use Reach\StatamicTolk\Content\Handlers\MarkdownHandler;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\Markup\Placeholders;
use Reach\StatamicTolk\Enums\SegmentKind;
use Reach\StatamicTolk\Tests\Support\ContentFixtures;
use Statamic\Fields\Field;

function markdownField(array $config = []): Field
{
    return new Field('body', ['type' => 'markdown', 'display' => 'Body', ...$config]);
}

/**
 * @return list<string>
 */
function markdownLeafTexts(string $value, ?Field $field = null): array
{
    return array_map(fn (Leaf $leaf) => $leaf->text, app(ContentWalker::class)->leaves($field ?? markdownField(), $value));
}

function markdownMarked(string $value, ?Field $field = null): string
{
    return app(ContentWalker::class)->mapField($field ?? markdownField(), $value, fn (Leaf $leaf) => "[el] {$leaf->text}");
}

it('is registered for markdown at boot', function () {
    expect(app(FieldtypeRegistry::class)->for(markdownField()))->toBeInstanceOf(MarkdownHandler::class);
});

it('returns an identical value when every leaf keeps its text', function (string $value) {
    $field = markdownField();
    $walker = app(ContentWalker::class);
    $leaves = $walker->leaves($field, $value);

    expect($walker->mapField($field, $value, fn (Leaf $leaf) => $leaf->text))->toBe($value)
        ->and($walker->inject($field, $value, array_map(fn (Leaf $leaf) => $leaf->text, $leaves)))->toBe($value);
})->with(fn () => [
    ...ContentFixtures::markup('md'),
    'markdown-long' => ContentFixtures::value('markdown-long'),
    'empty' => '',
    'whitespace around blocks' => "\n\n  Indented first line\n\nTrailing spaces   \n\n",
    'windows line breaks' => "# Title\r\n\r\nLine one  \r\nLine two\r\n",
]);

it('makes one markdown leaf per block of the markdown-long fixture', function () {
    $field = ContentFixtures::field('markdown-long');
    $leaves = app(ContentWalker::class)->leaves($field, ContentFixtures::value('markdown-long'));

    expect(array_map(fn (Leaf $leaf) => $leaf->text, $leaves))->toBe([
        '# Hiking the Samaria Gorge',
        "The Samaria Gorge is **16 kilometres** long and runs from the Omalos plateau down to the *Libyan Sea* at Agia Roumeli. Most people walk it in five to seven hours.<x1/>\nBring water, a hat and good shoes.",
        '## Before you go',
        'The gorge is open from **1 May** to **31 October**, weather permitting. Check the [national park](<x1/> "Samaria National Park") on the morning of your walk, or ask at [the front desk](<x2/>).',
        "1. Book the early bus to Omalos.\n2. Buy your ticket at the entrance (€10, cash only).\n3. Walk down, never up:\n   - the path drops 1,250 metres;\n   - the last boat from Agia Roumeli leaves at 17:30.",
        "> Start early. The first two hours are in the shade, and the narrow *Sideroportes* gate is quiet before ten.\n>\n> — Katerina, who has walked it forty times",
        '![The Iron Gates of the gorge](<x1/> "Sideroportes, the narrowest point")',
        '## What to pack',
        "| Item | Why |\n| --- | --- |\n| Water | There are springs, but carry 1.5 l |\n| Shoes | The riverbed is **loose stone** |\n| Cash | For the boat and the taverna |",
        'Our packing list, ready for your notes app:',
        "Use the code <x1/> for the hotel's packed lunch, or see the [lunch menu]<x2/>.",
        "<1>\nClosed on days of high wind: <2>call before you leave</2>.\n</1>",
        '## After the walk',
        'The boat from Agia Roumeli stops in Sougia and Chora Sfakion, where the bus back to Chania waits for it. You will be home in time for dinner; the kitchen keeps a table for walkers until 22:30.',
        'If your knees disagree, the spa has a *sports massage* for exactly this day. Call <x1/> to book it.',
        'Questions? Write to <x1/> or visit <x2/>.',
    ])
        ->and(array_unique(array_map(fn (Leaf $leaf) => $leaf->kind, $leaves), SORT_REGULAR))->toBe([SegmentKind::Markdown])
        ->and($leaves[3]->placeholders)->toBe(['x1' => 'https://www.samaria.gr/', 'x2' => 'statamic://entry::51c8a58f-8a3d-47fa-90cc-91f543aa618f'])
        ->and($leaves[0]->path)->toBe(['markdown_long'])
        ->and($leaves[0]->label)->toBe('Body');
});

it('writes translated blocks back between the original separators', function () {
    $value = "# Title\n\nFirst paragraph,\nstill the first.\n  \n\n```\ncode\n\nmore\n```\n\n---\n\n  Indented once.\n";

    expect(markdownLeafTexts($value))->toBe(['# Title', "First paragraph,\nstill the first.", 'Indented once.'])
        ->and(markdownMarked($value))->toBe("[el] # Title\n\n[el] First paragraph,\nstill the first.\n  \n\n```\ncode\n\nmore\n```\n\n---\n\n  [el] Indented once.\n");
});

it('restores the atoms of a translation that moved them', function () {
    $value = 'Book at [the desk](/desk "Front desk") or call {{ phone }} with code `SPA`.';

    expect(app(ContentWalker::class)->inject(markdownField(), $value, ['Καλέστε <x2/> με τον κωδικό <x3/> ή κλείστε στη [ρεσεψιόν](<x1/> "Ρεσεψιόν").']))
        ->toBe('Καλέστε {{ phone }} με τον κωδικό `SPA` ή κλείστε στη [ρεσεψιόν](/desk "Ρεσεψιόν").');
});

it('never cuts a block inside a fence, and a block that is only a fence or atoms is not a leaf', function () {
    $value = ContentFixtures::markup('md')['code-fences.md'];

    expect(markdownLeafTexts($value))->toBe([
        'Paste the booking widget into any page:',
        'Then use <x1/> in a sentence, or <x2/>.',
        'The end.',
    ])->and(markdownLeafTexts("Text before:\n```\nfirst\n\nsecond\n```\nText after."))->toBe(['Text before:', 'Text after.']);
});

it('leaves blocks without translatable text out', function (string $block) {
    expect(markdownLeafTexts("Intro.\n\n{$block}\n\nOutro."))->toBe(['Intro.', 'Outro.']);
})->with([
    'a rule' => '---',
    'an image without alt text' => '![](/img/pool.jpg)',
    'an Antlers tag' => '{{ partial:booking }}',
    'a comment' => '<!-- note -->',
    'a definition' => '[menu]: /menu "Menu"',
    'an autolink' => '<https://kyma-bay.example>',
    'a URL' => 'https://kyma-bay.example/rooms',
    'a heading of only an Antlers tag' => '## {{ title }}',
    'a script' => "<script>\nlet a = 1;\n\nlet b = 2;\n</script>",
]);

it('treats an indented block as code outside a list and as a continuation inside one', function () {
    expect(markdownLeafTexts(ContentFixtures::markup('md')['code-fences.md']))->not->toContain('    Indented code also stays as it is.')
        ->and(markdownLeafTexts("A paragraph.\n\n    indented code\n\n\tTabbed code"))->toBe(['A paragraph.'])
        ->and(markdownLeafTexts("- An item\n\n    Its second paragraph.\n\n   Still the item.\n\n      Code in the item.\n\nAfter the list.\n\n    Code again."))
        ->toBe(['- An item', 'Its second paragraph.', 'Still the item.', 'After the list.'])
        ->and(markdownLeafTexts("1. Install it:\n\n       composer require tolk\n\n2. Run it."))->toBe(['1. Install it:', '2. Run it.'])
        ->and(markdownLeafTexts("Steps:\n- One\n  - Nested\n\n    Nested paragraph."))->toBe(["Steps:\n- One\n  - Nested", 'Nested paragraph.']);
});

it('keeps statamic:// destinations out of every leaf', function () {
    $texts = implode("\n", [
        ...markdownLeafTexts(ContentFixtures::value('markdown-long')),
        ...array_merge(...array_values(array_map(fn (string $sample) => markdownLeafTexts($sample), ContentFixtures::markup('md')))),
    ]);

    expect($texts)->not->toContain('statamic://')
        ->and($texts)->not->toContain('/img/');
});

it('rejects a translation of a leaf that adds a link', function () {
    $leaf = app(ContentWalker::class)->leaves(markdownField(), 'Check the [national park](https://www.samaria.gr/ "Samaria") first.')[0];

    expect($leaf->text)->toBe('Check the [national park](<x1/> "Samaria") first.')
        ->and(Placeholders::validate($leaf->text, 'Δείτε το [εθνικό πάρκο](<x1/> "Σαμαριά") πρώτα.', SegmentKind::Markdown))->toBeNull()
        ->and(Placeholders::validate($leaf->text, 'Δείτε το [εθνικό πάρκο](<x1/> "Σαμαριά") πρώτα. [click](javascript:alert(1))', SegmentKind::Markdown))
        ->toBe('A link destination is not a placeholder.');
});

it('makes the same number of leaves for a short and a long version of the same structure', function () {
    $structure = fn (string $text) => "# {$text}\n\n{$text}\n\n- {$text}\n- {$text}\n\n```\n{$text}\n```\n\n> {$text}\n\n| A | B |\n| --- | --- |\n| {$text} | {$text} |";
    $long = str_repeat('A sentence about the gorge that goes on and on. ', 200);

    expect(markdownLeafTexts($structure('Short')))->toHaveCount(5)
        ->and(markdownLeafTexts($structure($long)))->toHaveCount(5)
        ->and(markdownLeafTexts($structure(implode("\n", array_fill(0, 50, 'Line.')))))->toHaveCount(5);
});

it('reads the same leaves from a translation as from its source', function () {
    $value = ContentFixtures::value('markdown-long');
    $translated = markdownMarked($value);

    expect(markdownLeafTexts($translated))->toBe(array_map(fn (string $text) => "[el] {$text}", markdownLeafTexts($value)));
});

it('keeps collapsed and shortcut references pointing at their definitions', function () {
    $value = ContentFixtures::markup('md')['reference-definition.md'];

    expect(markdownLeafTexts($value))->toBe(['Read [the dinner menu]<x1/>, the [price list]<x2/> or [our story]<x3/>.'])
        ->and(app(ContentWalker::class)->inject(markdownField(), $value, ['Διαβάστε [το μενού]<x1/>, τον [τιμοκατάλογο]<x2/> ή [την ιστορία μας]<x3/>.']))
        ->toBe("Διαβάστε [το μενού][menu], τον [τιμοκατάλογο][price list] ή [την ιστορία μας][our story].\n\n[menu]: statamic://entry::ac2b1a25-e593-4b80-94a2-a97336a0b085 \"Dinner menu\"\n[price list]: <statamic://asset::assets::price-list.pdf>\n[our story]: /about\n  'About Kyma Bay'\n");
});

it('takes the definitions of the whole document, wherever they are', function () {
    $value = "[sea]: /sea\n\nSee [sea] and [sky].\n\n[sky]: /sky";

    expect(markdownLeafTexts($value))->toBe(['See [sea]<x1/> and [sky]<x2/>.']);
});

it('passes the translation hint and no character limit', function () {
    $leaf = app(ContentWalker::class)->leaves(markdownField(['tolk_hint' => '  Keep it short ', 'character_limit' => 50]), 'Rooms by the sea')[0];

    expect($leaf->hint)->toBe('Keep it short')
        ->and($leaf->maxLength)->toBeNull()
        ->and($leaf->kind)->toBe(SegmentKind::Markdown);
});

it('leaves values that are not strings untouched', function (mixed $value) {
    expect(app(ContentWalker::class)->leaves(markdownField(), $value))->toBe([])
        ->and(app(ContentWalker::class)->mapField(markdownField(), $value, fn () => 'X'))->toBe($value);
})->with(['null' => null, 'an integer' => 2026, 'an array' => [['text' => 'Home']]]);
