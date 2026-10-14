<?php

use Random\Engine\Mt19937;
use Random\Randomizer;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\Markup\Placeholders;
use Reach\StatamicTolk\Content\Markup\Renumber;
use Reach\StatamicTolk\Content\Markup\Tokenized;
use Reach\StatamicTolk\Content\Markup\Tokenizer;
use Reach\StatamicTolk\Enums\SegmentKind;
use Reach\StatamicTolk\Tests\Support\ContentFixtures;
use Statamic\Facades\Markdown;

function tokenizeMarkdown(string $text): Tokenized
{
    return Tokenizer::tokenize($text, SegmentKind::Markdown);
}

function markdownSamples(): array
{
    return [
        ...ContentFixtures::markup('md'),
        'markdown-long' => ContentFixtures::value('markdown-long'),
    ];
}

it('restores every Markdown sample exactly', function (string $text) {
    $tokenized = tokenizeMarkdown($text);

    expect($tokenized->kind)->toBe(SegmentKind::Markdown)
        ->and(Tokenizer::restore($tokenized->text, $tokenized))->toBe($text)
        ->and(Placeholders::validate($tokenized->text, $tokenized->text, SegmentKind::Markdown))->toBeNull();
})->with(fn () => markdownSamples());

it('restores edge cases exactly', function (string $text) {
    $tokenized = tokenizeMarkdown($text);

    expect(Tokenizer::restore($tokenized->text, $tokenized))->toBe($text)
        ->and(Placeholders::validate($tokenized->text, $tokenized->text, SegmentKind::Markdown))->toBeNull();
})->with([
    'empty' => '',
    'text only' => 'Rooms by the sea',
    'entities and literal characters' => 'Tom &amp; Jerry &lt;3, kids < 12 & seniors > 65',
    'an unclosed fence' => "Before\n\n```js\nlet a = 1;\n\nlet b = 2;",
    'an empty fence' => "```\n```\nAfter",
    'a fence with a longer closing' => "````\ncode\n``````\nAfter",
    'unmatched backticks' => 'One ` and two `` here',
    'escaped characters' => 'Not \\[a link\\](/url), not \\`code\\` and not \\<b>bold\\</b>',
    'an empty destination' => 'An [empty]() link',
    'an unclosed angle destination' => 'A [link](<mailto:x and more',
    'a destination with parentheses' => 'See [Chania](https://en.wikipedia.org/wiki/Chania_(city) "Wiki") now',
    'a link across lines' => "A [long\nlink](\n/about\n\"Title\") here",
    'a footnote' => "Text[^1] here.\n\n[^1]: The footnote text.",
    'a definition that is not one' => '[Note]: this is important',
    'windows line breaks' => "Line one  \r\nLine two\r\n\r\n```\r\ncode\r\n```\r\n\r\n[a]: /url\r\n",
    'antlers holding markdown' => 'Hi {{ name | replace("[a](b)", "`c`") }} there',
    'a heading with attributes' => '## Before you go {#before .wide}',
    'text shaped like placeholders behind backslashes' => 'Type \\<x1/>, \\<1>, \\</1> or \\\\<x2/> next to `code` and <b>bold</b>',
]);

// Behind a backslash, such text would stay in the segment and read as a placeholder: as one the tokenizer also made,
// so the block could never validate, or a translation keeping only the escaped one would put a code span behind it.
it('carries text that looks like a placeholder as an atom, also behind a backslash', function () {
    $tokenized = tokenizeMarkdown('Type \\<x1/>, \\<1>bold\\</1> or \\\\<x2/> next to `code`');

    expect($tokenized->text)->toBe('Type \\<x1/>, \\<x2/>bold\\<x3/> or \\\\<x4/> next to <x5/>')
        ->and($tokenized->map)->toBe(['x1' => '<x1/>', 'x2' => '<1>', 'x3' => '</1>', 'x4' => '<x2/>', 'x5' => '`code`'])
        ->and(Placeholders::validate($tokenized->text, $tokenized->text, SegmentKind::Markdown))->toBeNull()
        ->and(Placeholders::validate($tokenized->text, 'Γράψτε \\<x1/>, \\<x2/>bold\\<x3/> ή \\\\<x4/>', SegmentKind::Markdown))->toBe('<x5/> is missing.')
        ->and(Tokenizer::restore('Δίπλα στο <x5/>, γράψτε \\<x1/>, \\<x2/>bold\\<x3/> ή \\\\<x4/>', $tokenized))
        ->toBe('Δίπλα στο `code`, γράψτε \\<x1/>, \\<1>bold\\</1> ή \\\\<x2/>');
});

// Markdown, its escapes and Tolk's own placeholders, joined at random: a segment holds only the placeholders the
// tokenizer made, each once, so it validates against itself and restores exactly.
it('never leaves text that reads as a placeholder in a segment', function () {
    $fragments = ['\\', '\\\\', '<x1/>', '<x2/>', '<1>', '</1>', '<2>', '</2>', '<br/>', '<br>', '<b>', '</b>', '<!--', '-->', '`', '``', '[', ']', '](', ']:', '(', ')', '{', '}', '{.c}', '{{ a }}', '[a]: /u', '[^1]', '<https://a.b>', '*', '"', '=', '<', '>', '/', ' ', '  ', "\n", 'text'];
    $random = new Randomizer(new Mt19937(37));
    $failing = [];

    for ($i = 0; $i < 4000; $i++) {
        $text = implode('', array_map(fn () => $fragments[$random->getInt(0, count($fragments) - 1)], range(1, $random->getInt(1, 10))));
        $tokenized = tokenizeMarkdown($text);
        preg_match_all('/'.Placeholders::IDS.'/', $tokenized->text, $placeholders);
        $ids = array_map(fn (string $slash, string $pair, string $atom) => $slash.$pair.$atom, ...array_slice($placeholders, 1));

        if (Tokenizer::restore($tokenized->text, $tokenized) !== $text
            || Placeholders::validate($tokenized->text, $tokenized->text, SegmentKind::Markdown) !== null
            || count($ids) !== count(array_unique($ids))
            || array_diff($ids, array_map('strval', array_keys($tokenized->map))) !== []) {
            $failing[] = $text;
        }
    }

    expect($failing)->toBe([]);
});

it('makes fences atoms, whatever they hold', function () {
    $tokenized = tokenizeMarkdown(ContentFixtures::markup('md')['code-fences.md']);

    expect($tokenized->text)->toBe("Paste the booking widget into any page:\n\n<x1/>\n\nThen use <x2/> in a sentence, or <x3/>.\n\n<x4/>\n\n    Indented code also stays as it is.\n\nThe end.\n")
        ->and($tokenized->map['x1'])->toBe("```html\n<div class=\"booking\">\n  {{ partial:booking }}\n</div>\n\n<p>This is code, not a paragraph.</p>\n```")
        ->and($tokenized->map['x4'])->toBe("~~~\nA tilde fence can hold ``` without closing.\n\nStill inside the fence.\n~~~");
});

it('ends a fence only at a closing line of its own character, at least as long', function (string $text, string $expected) {
    expect(tokenizeMarkdown($text)->text)->toBe($expected);
})->with([
    'an unclosed fence runs to the end' => ["Before\n\n```\ncode\n\nAfter", "Before\n\n<x1/>"],
    'a shorter closing does not close' => ["````\ncode\n```\nstill code\n````\nAfter", "<x1/>\nAfter"],
    'a closing with text after it does not close' => ["```\ncode\n``` text\nmore\n```\nAfter", "<x1/>\nAfter"],
    'a backtick info string holding a backtick is no fence' => ["```a`b```\n\nText", "<x1/>\n\nText"],
    'a fence in a blockquote' => ["> Quote\n> ```\n> code\n> ```\n> More", "> Quote\n<x1/>\n> More"],
    'a fence in a list item' => ["1. Run:\n   ```bash\n   npm install\n   ```\n2. Done", "1. Run:\n<x1/>\n2. Done"],
]);

it('makes code spans atoms and leaves unmatched backticks as text', function () {
    $tokenized = tokenizeMarkdown('Use `{{ booking:form }}` or ``a `nested` span``, not \\`escaped\\` or ` alone.');

    expect($tokenized->text)->toBe('Use <x1/> or <x2/>, not \\`escaped\\` or ` alone.')
        ->and($tokenized->placeholders)->toBe(['x1' => '`{{ booking:form }}`', 'x2' => '``a `nested` span``']);
});

it('makes link and image destinations atoms right after "](" and leaves link text and titles translatable', function () {
    $tokenized = tokenizeMarkdown(ContentFixtures::markup('md')['links-with-titles.md']);

    expect($tokenized->text)->toBe("Read [our story](<x1/> \"About Kyma Bay\"), the [dinner menu](<x2/> 'Dinner menu (PDF)') or the [restaurant](<x3/> (Our restaurant)).\n\nOr [write to us](<x4/> \"Email the front desk\").\n")
        ->and($tokenized->placeholders)->toBe([
            'x1' => '/about',
            'x2' => 'statamic://asset::assets::menu.pdf',
            'x3' => 'statamic://entry::ac2b1a25-e593-4b80-94a2-a97336a0b085',
            'x4' => '<mailto:info@kyma-bay.example>',
        ]);
});

it('gives an image inside a link two destination atoms', function () {
    $tokenized = tokenizeMarkdown(ContentFixtures::markup('md')['images.md']);

    expect($tokenized->text)->toBe("![The saltwater pool at sunset](<x1/> \"The pool terrace\")\n\nLook for this sign at the harbour: ![A blue wave on a white sign](<x2/>), or follow the [![hotel logo](<x3/>)](<x4/>).\n")
        ->and(array_values($tokenized->placeholders))->toBe(['statamic://asset::assets::pool-terrace.jpg', '/img/sign.png', '/img/logo.svg', '/']);
});

// A Markdown field with `antlers: true` and `smartypants: true` parses its Antlers before its Markdown, so CommonMark
// reads the tag's output as the destination; whatever the order, the tag's parameters are never text.
it('makes a link or image destination holding an Antlers tag one atom, the tag\'s parameters included', function (string $text, string $tokenized, string $destination) {
    $result = tokenizeMarkdown($text);

    expect($result->text)->toBe($tokenized)
        ->and($result->map)->toBe(['x1' => $destination])
        ->and(Tokenizer::restore($result->text, $result))->toBe($text);
})->with([
    'a tag with a parameter' => ['Write to [the front desk]({{ link to="contact" }}).', 'Write to [the front desk](<x1/>).', '{{ link to="contact" }}'],
    'an image tag before a title' => ['![The pool]({{ glide:image width="800" }} "The pool")', '![The pool](<x1/> "The pool")', '{{ glide:image width="800" }}'],
    'a tag inside a path' => ['See [the suite](/rooms/{{ slug }}/book) now', 'See [the suite](<x1/>) now', '/rooms/{{ slug }}/book'],
    'a tag inside parentheses' => ['See [the suite](/rooms_({{ slug }})) now', 'See [the suite](<x1/>) now', '/rooms_({{ slug }})'],
    'a tag holding parentheses' => ['See [the suite]({{ link to="rooms" | replace("(", "") }}) now', 'See [the suite](<x1/>) now', '{{ link to="rooms" | replace("(", "") }}'],
    'a tag holding ">" in angle brackets' => ['See [the suite](<{{ count > 1 ? "/rooms" : "/room" }}>) now', 'See [the suite](<x1/>) now', '<{{ count > 1 ? "/rooms" : "/room" }}>'],
    'a tag across lines' => ["Write to [the front desk]({{ link\n  to=\"contact\" }}).", 'Write to [the front desk](<x1/>).', "{{ link\n  to=\"contact\" }}"],
]);

it('makes a reference definition whose destination holds an Antlers tag an atom, and reads its label', function () {
    $text = "Read [our story], [the menu][menu] or [the suite][].\n\n[our story]: {{ link to=\"about\" }}\n[menu]: {{ link to=\"menu\" }} \"Dinner menu\"\n[the suite]: /rooms/{{ slug }}/book";
    $tokenized = tokenizeMarkdown($text);

    expect(Tokenizer::referenceLabels($text))->toBe(['our story', 'menu', 'the suite'])
        ->and($tokenized->text)->toBe("Read [our story]<x1/>, [the menu]<x2/> or [the suite]<x3/>.\n\n<x4/>\n<x5/>\n<x6/>")
        ->and(array_slice($tokenized->map, 3))->toBe([
            'x4' => '[our story]: {{ link to="about" }}',
            'x5' => '[menu]: {{ link to="menu" }} "Dinner menu"',
            'x6' => '[the suite]: /rooms/{{ slug }}/book',
        ])
        ->and(Tokenizer::restore("Διαβάστε [την ιστορία μας]<x1/>, [το μενού]<x2/> ή [τη σουίτα]<x3/>.\n\n<x4/>\n<x5/>\n<x6/>", $tokenized))
        ->toStartWith('Διαβάστε [την ιστορία μας][our story], [το μενού][menu] ή [τη σουίτα][the suite].');
});

it('never lets a statamic:// destination reach the translatable text', function (string $text) {
    expect(tokenizeMarkdown($text)->text)->not->toContain('statamic://');
})->with(fn () => markdownSamples());

it('makes reference definitions atoms, a title on the next line included', function () {
    $tokenized = tokenizeMarkdown(ContentFixtures::markup('md')['reference-definition.md']);

    expect($tokenized->text)->toBe("Read [the dinner menu]<x1/>, the [price list]<x2/> or [our story]<x3/>.\n\n<x4/>\n<x5/>\n<x6/>\n")
        ->and($tokenized->map)->toBe([
            'x1' => '[menu]',
            'x2' => '[]',
            'x3' => '',
            'x4' => '[menu]: statamic://entry::ac2b1a25-e593-4b80-94a2-a97336a0b085 "Dinner menu"',
            'x5' => '[price list]: <statamic://asset::assets::price-list.pdf>',
            'x6' => "[our story]: /about\n  'About Kyma Bay'",
        ]);
});

it('keeps a collapsed or shortcut reference pointing at its definition once its text is translated', function () {
    $tokenized = tokenizeMarkdown(ContentFixtures::markup('md')['reference-definition.md']);

    expect($tokenized->placeholders)->toMatchArray(['x1' => '[menu]', 'x2' => '[price list]', 'x3' => '[our story]'])
        ->and($tokenized->references)->toBe(['x2' => '[price list]', 'x3' => '[our story]'])
        ->and(Tokenizer::restore("Διαβάστε [το μενού]<x1/>, τον [τιμοκατάλογο]<x2/> ή [την ιστορία μας]<x3/>.\n\n<x4/>\n<x5/>\n<x6/>\n", $tokenized))
        ->toStartWith('Διαβάστε [το μενού][menu], τον [τιμοκατάλογο][price list] ή [την ιστορία μας][our story].');
});

it('matches reference labels as CommonMark does and leaves brackets without a definition alone', function () {
    $tokenized = Tokenizer::markdown('See [Our  Story], [the menu][], [unknown][] and [sic] or ![Logo].', ['our story', 'logo']);

    expect($tokenized->text)->toBe('See [Our  Story]<x1/>, [the menu]<x2/>, [unknown]<x3/> and [sic] or ![Logo]<x4/>.')
        ->and($tokenized->references)->toBe(['x1' => '[Our  Story]', 'x4' => '[Logo]'])
        ->and($tokenized->placeholders)->toBe(['x1' => '[Our  Story]', 'x2' => '[]', 'x3' => '[]', 'x4' => '[Logo]']);
});

it('reads the reference labels of a document', function () {
    expect(Tokenizer::referenceLabels(ContentFixtures::markup('md')['reference-definition.md']))->toBe(['menu', 'price list', 'our story'])
        ->and(Tokenizer::referenceLabels("```\n[inside]: /a-fence\n```\n\n[Outside  Label]: /url\n[^1]: A footnote."))->toBe(['outside label']);
});

it('makes footnote references and autolinks atoms', function () {
    $tokenized = tokenizeMarkdown("Write to <info@kyma-bay.example> or visit <https://kyma-bay.example/contact>.[^1]\n\n[^1]: Open daily.");

    expect($tokenized->text)->toBe("Write to <x1/> or visit <x2/>.<x3/>\n\n<x4/>: Open daily.")
        ->and($tokenized->placeholders)->toBe(['x1' => '<info@kyma-bay.example>', 'x2' => '<https://kyma-bay.example/contact>', 'x3' => '[^1]', 'x4' => '[^1]']);
});

it('handles raw HTML as the html kind does', function () {
    $tokenized = tokenizeMarkdown("<div class=\"notice\">\nClosed: <strong>call first</strong>.<br>\n</div>\n\n<!-- checked -->\n\n<script>if (a < b) {}</script>");

    expect($tokenized->text)->toBe("<1>\nClosed: <2>call first</2>.<x1/>\n</1>\n\n<x2/>\n\n<x3/>")
        ->and($tokenized->placeholders)->toBe([1 => '<div class="notice">', 2 => '<strong>', 'x1' => '<br>', 'x2' => '<!-- checked -->', 'x3' => '<script>if (a < b) {}</script>'])
        ->and($tokenized->map['/1'])->toBe('</div>');
});

it('makes Antlers tags, hard breaks and attribute lists atoms', function () {
    $tokenized = tokenizeMarkdown("## Call {{ site:phone }} {#call}\n\nLine one  \nLine two\\\nLine three");

    expect($tokenized->text)->toBe("## Call <x1/> <x2/>\n\nLine one<x3/>\nLine two<x4/>\nLine three")
        ->and($tokenized->placeholders)->toBe(['x1' => '{{ site:phone }}', 'x2' => '{#call}', 'x3' => '  ', 'x4' => '\\']);
});

it('makes every attribute list that CommonMark reads an atom', function (string $text, string $tokenized, string $list, string $rendered) {
    $result = tokenizeMarkdown($text);

    expect(Markdown::parse($text))->toContain($rendered)
        ->and($result->text)->toBe($tokenized)
        ->and(array_values(array_filter($result->map, fn (string $original) => str_starts_with($original, '{') && ! str_starts_with($original, '{{'))))->toBe([$list])
        ->and(Tokenizer::restore($result->text, $result))->toBe($text);
})->with([
    'a name with a colon' => ['Open *the menu*{x-on:click="open = true"} now', 'Open *the menu*<x1/> now', '{x-on:click="open = true"}', 'x-on:click="open = true"'],
    'a name that starts with a colon' => ['Open *the menu*{:class="open"} now', 'Open *the menu*<x1/> now', '{:class="open"}', 'class="open"'],
    'spaces around the equals sign' => ['Open *the menu*{ data-state = "open" } now', 'Open *the menu*<x1/> now', '{ data-state = "open" }', 'data-state='],
    'a value holding braces' => ['Open *the menu*{x-data="{ open: false }"} now', 'Open *the menu*<x1/> now', '{x-data="{ open: false }"}', 'x-data="{ open: false }"'],
    'an id holding dots' => ['## Before you go {#before.you.go}', '## Before you go <x1/>', '{#before.you.go}', 'id="before.you.go"'],
    'a Kramdown colon' => ['Open *the menu*{: .wide} now', 'Open *the menu*<x1/> now', '{: .wide}', 'class="wide"'],
    'an unquoted value before a full stop' => ['Read [our story](/about){rel=nofollow}.', 'Read [our story](<x1/>)<x2/>.', '{rel=nofollow}', 'rel="nofollow"'],
    'an unquoted value holding a brace' => ['Open *the menu*{title=a}b} now', 'Open *the menu*<x1/> now', '{title=a}b}', 'title="a}b"'],
    'across two lines' => ["Open *the menu*{title=\"Menu\"\ndata-state=open} now", 'Open *the menu*<x1/> now', "{title=\"Menu\"\ndata-state=open}", 'data-state="open"'],
    'on its own line' => ["{.lead}\nOpen the menu now", "<x1/>\nOpen the menu now", '{.lead}', 'class="lead"'],
]);

it('leaves braces that CommonMark does not read as an attribute list as text', function (string $text) {
    expect(Markdown::parse($text))->toContain(htmlspecialchars($text, ENT_NOQUOTES))
        ->and(tokenizeMarkdown($text)->text)->toBe($text);
})->with([
    'words' => 'Hello {see below} there',
    'numbers' => 'Pick a number from {1, 2} today',
    'a name CommonMark does not allow' => 'Mention {@click} as text',
]);

it('makes an attribute list holding an Antlers tag one atom', function () {
    $tokenized = tokenizeMarkdown('Open *the menu*{data-id={{ id }}} now');

    expect($tokenized->text)->toBe('Open *the menu*<x1/> now')
        ->and($tokenized->map)->toBe(['x1' => '{data-id={{ id }}}']);
});

it('does not escape', function () {
    $tokenized = tokenizeMarkdown('Kids < 12 &amp; seniors > 65 &lt;3');

    expect($tokenized->text)->toBe('Kids < 12 &amp; seniors > 65 &lt;3')
        ->and(Tokenizer::restore('Παιδιά < 12 &amp; &lt;3', $tokenized))->toBe('Παιδιά < 12 &amp; &lt;3');
});

it('rejects a translation that adds a link destination, a definition or a tag', function (string $translation) {
    $tokenized = tokenizeMarkdown('See [our rooms](/rooms "Rooms") and [the menu][menu].');

    expect($tokenized->text)->toBe('See [our rooms](<x1/> "Rooms") and [the menu]<x2/>.')
        ->and(Placeholders::validate($tokenized->text, $translation, SegmentKind::Markdown))->not->toBeNull();
})->with([
    'an inline link' => 'Δείτε [τα δωμάτια](<x1/> "Δωμάτια") και [το μενού]<x2/>. [click](javascript:alert(1))',
    'an image' => 'Δείτε [τα δωμάτια](<x1/> "Δωμάτια") και [το μενού]<x2/>. ![x](https://evil.test/a.png)',
    'a replaced destination' => 'Δείτε <x1/>[τα δωμάτια](https://evil.test) και [το μενού]<x2/>.',
    'a definition' => "Δείτε [τα δωμάτια](<x1/> \"Δωμάτια\") και [το μενού]<x2/>.\n\n[evil]: javascript:alert(1)",
    'an autolink' => 'Δείτε [τα δωμάτια](<x1/> "Δωμάτια") και [το μενού]<x2/>. <javascript:alert(1)>',
    'an HTML link' => 'Δείτε [τα δωμάτια](<x1/> "Δωμάτια") και [το μενού]<x2/>. <a href="https://evil.test">εδώ</a>',
]);

it('accepts a translation that keeps every atom where it belongs', function () {
    $tokenized = tokenizeMarkdown('See [our rooms](/rooms "Rooms") and [the menu][menu].');
    $translation = 'Δείτε [το μενού]<x2/> και [τα δωμάτια](<x1/> "Δωμάτια").';

    expect(Placeholders::validate($tokenized->text, $translation, SegmentKind::Markdown))->toBeNull()
        ->and(Tokenizer::restore($translation, $tokenized))->toBe('Δείτε [το μενού][menu] και [τα δωμάτια](/rooms "Δωμάτια").');
});

it('renumbers a translated block that reordered its links like its source', function () {
    $source = tokenizeMarkdown('Read [our story](/about) or [the menu](/menu).');
    $target = tokenizeMarkdown('Διαβάστε [το μενού](/menu) ή [την ιστορία μας](/about).');
    $leaf = fn (Tokenized $tokenized) => new Leaf($tokenized->text, SegmentKind::Markdown, ['body'], 'Body', placeholders: $tokenized->placeholders);

    expect(Renumber::like($leaf($target), $leaf($source)))->toBe('Διαβάστε [το μενού](<x2/>) ή [την ιστορία μας](<x1/>).');
});

it('tokenizes a 400 KB document in one pass', function () {
    $document = str_repeat(ContentFixtures::value('markdown-long')."\n\n", 200);
    $tokenized = tokenizeMarkdown($document);

    expect(Tokenizer::restore($tokenized->text, $tokenized))->toBe($document)
        ->and(strlen($document))->toBeGreaterThan(400_000);
});

// Statamic lifts pcre.backtrack_limit (statamic.system.pcre_backtrack_limit, -1 by default); a host may set one.
it('takes a construct that never closes in one pass, within PHP\'s default PCRE limits', function (string $opening, SegmentKind $kind) {
    $text = $opening.str_repeat("Line of text with **bold**, a [link](/x) and a \"quote.\n", 25_000);
    $limit = ini_set('pcre.backtrack_limit', '1000000');

    try {
        $tokenized = Tokenizer::tokenize($text, $kind);
        $blocks = Tokenizer::splitMarkdownBlocks($text);
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
    }

    expect(strlen($text))->toBeGreaterThan(1_000_000)
        ->and(Tokenizer::restore($tokenized->text, $tokenized))->toBe($text)
        ->and(implode('', $blocks))->toBe($text);
})->with([
    'a fence' => ["```\n", SegmentKind::Markdown],
    'a comment' => ["<!--\n", SegmentKind::Markdown],
    'a pre element' => ["<pre>\n", SegmentKind::Markdown],
    'an Antlers tag' => ['{{ ', SegmentKind::Markdown],
    'an attribute list' => ['{x-data="', SegmentKind::Markdown],
    'a definition title' => ['[a]: /url "', SegmentKind::Markdown],
    'an Antlers tag in a link destination' => ['[a]({{ ', SegmentKind::Markdown],
    'an Antlers tag in a definition' => ['[a]: {{ ', SegmentKind::Markdown],
    'a code span' => ['`` ', SegmentKind::Markdown],
    'a comment in HTML' => ["<!--\n", SegmentKind::Html],
    'a pre element in HTML' => ["<pre>\n", SegmentKind::Html],
    'an Antlers tag in HTML' => ['{{ ', SegmentKind::Html],
    'an Antlers comment in plain text' => ['{{# ', SegmentKind::Plain],
]);

it('takes a link destination of Antlers openings that never close in one pass, within PHP\'s default PCRE limits', function () {
    $text = 'See [a]('.str_repeat('{', 200_000).' here';
    $limit = ini_set('pcre.backtrack_limit', '1000000');

    try {
        $tokenized = tokenizeMarkdown($text);
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
    }

    expect($tokenized->text)->toBe('See [a](<x1/> here')
        ->and(strlen($tokenized->map['x1']))->toBe(200_000);
});

describe('splitting into blocks', function () {
    it('gives the input back when the pieces are joined', function (string $text) {
        expect(implode('', Tokenizer::splitMarkdownBlocks($text)))->toBe($text);
    })->with(fn () => [...markdownSamples(), 'empty' => '', 'blank lines first and last' => "\n\n  \nText\n \n\n"]);

    it('cuts at blank lines and keeps the separators at the odd indexes', function () {
        expect(Tokenizer::splitMarkdownBlocks("# Title\n\nOne\nstill one\n  \n\nTwo\r\n\r\nThree\n"))
            ->toBe(['# Title', "\n\n", "One\nstill one", "\n  \n\n", 'Two', "\r\n\r\n", "Three\n"])
            ->and(Tokenizer::splitMarkdownBlocks(''))->toBe(['']);
    });

    it('never cuts inside a fence', function () {
        $blocks = Tokenizer::splitMarkdownBlocks(ContentFixtures::markup('md')['code-fences.md']);

        expect($blocks)->toHaveCount(11)
            ->and($blocks[2])->toBe("```html\n<div class=\"booking\">\n  {{ partial:booking }}\n</div>\n\n<p>This is code, not a paragraph.</p>\n```")
            ->and($blocks[6])->toBe("~~~\nA tilde fence can hold ``` without closing.\n\nStill inside the fence.\n~~~");
    });

    it('cuts a fence out of the lines around it', function () {
        expect(Tokenizer::splitMarkdownBlocks("Text:\n```\ncode\n\nmore\n```\nAfter\n\n> Quote\n> ~~~\n> code\n> ~~~"))
            ->toBe(['Text:', "\n", "```\ncode\n\nmore\n```", "\n", 'After', "\n\n", '> Quote', "\n", "> ~~~\n> code\n> ~~~"]);
    });

    it('keeps an unclosed fence to the end', function () {
        expect(Tokenizer::splitMarkdownBlocks("One\n\n```\ncode\n\nmore"))->toBe(['One', "\n\n", "```\ncode\n\nmore"]);
    });

    it('never cuts inside a comment, a script, pre, style or textarea element, or an Antlers tag', function () {
        expect(Tokenizer::splitMarkdownBlocks("One\n<!-- a\n\nb -->\n\n<pre>\nx\n\ny\n</pre> tail\nTwo\n\n{{ collection:news\n\n  limit=\"3\" }}"))
            ->toBe(['One', "\n", "<!-- a\n\nb -->", "\n\n", "<pre>\nx\n\ny\n</pre> tail", "\n", 'Two', "\n\n", "{{ collection:news\n\n  limit=\"3\" }}"]);
    });
});
