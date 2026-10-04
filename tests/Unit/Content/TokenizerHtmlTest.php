<?php

use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\Markup\Placeholders;
use Reach\StatamicTolk\Content\Markup\Renumber;
use Reach\StatamicTolk\Content\Markup\Tokenized;
use Reach\StatamicTolk\Content\Markup\Tokenizer;
use Reach\StatamicTolk\Enums\SegmentKind;
use Reach\StatamicTolk\Tests\Support\ContentFixtures;

function tokenizeHtml(string $text): Tokenized
{
    return Tokenizer::tokenize($text, SegmentKind::Html);
}

function htmlSamples(): array
{
    return [
        ...ContentFixtures::markup('html'),
        'textarea-html' => ContentFixtures::value('textarea-html'),
        'bard-html-string' => ContentFixtures::value('bard-html-string'),
    ];
}

it('restores every HTML sample exactly', function (string $text) {
    $tokenized = tokenizeHtml($text);

    expect($tokenized->kind)->toBe(SegmentKind::Html)
        ->and(Tokenizer::restore($tokenized->text, $tokenized))->toBe($text);
})->with(fn () => htmlSamples());

it('restores edge cases exactly', function (string $text) {
    $tokenized = tokenizeHtml($text);

    expect(Tokenizer::restore($tokenized->text, $tokenized))->toBe($text);
})->with([
    'empty' => '',
    'text only' => 'Rooms by the sea',
    'entities' => 'Tom &amp; Jerry &lt;3 &gt; &#039;s',
    'a literal less-than' => '<p>Kids < 12 & seniors > 65</p>',
    'text that looks like a placeholder' => '<p>Type <1> or </2> or <x1/> or <br/> here</p>',
    'upper-case tags' => '<P>Rooms <B>by</b> the sea</p>',
    'a quoted attribute holding > and <' => '<a title="5 > 3 < 4" href=\'/a>b\'>Link</a>',
    'an unquoted attribute with an apostrophe' => "<a title=it's>Rock</a> and 'roll'",
    'antlers holding a tag' => '<p>Hello {{ name | wrap("<b>") }} there</p>',
    'antlers holding > inside a tag' => '<div {{ if a > b }}class="wide"{{ /if }}>Wide</div>',
    'antlers inside a quoted value' => '<a href="{{ a ? "x > y" : "z" }}">Go</a>',
    'unclosed antlers' => '<p>Price {{ amount</p>',
    'a tag that never closes' => '<p>Prices <a lot</p>',
    'an unclosed comment' => '<p>Visible</p><!-- hidden <p>text</p>',
    'an unclosed script' => '<p>Book</p><script>if (a < b) { go(); }',
    'an unclosed pre' => '<pre>Not closed <b>at all</b>',
    'a doctype and a processing instruction' => '<!DOCTYPE html><?xml version="1.0"?><p>Text</p>',
    'crossed tags' => '<b>bold <i>both</b> italic</i>',
    'implicitly closed list items' => '<ul><li>One<li>Two</ul>',
    'a self-closing element' => '<p>Before<span/>after</p>',
    'windows line breaks' => "<p>Line one</p>\r\n<p>Line two</p>",
]);

it('pairs matched tags in order, with the opening tag as the descriptor', function () {
    $tokenized = tokenizeHtml('<p>Read <a href="/about" title="About us">our story</a> or <b>call</b>.</p>');

    expect($tokenized->text)->toBe('<1>Read <2>our story</2> or <3>call</3>.</1>')
        ->and($tokenized->placeholders)->toBe([
            1 => '<p>',
            2 => '<a href="/about" title="About us">',
            3 => '<b>',
        ])
        ->and($tokenized->map)->toBe([
            1 => '<p>',
            2 => '<a href="/about" title="About us">',
            '/2' => '</a>',
            3 => '<b>',
            '/3' => '</b>',
            '/1' => '</p>',
        ]);
});

it('keeps nested inline tags properly nested', function () {
    $tokenized = tokenizeHtml(ContentFixtures::markup('html')['nested-inline.html']);

    expect($tokenized->text)->toBe("<1>Our <2>spa <3>and the <4>heated pool</4></3> are open</2> from 9:00, <5>every day</5>. Kids &lt; 12 &amp; guests over 70 swim free.</1>\n")
        ->and($tokenized->placeholders)->toBe([1 => '<p>', 2 => '<strong>', 3 => '<em>', 4 => '<a href="/pool">', 5 => '<span class="note">'])
        ->and(Placeholders::validate($tokenized->text, $tokenized->text, SegmentKind::Html))->toBeNull();
});

it('leaves tags that cross or are closed implicitly unmatched, so the pairs stay nested', function (string $text, string $expected) {
    $tokenized = tokenizeHtml($text);

    expect($tokenized->text)->toBe($expected)
        ->and(Placeholders::validate($tokenized->text, $tokenized->text, SegmentKind::Html))->toBeNull();
})->with([
    'crossed' => ['<b>bold <i>both</b> italic</i>', '<1>bold <x1/>both</1> italic<x2/>'],
    'list items without closing tags' => ['<ul><li>One<li>Two</ul>', '<1><x1/>One<x2/>Two</1>'],
    'a closing tag with no opening' => ['Rooms</p> by <em>the sea</em>', 'Rooms<x1/> by <1>the sea</1>'],
    'an opening tag with no closing' => ['<p>Rooms <em>by the sea', '<x1/>Rooms <x2/>by the sea'],
    'tag names in mixed case' => ['<P>Rooms <B>by</b> the sea</p>', '<1>Rooms <2>by</2> the sea</1>'],
    'a void element never pairs with a closing tag' => ['<p>Line<br>break</br> and <img src="/a.jpg">pool</img></p>', '<1>Line<x1/>break<x2/> and <x3/>pool<x4/></1>'],
]);

it('makes void, self-closing and unmatched tags, comments and declarations atoms', function () {
    $tokenized = tokenizeHtml('<!DOCTYPE html><p>Hotel<br>Chania<br />Crete<img src="/a.jpg" alt="Sea"><span/></p><!-- note --></div>');

    expect($tokenized->text)->toBe('<x1/><1>Hotel<x2/>Chania<x3/>Crete<x4/><x5/></1><x6/><x7/>')
        ->and($tokenized->placeholders)->toBe([
            'x1' => '<!DOCTYPE html>',
            1 => '<p>',
            'x2' => '<br>',
            'x3' => '<br />',
            'x4' => '<img src="/a.jpg" alt="Sea">',
            'x5' => '<span/>',
            'x6' => '<!-- note -->',
            'x7' => '</div>',
        ]);
});

it('takes a whole comment as one atom, the tags inside it included', function () {
    $tokenized = tokenizeHtml(ContentFixtures::markup('html')['comment.html']);

    expect($tokenized->text)->toBe("<x1/><1>Rooms by the sea</1><x2/>\n")
        ->and($tokenized->map['x2'])->toBe("<!--\nChecked with the owners in March 2026.\n<p>Old text</p>\n-->");
});

it('makes a whole script element one atom', function () {
    $tokenized = tokenizeHtml(ContentFixtures::markup('html')['script.html']);

    expect($tokenized->text)->toBe("<1>Book online:</1><x1/><x2/><2>Or call us.</2>\n")
        ->and($tokenized->map['x1'])->toBe("<script type=\"text/javascript\">document.write('<p>Loading the booking form…</p>'); if (nights < 7 && guests > 2) { offer = '</div>'; }</script>")
        ->and($tokenized->map['x2'])->toBe('<style>.booking > p { color: #036; }</style>');
});

it('makes a whole style, pre or code element one atom, and an unclosed script or style runs to the end', function (string $text, string $expected, string $atom) {
    $tokenized = tokenizeHtml($text);

    expect($tokenized->text)->toBe($expected)
        ->and($tokenized->map['x1'])->toBe($atom);
})->with([
    'pre holding code and Antlers' => ["<p>Use</p><pre><code>&lt;div&gt;\n  {{ partial:booking }}\n&lt;/div&gt;</code></pre>", '<1>Use</1><x1/>', "<pre><code>&lt;div&gt;\n  {{ partial:booking }}\n&lt;/div&gt;</code></pre>"],
    'inline code' => ['<p>Code <code>SPA-10</code> saves</p>', '<1>Code <x1/> saves</1>', '<code>SPA-10</code>'],
    'upper-case closing tag' => ['<SCRIPT>a < b</Script> after', '<x1/> after', '<SCRIPT>a < b</Script>'],
    'unclosed script' => ['<p>Book</p><script>if (a < b) { go(); }', '<1>Book</1><x1/>', '<script>if (a < b) { go(); }'],
    'unclosed style' => ['Text<style>p > a { color: red }', 'Text<x1/>', '<style>p > a { color: red }'],
]);

it('makes an unclosed pre or code tag an atom and still translates what follows', function () {
    expect(tokenizeHtml('<pre>Not closed <b>at all</b>')->text)->toBe('<x1/>Not closed <1>at all</1>')
        ->and(tokenizeHtml('<code>Not closed')->text)->toBe('<x1/>Not closed');
});

it('does not take an element whose name only starts like script, style, pre or code whole', function () {
    expect(tokenizeHtml('<code-sample>Sea</code-sample> <preview>view</preview>')->text)->toBe('<1>Sea</1> <2>view</2>');
});

it('turns Antlers tags into atoms first, so the model never sees one', function () {
    $tokenized = tokenizeHtml('<p>Hi {{ name }}, see {{ name | wrap("<b>") }} or {{# a <i>note</i> #}}.</p>');

    expect($tokenized->text)->toBe('<1>Hi <x1/>, see <x2/> or <x3/>.</1>')
        ->and($tokenized->placeholders)->toBe([
            1 => '<p>',
            'x1' => '{{ name }}',
            'x2' => '{{ name | wrap("<b>") }}',
            'x3' => '{{# a <i>note</i> #}}',
        ]);
});

it('swallows Antlers inside a tag into the tag and gives it back on restore', function () {
    $sample = ContentFixtures::markup('html')['antlers.html'];
    $tokenized = tokenizeHtml($sample);

    expect($tokenized->text)->toBe("<1>Questions? <2>Contact us</2> or call <x1/>. <x2/></1>\n")
        ->and($tokenized->placeholders[2])->toBe('<a href="{{ link to="contact" }}" title="Write to {{ site:name }}">')
        ->and(Tokenizer::restore("<1>Ερωτήσεις; Καλέστε <x1/> ή <2>επικοινωνήστε μαζί μας</2>. <x2/></1>\n", $tokenized))
        ->toBe("<p>Ερωτήσεις; Καλέστε {{ site_settings:phone }} ή <a href=\"{{ link to=\"contact\" }}\" title=\"Write to {{ site:name }}\">επικοινωνήστε μαζί μας</a>. {{ partial:icons/arrow }}</p>\n");
});

it('ends a tag at the first > outside Antlers tags and quoted values', function (string $text, string $tag) {
    expect(tokenizeHtml($text)->placeholders[1])->toBe($tag);
})->with([
    'a quoted attribute holding > and <' => ['<a title="5 > 3 < 4" href=\'/a>b\'>Link</a>', '<a title="5 > 3 < 4" href=\'/a>b\'>'],
    'antlers holding > between attributes' => ['<div {{ if a > b }}class="wide"{{ /if }}>Wide</div>', '<div {{ if a > b }}class="wide"{{ /if }}>'],
    'antlers holding quotes inside a quoted value' => ['<a href="{{ a ? "x > y" : "z" }}">Go</a>', '<a href="{{ a ? "x > y" : "z" }}">'],
    'an unquoted value with an apostrophe' => ["<a title=it's>Rock</a> and 'roll'", "<a title=it's>"],
]);

it('carries text that looks like a placeholder as an atom', function () {
    $tokenized = tokenizeHtml('<p>Type <1> or </2> or <x1/> or <br/></p>');

    expect($tokenized->text)->toBe('<1>Type <x1/> or <x2/> or <x3/> or <x4/></1>')
        ->and($tokenized->placeholders)->toBe([1 => '<p>', 'x1' => '<1>', 'x2' => '</2>', 'x3' => '<x1/>', 'x4' => '<br/>']);
});

it('does not escape', function () {
    $tokenized = tokenizeHtml('<p>Kids < 12 &amp; seniors > 65 &lt;3</p>');

    expect($tokenized->text)->toBe('<1>Kids < 12 &amp; seniors > 65 &lt;3</1>')
        ->and(Tokenizer::restore('<1>Παιδιά < 12 &amp; &lt;3</1>', $tokenized))->toBe('<p>Παιδιά < 12 &amp; &lt;3</p>');
});

it('restores a translation that reorders pairs and atoms', function () {
    $tokenized = tokenizeHtml('<p><a href="/rooms">Book</a> a <strong>sea view</strong> suite<br>today.</p>');

    expect($tokenized->text)->toBe('<1><2>Book</2> a <3>sea view</3> suite<x1/>today.</1>')
        ->and(Tokenizer::restore('<1>Μια σουίτα με <3>θέα στη θάλασσα</3><x1/><2>κλείστε</2> σήμερα.</1>', $tokenized))
        ->toBe('<p>Μια σουίτα με <strong>θέα στη θάλασσα</strong><br><a href="/rooms">κλείστε</a> σήμερα.</p>');
});

it('leaves a placeholder it does not know in place', function () {
    $tokenized = tokenizeHtml('<p>Rooms</p>');

    expect(Tokenizer::restore('<1>Δωμάτια</1><2>x</2><x9/>', $tokenized))->toBe('<p>Δωμάτια</p><2>x</2><x9/>');
});

it('rejects a translation that adds a tag', function (string $translation, string $problem) {
    $source = tokenizeHtml('<p>Our <strong>spa</strong> is open.</p>')->text;

    expect(Placeholders::validate($source, $translation, SegmentKind::Html))->toBe($problem);
})->with([
    'an element' => ['<1>Το <2>σπα</2> μας είναι <b>ανοιχτό</b>.</1>', '<b> is not a placeholder; </b> is not a placeholder.'],
    'a script' => ['<1>Το <2>σπα</2> μας είναι ανοιχτό.<script>alert(1)</script></1>', '<script> is not a placeholder; </script> is not a placeholder.'],
    'a void tag without >' => ['<1>Το <2>σπα</2> μας <img src=x onerror=alert(1)</1>', '<img src=x onerror=alert(1) is not a placeholder.'],
    'a comment' => ['<1>Το <2>σπα</2> <!-- μας --> είναι ανοιχτό.</1>', '<!-- μας --> is not a placeholder.'],
    'a pair the source did not have' => ['<1>Το <2>σπα</2> <3>μας</3>.</1>', '<3> is not in the source.'],
    'an atom the source did not have' => ['<1>Το <2>σπα</2> μας<x1/>.</1>', '<x1/> is not in the source.'],
    'antlers' => ['<1>Το <2>σπα</2> {{ site:name }} είναι ανοιχτό.</1>', 'It adds an Antlers tag ({{ … }}).'],
]);

it('lets Renumber express a target leaf in the numbering of its source', function () {
    $source = tokenizeHtml('<p><a href="/rooms">Book</a> a <strong>suite</strong>.</p>');
    $target = tokenizeHtml('<p>Μια <strong>σουίτα</strong> <a href="/rooms">κλείστε</a>.</p>');
    $leaf = fn (Tokenized $tokenized) => new Leaf($tokenized->text, SegmentKind::Html, ['intro'], 'Intro', placeholders: $tokenized->placeholders);

    expect($target->text)->toBe('<1>Μια <2>σουίτα</2> <3>κλείστε</3>.</1>')
        ->and(Renumber::like($leaf($target), $leaf($source)))->toBe('<1>Μια <3>σουίτα</3> <2>κλείστε</2>.</1>');
});

it('tokenizes a long document and a tag that never closes without backtracking out', function () {
    $document = str_repeat('<p>Rooms <strong>by</strong> the <a href="/sea" title="a > b">sea</a> {{ site:name }}.</p>', 5000);
    $unclosed = '<a '.str_repeat('b="c" ', 20000).'{{ x }}';

    expect(Tokenizer::restore(tokenizeHtml($document)->text, tokenizeHtml($document)))->toBe($document)
        ->and(tokenizeHtml($unclosed)->text)->toBe('<a '.str_repeat('b="c" ', 20000).'<x1/>');
});

it('cuts HTML after each top-level block element', function () {
    expect(Tokenizer::splitHtmlBlocks(ContentFixtures::value('bard-html-string')))->toBe([
        '<h2>Our new spa</h2>',
        '<p>The spa opens on <strong>1 April</strong> in the old olive press. Book a treatment at <a href="statamic://entry::51c8a58f-8a3d-47fa-90cc-91f543aa618f">the front desk</a> or read the <a target="_blank" href="statamic://asset::assets::spa-prices.pdf" title="Spa prices for 2026 (PDF)">price list</a>.</p>',
        '<p style="text-align: center;"><em>Open daily</em><br>10:00–20:00, kids &lt; 12 &amp; guests over 70 with a companion</p>',
        '<h3>Treatments</h3>',
        '<ul><li><p>Olive oil massage, 60 minutes</p></li><li><p>Sea salt scrub</p><ol start="1"><li><p>with <strong><em>Cretan</em></strong> honey</p></li><li><p>with thyme</p></li></ol></li></ul>',
        '<blockquote><p>The best hour of our holiday. <small>Anna, Vienna</small></p></blockquote>',
        '<table><tbody><tr><th rowspan="1" colspan="1"><p>Treatment</p></th><th rowspan="1" colspan="1"><p>Price</p></th></tr><tr><td rowspan="1" colspan="1"><p>Massage</p></td><td rowspan="1" colspan="1"><p>€80</p></td></tr></tbody></table>',
        '<hr><p><img src="statamic://asset::assets::spa-pool.jpg" alt="The indoor pool under the stone arches"></p>',
        '<p>Room size 45 m<sup>2</sup>, code <code>SPA-10</code> for 10% off, and <em>eleven o&#039;clock</em> is never too late for coffee.</p>',
        "<pre><code>&lt;div class=&quot;booking&quot;&gt;\n  {{ partial:booking }}\n&lt;/div&gt;</code></pre>",
    ]);
});

it('gives pieces that concatenate to the input', function (string $html) {
    expect(implode('', Tokenizer::splitHtmlBlocks($html)))->toBe($html);
})->with(fn () => [
    ...htmlSamples(),
    'crossed and unclosed tags' => '<div><p>One<li>Two</div></p><b>three<p>four</p>',
    'text without blocks' => 'Rooms <b>by</b> the sea',
]);

it('cuts only at the top level, and never inside a comment, script or Antlers tag', function (string $html, array $pieces) {
    expect(Tokenizer::splitHtmlBlocks($html))->toBe($pieces);
})->with([
    'blocks inside a div' => ['<div><p>One</p><p>Two</p></div><p>Three</p>', ['<div><p>One</p><p>Two</p></div>', '<p>Three</p>']],
    'blocks inside an element that is not a block' => ['<section><p>One</p><p>Two</p></section>', ['<section><p>One</p><p>Two</p></section>']],
    'text between and after blocks' => ["<p>One</p>\n<h2>Two</h2>\nTail", ['<p>One</p>', "\n<h2>Two</h2>", "\nTail"]],
    'a comment holding a block' => ['<!-- <p>Old</p> --><p>New</p>', ['<!-- <p>Old</p> --><p>New</p>']],
    'a script holding a block' => ['<script>w("<p>x</p>")</script><p>New</p>', ['<script>w("<p>x</p>")</script><p>New</p>']],
    'antlers holding a block' => ['{{ x | wrap("<p>a</p>") }}<p>New</p>', ['{{ x | wrap("<p>a</p>") }}<p>New</p>']],
    'an unclosed block' => ['<p>One<p>Two</p>', ['<p>One<p>Two</p>']],
    'a pre at the top level' => ['<pre>a</pre><p>b</p>', ['<pre>a</pre>', '<p>b</p>']],
    'no blocks' => ['Rooms <b>by</b> the sea', ['Rooms <b>by</b> the sea']],
    'empty' => ['', []],
]);

it('unwraps HTML that is exactly one block element', function (string $html, ?array $parts) {
    expect(Tokenizer::unwrapBlock($html))->toBe($parts);

    if ($parts !== null) {
        expect(implode('', $parts))->toBe($html);
    }
})->with([
    'a paragraph' => ['<p style="text-align: center;">One <b>two</b></p>', ['<p style="text-align: center;">', 'One <b>two</b>', '</p>']],
    'a list, whitespace inside kept' => ["<ul>\n<li>One</li>\n</ul>", ['<ul>', "\n<li>One</li>\n", '</ul>']],
    'an empty paragraph' => ['<p></p>', ['<p>', '', '</p>']],
    'an attribute holding a tag' => ['<p title="a <p> b">One</p>', ['<p title="a <p> b">', 'One', '</p>']],
    'antlers in the opening tag' => ['<h2 id="{{ slug }}">Spa</h2>', ['<h2 id="{{ slug }}">', 'Spa', '</h2>']],
    'two blocks' => ['<p>One</p><p>Two</p>', null],
    'text before' => ['Loose <p>One</p>', null],
    'text after' => ['<p>One</p> loose', null],
    'whitespace around' => [' <p>One</p>', null],
    'an element that is not a block' => ['<span>One</span>', null],
    'a pre element' => ['<pre>a</pre>', null],
    'a void element first' => ['<hr><p>One</p>', null],
    'an unclosed block' => ['<p>One<p>Two</p>', null],
    'text' => ['One', null],
    'empty' => ['', null],
]);
