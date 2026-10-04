<?php

use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\Markup\InlineMarkup;
use Reach\StatamicTolk\Content\Markup\Placeholders;
use Reach\StatamicTolk\Content\Markup\Renumber;
use Reach\StatamicTolk\Content\Markup\Serialized;
use Reach\StatamicTolk\Enums\SegmentKind;
use Reach\StatamicTolk\Tests\Support\ContentFixtures;

const INLINE_BOLD = ['type' => 'bold'];
const INLINE_ITALIC = ['type' => 'italic'];
const INLINE_CODE = ['type' => 'code'];
const INLINE_LINK = ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::rooms']];

function inlineText(string $text, array $marks = []): array
{
    return $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'marks' => $marks, 'text' => $text];
}

function inlineLeaf(Serialized $serialized): Leaf
{
    return new Leaf($serialized->text, SegmentKind::Inline, ['content', 0], 'Content', placeholders: $serialized->placeholders);
}

/**
 * Every list of inline nodes in a Bard value: the content of each node whose children are all inline.
 *
 * @return array<string, list<array>>
 */
function inlineContents(array $nodes, string $path = 'content'): array
{
    $contents = [];

    foreach ($nodes as $index => $node) {
        $content = $node['content'] ?? null;

        if (! is_array($content) || $content === []) {
            continue;
        }

        $inline = array_diff(array_column($content, 'type'), ['text', 'hardBreak', 'image']) === [];
        $contents = [...$contents, ...($inline ? ["{$path}.{$index}" => $content] : inlineContents($content, "{$path}.{$index}"))];
    }

    return $contents;
}

function inlineFixtureContents(): array
{
    return [
        'bard-inline' => [ContentFixtures::value('bard-inline')],
        ...array_map(fn (array $content) => [$content], inlineContents(ContentFixtures::value('bard-rich'))),
    ];
}

it('makes a run with bold and a link one pair whose marks are in the map', function () {
    $marks = [
        INLINE_BOLD,
        ['type' => 'link', 'attrs' => ['href' => 'statamic://asset::assets::price-list.pdf', 'rel' => null, 'target' => '_blank', 'title' => null]],
    ];
    $serialized = InlineMarkup::serialize([inlineText('Download the '), inlineText('price list', $marks), inlineText('.')]);

    expect($serialized->text)->toBe('Download the <1>price list</1>.')
        ->and($serialized->map)->toBe([1 => $marks])
        ->and($serialized->placeholders)->toBe([
            1 => '[{"type":"bold"},{"attrs":{"href":"statamic://asset::assets::price-list.pdf","target":"_blank"},"type":"link"}]',
        ])
        ->and(InlineMarkup::parse('Κατεβάστε τον <1>τιμοκατάλογο</1>.', $serialized))->toBe([
            inlineText('Κατεβάστε τον '),
            inlineText('τιμοκατάλογο', $marks),
            inlineText('.'),
        ]);
});

it('gives adjacent runs with different marks a pair each and joins nodes with equal marks into one run', function () {
    $serialized = InlineMarkup::serialize([
        inlineText('Always', [INLINE_BOLD]),
        inlineText(' fresh', [INLINE_ITALIC]),
        inlineText(' bread', [INLINE_BOLD, INLINE_ITALIC]),
        inlineText(' from', [INLINE_BOLD]),
        inlineText(' the oven', [INLINE_BOLD]),
        inlineText('.'),
    ]);

    expect($serialized->text)->toBe('<1>Always</1><2> fresh</2><3> bread</3><4> from the oven</4>.')
        ->and($serialized->map)->toBe([1 => [INLINE_BOLD], 2 => [INLINE_ITALIC], 3 => [INLINE_BOLD, INLINE_ITALIC], 4 => [INLINE_BOLD]])
        ->and($serialized->placeholders)->toBe([1 => '[{"type":"bold"}]', 2 => '[{"type":"italic"}]', 3 => '[{"type":"bold"},{"type":"italic"}]', 4 => '[{"type":"bold"}]']);
});

it('makes a hard break <br/> and gives back the source break nodes in order', function () {
    $marked = ['type' => 'hardBreak', 'marks' => [INLINE_BOLD]];
    $serialized = InlineMarkup::serialize([
        inlineText('Kyma Bay Hotel'),
        ['type' => 'hardBreak'],
        inlineText('Akti Koundourioti 12', [INLINE_BOLD]),
        $marked,
        inlineText('Chania', [INLINE_BOLD]),
    ]);

    expect($serialized->text)->toBe('Kyma Bay Hotel<br/><1>Akti Koundourioti 12</1><br/><2>Chania</2>')
        ->and($serialized->breaks)->toBe([['type' => 'hardBreak'], $marked])
        ->and(InlineMarkup::parse('Ξενοδοχείο Kyma Bay<br/><1>Ακτή Κουντουριώτη 12</1><br/><2>Χανιά</2>', $serialized))->toBe([
            inlineText('Ξενοδοχείο Kyma Bay'),
            ['type' => 'hardBreak'],
            inlineText('Ακτή Κουντουριώτη 12', [INLINE_BOLD]),
            $marked,
            inlineText('Χανιά', [INLINE_BOLD]),
        ]);
});

it('carries an inline image as an atom whose descriptor leaves out its alt text', function () {
    $image = ['type' => 'image', 'attrs' => ['src' => 'asset::assets::sign.png', 'alt' => 'A blue wave on a white sign']];
    $serialized = InlineMarkup::serialize([inlineText('Look for this sign at the harbour: '), $image]);

    expect($serialized->text)->toBe('Look for this sign at the harbour: <x1/>')
        ->and($serialized->map)->toBe(['x1' => $image])
        ->and($serialized->placeholders)->toBe(['x1' => '{"attrs":{"src":"asset::assets::sign.png"},"type":"image"}'])
        ->and(InlineMarkup::parse('<x1/> Αναζητήστε αυτή την πινακίδα στο λιμάνι.', $serialized))->toBe([
            $image,
            inlineText(' Αναζητήστε αυτή την πινακίδα στο λιμάνι.'),
        ]);
});

it('makes a code-marked run an atom that is never translated', function () {
    $code = inlineText('kyma-guest-2026', [INLINE_CODE]);
    $serialized = InlineMarkup::serialize([inlineText('Wi-Fi password: '), $code, inlineText(' at the desk')]);

    expect($serialized->text)->toBe('Wi-Fi password: <x1/> at the desk')
        ->and($serialized->map)->toBe(['x1' => $code])
        ->and($serialized->placeholders)->toBe(['x1' => '{"marks":[{"type":"code"}],"text":"kyma-guest-2026","type":"text"}'])
        ->and(InlineMarkup::parse('Κωδικός Wi-Fi: <x1/> στη ρεσεψιόν', $serialized))->toBe([
            inlineText('Κωδικός Wi-Fi: '),
            $code,
            inlineText(' στη ρεσεψιόν'),
        ]);
});

it('makes a code run of several nodes one atom holding their text', function () {
    $serialized = InlineMarkup::serialize([inlineText('npm ', [INLINE_BOLD, INLINE_CODE]), inlineText('install', [INLINE_BOLD, INLINE_CODE])]);

    expect($serialized->text)->toBe('<x1/>')
        ->and($serialized->map)->toBe(['x1' => inlineText('npm install', [INLINE_BOLD, INLINE_CODE])]);
});

it('carries any other inline node, and a text node with keys of its own, whole as an atom', function () {
    $mention = ['type' => 'mention', 'attrs' => ['id' => 5, 'label' => 'Maria']];
    $custom = ['type' => 'text', 'text' => 'Chania', 'attrs' => ['lang' => 'el']];
    $serialized = InlineMarkup::serialize([inlineText('Ask '), $mention, inlineText(' about '), $custom]);

    expect($serialized->text)->toBe('Ask <x1/> about <x2/>')
        ->and($serialized->map)->toBe(['x1' => $mention, 'x2' => $custom])
        ->and(InlineMarkup::parse('Ρωτήστε <x1/> για <x2/>', $serialized))->toBe([inlineText('Ρωτήστε '), $mention, inlineText(' για '), $custom]);
});

it('makes Antlers tags atoms inside and outside runs and puts them back as text', function () {
    $serialized = InlineMarkup::serialize([inlineText('Welcome to {{ site:name }}', [INLINE_BOLD]), inlineText(' in {{ city }}.')]);

    expect($serialized->text)->toBe('<1>Welcome to <x1/></1> in <x2/>.')
        ->and($serialized->map)->toBe([1 => [INLINE_BOLD], 'x1' => '{{ site:name }}', 'x2' => '{{ city }}'])
        ->and($serialized->placeholders)->toBe([1 => '[{"type":"bold"}]', 'x1' => '{{ site:name }}', 'x2' => '{{ city }}'])
        ->and(InlineMarkup::parse('<1>Καλώς ήρθατε στο <x1/></1> στην πόλη <x2/>.', $serialized))->toBe([
            inlineText('Καλώς ήρθατε στο {{ site:name }}', [INLINE_BOLD]),
            inlineText(' στην πόλη {{ city }}.'),
        ]);
});

it('rebuilds the runs in the order of the translation', function () {
    $serialized = InlineMarkup::serialize([
        inlineText('Book a '),
        inlineText('sea view suite', [INLINE_BOLD]),
        inlineText(' near the '),
        inlineText('harbour', [INLINE_LINK]),
        inlineText('.'),
    ]);
    $translation = 'Κοντά στο <2>λιμάνι</2>, κλείστε μια <1>σουίτα με θέα</1>.';

    expect($serialized->text)->toBe('Book a <1>sea view suite</1> near the <2>harbour</2>.')
        ->and(Placeholders::validate($serialized->text, $translation, SegmentKind::Inline))->toBeNull()
        ->and(InlineMarkup::parse($translation, $serialized))->toBe([
            inlineText('Κοντά στο '),
            inlineText('λιμάνι', [INLINE_LINK]),
            inlineText(', κλείστε μια '),
            inlineText('σουίτα με θέα', [INLINE_BOLD]),
            inlineText('.'),
        ]);
});

it('gives a run split in two two nodes with the same marks, and joins occurrences that touch', function () {
    $serialized = InlineMarkup::serialize([inlineText('Free parking', [INLINE_BOLD]), inlineText(' for guests.')]);
    $split = 'Δωρεάν <1>στάθμευση</1> για <1>επισκέπτες</1>.';

    expect(Placeholders::validate($serialized->text, $split, SegmentKind::Inline))->toBeNull()
        ->and(InlineMarkup::parse($split, $serialized))->toBe([
            inlineText('Δωρεάν '),
            inlineText('στάθμευση', [INLINE_BOLD]),
            inlineText(' για '),
            inlineText('επισκέπτες', [INLINE_BOLD]),
            inlineText('.'),
        ])
        ->and(InlineMarkup::parse('<1>Δωρεάν </1><1>στάθμευση</1> για επισκέπτες.', $serialized))->toBe([
            inlineText('Δωρεάν στάθμευση', [INLINE_BOLD]),
            inlineText(' για επισκέπτες.'),
        ]);
});

it('escapes a literal <, > and & and unescapes them in the translation', function () {
    $serialized = InlineMarkup::serialize([inlineText('Kids < 12 & seniors > 65 '), inlineText('stay <free>', [INLINE_BOLD]), inlineText(', type <1> or &amp;')]);

    expect($serialized->text)->toBe('Kids &lt; 12 &amp; seniors &gt; 65 <1>stay &lt;free&gt;</1>, type &lt;1&gt; or &amp;amp;')
        ->and(Placeholders::validate($serialized->text, 'Παιδιά &lt; 12 &amp; <1>δωρεάν &lt;free&gt;</1>, &lt;1&gt; ή &amp;amp;', SegmentKind::Inline))->toBeNull()
        ->and(InlineMarkup::parse('Παιδιά &lt; 12 &amp; <1>δωρεάν &lt;free&gt;</1>, &lt;1&gt; ή &amp;amp;', $serialized))->toBe([
            inlineText('Παιδιά < 12 & '),
            inlineText('δωρεάν <free>', [INLINE_BOLD]),
            inlineText(', <1> ή &amp;'),
        ]);
});

it('gives back the same array when the text is unchanged', function (array $nodes) {
    $serialized = InlineMarkup::serialize($nodes);

    expect(InlineMarkup::parse($serialized->text, $serialized))->toBe($nodes)
        ->and($serialized->nodes)->toBe($nodes);
})->with(fn () => inlineFixtureContents());

it('rebuilds every fixture paragraph from its own text', function (array $nodes) {
    $serialized = InlineMarkup::serialize($nodes);
    $rebuilt = new Serialized('', $serialized->map, $serialized->placeholders, [], $serialized->breaks);

    expect(InlineMarkup::parse($serialized->text, $rebuilt))->toBe($nodes);
})->with(fn () => inlineFixtureContents());

it('serializes the fixtures to the expected texts', function () {
    $rich = inlineContents(ContentFixtures::value('bard-rich'));

    expect(InlineMarkup::serialize(ContentFixtures::value('bard-inline'))->text)->toBe('Rooms <1>by the sea</1> in <2>Chania</2><br/><3>since 1987</3>')
        ->and(InlineMarkup::serialize($rich['content.1'])->text)->toBe("Since 1987 the Markakis family has welcomed guests to the <1>old harbour</1> of Chania. Breakfast is served until <2>eleven o'clock</2>, <3>always</3> with bread from the oven next door.")
        ->and(InlineMarkup::serialize($rich['content.3'])->text)->toBe('Kyma Bay Hotel<br/>Akti Koundourioti 12, Chania<br/>Wi-Fi password: <x1/> (kids &lt; 12 &amp; pets stay free)');
});

it('drops empty text nodes and orders the keys of new nodes type, marks, text', function () {
    $serialized = InlineMarkup::serialize([inlineText('Rooms', [INLINE_ITALIC]), inlineText(''), inlineText(' by the sea')]);
    $nodes = InlineMarkup::parse('<1></1>Δωμάτια δίπλα στη θάλασσα', $serialized);

    expect($serialized->text)->toBe('<1>Rooms</1> by the sea')
        ->and($nodes)->toBe([inlineText('Δωμάτια δίπλα στη θάλασσα')])
        ->and(array_keys(InlineMarkup::parse('<1>Δωμάτια</1>', $serialized)[0]))->toBe(['type', 'marks', 'text']);
});

it('leaves a placeholder the source does not have in the text', function () {
    $serialized = InlineMarkup::serialize([inlineText('Rooms', [INLINE_BOLD])]);

    expect(InlineMarkup::parse('<1>Δωμάτια</1> <7>x</7> <x9/>', $serialized))->toBe([
        inlineText('Δωμάτια', [INLINE_BOLD]),
        inlineText(' <7>x</7> <x9/>'),
    ]);
});

it('serializes an empty list to an empty text', function () {
    $serialized = InlineMarkup::serialize([]);

    expect($serialized->text)->toBe('')
        ->and(InlineMarkup::parse('', $serialized))->toBe([]);
});

it('gives a target leaf whose runs the translation reordered the numbering of its source', function () {
    $source = InlineMarkup::serialize([
        inlineText('Book a '),
        inlineText('sea view suite', [INLINE_BOLD]),
        inlineText(' near the '),
        inlineText('harbour', [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::rooms', 'title' => 'Map of the harbour']]]),
        inlineText('.'),
    ]);
    // The stored translation, after a CP save added the link's empty attributes and its title was translated.
    $target = InlineMarkup::serialize([
        inlineText('Κοντά στο '),
        inlineText('λιμάνι', [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::rooms', 'rel' => null, 'target' => null, 'title' => 'Χάρτης του λιμανιού']]]),
        inlineText(', κλείστε μια '),
        inlineText('σουίτα με θέα', [INLINE_BOLD]),
        inlineText('.'),
    ]);

    expect($target->text)->toBe('Κοντά στο <1>λιμάνι</1>, κλείστε μια <2>σουίτα με θέα</2>.')
        ->and(Renumber::like(inlineLeaf($target), inlineLeaf($source)))->toBe('Κοντά στο <2>λιμάνι</2>, κλείστε μια <1>σουίτα με θέα</1>.');
});

it('offers each link title once and writes the translation into every mark of that link', function () {
    $link = ['type' => 'link', 'attrs' => ['href' => 'https://www.chania-port.example/ferries', 'rel' => null, 'target' => '_blank', 'title' => 'Ferry timetable']];
    $other = ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::rooms', 'title' => 'Our rooms']];
    $untitled = ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::spa', 'title' => '  ']];
    $nodes = [
        inlineText('Check the ', []),
        inlineText('ferry', [$link]),
        inlineText(' timetable', [INLINE_BOLD, $link]),
        inlineText(', our '),
        inlineText('rooms', [$other]),
        inlineText(' and the '),
        inlineText('spa', [$untitled]),
    ];
    $offered = [];

    $mapped = InlineMarkup::mapLinkTitles($nodes, function (string $title) use (&$offered): string {
        $offered[] = $title;

        return "[el] {$title}";
    });
    $translated = ['type' => 'link', 'attrs' => [...$link['attrs'], 'title' => '[el] Ferry timetable']];
    $serialized = InlineMarkup::serialize($mapped);

    expect($offered)->toBe(['Ferry timetable', 'Our rooms'])
        ->and($mapped[1]['marks'])->toBe([$translated])
        ->and($mapped[2]['marks'])->toBe([INLINE_BOLD, $translated])
        ->and($mapped[4]['marks'][0]['attrs'])->toBe(['href' => 'statamic://entry::rooms', 'title' => '[el] Our rooms'])
        ->and($mapped[6])->toBe($nodes[6])
        ->and($serialized->map[1])->toBe([$translated])
        ->and($serialized->placeholders)->toBe(InlineMarkup::serialize($nodes)->placeholders)
        ->and(InlineMarkup::parse($serialized->text, $serialized))->toBe($mapped)
        ->and(InlineMarkup::mapLinkTitles($nodes, fn (string $title) => $title))->toBe($nodes);
});
