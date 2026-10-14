<?php

use Reach\StatamicTolk\Content\Markup\Placeholders;
use Reach\StatamicTolk\Content\Markup\Tokenizer;
use Reach\StatamicTolk\Enums\SegmentKind;

it('describes the placeholders of a text', function () {
    expect(Placeholders::signature('<1>Book</1> a <2>suite</2><x1/> now.<br/>Call <x2/> or <1>us</1>.<br/>'))->toBe([
        'pairs' => ['1', '2'],
        'atoms' => ['x1', 'x2'],
        'breaks' => 2,
    ])->and(Placeholders::signature('No placeholders, &lt;b&gt; and <b>tags</b> are not ones'))->toBe([
        'pairs' => [],
        'atoms' => [],
        'breaks' => 0,
    ]);
});

it('accepts a valid translation', function (string $source, string $translated, SegmentKind $kind) {
    expect(Placeholders::validate($source, $translated, $kind))->toBeNull();
})->with([
    'rule 1: pairs balanced and each once' => ['Our <1>spa</1> offers <2>massages</2>.', 'Το <1>σπα</1> μας προσφέρει <2>μασάζ</2>.', SegmentKind::Plain],
    'rule 1: pairs reordered' => ['<1>Read</1> the <2>policy</2>.', 'Lies die <2>Regeln</2> <1>durch</1>.', SegmentKind::Html],
    'rule 1: properly nested outside inline' => ['<1>Our <2>spa</2></1> is open.', '<1>Το <2>σπα</2> μας</1> είναι ανοιχτό.', SegmentKind::Html],
    'rule 1: an inline run split in two' => ['Book a <1>sea view suite</1> now.', 'Κλείστε μια <1>σουίτα</1> με <1>θέα στη θάλασσα</1> τώρα.', SegmentKind::Inline],
    'rule 2: a pair around an atom' => ['<1><x1/></1> Photo', '<1><x1/></1> Φωτογραφία', SegmentKind::Html],
    'rule 2: a pair that was already empty in the source' => ['<1></1>Top of the page', '<1></1>Αρχή της σελίδας', SegmentKind::Html],
    'rule 3: atoms once each, reordered' => ['Call <x1/> or write to <x2/>.', 'Γράψτε στο <x2/> ή καλέστε το <x1/>.', SegmentKind::Plain],
    'rule 4: same number of breaks' => ['Check-in 15:00.<br/>Check-out 11:00.', 'Check-in 15:00.<br/>Check-out 11:00 Uhr.', SegmentKind::Inline],
    'rule 5: no tags at all' => ['Rooms by the sea', 'Δωμάτια δίπλα στη θάλασσα', SegmentKind::Plain],
    'rule 5: a raw < that starts no tag' => ['Kids &lt; 12 stay free', 'Παιδιά < 12 μένουν δωρεάν', SegmentKind::Plain],
    'rule 5: the ends of Antlers tags the source holds as text' => ['Close a tag with }} after <x1/>', 'Κλείστε μια ετικέτα με }} μετά το <x1/>', SegmentKind::Markdown],
    'rule 6: the escapes of the source' => ['Kids &lt; 12 &amp; teens &gt; 12', 'Kinder &lt; 12 &amp; Jugendliche &gt; 12', SegmentKind::Plain],
    'rule 6: escapes do not count in markdown' => ['A **bold** claim', 'Eine &lt;b&gt;-freie Aussage', SegmentKind::Markdown],
    'rule 6: the escaped tags of the source, reordered' => ['&lt;b&gt;Breakfast&lt;/b&gt; and &lt;i&gt;dinner&lt;/i&gt;', '&lt;i&gt;Δείπνο&lt;/i&gt; και &lt;b&gt;πρωινό&lt;/b&gt;', SegmentKind::Plain],
    'rule 6: an escaped tag around an atom, kept' => ['Read our &lt;a href="<x1/>"&gt;terms&lt;/a&gt;.', 'Lesen Sie unsere &lt;a href="<x1/>"&gt;AGB&lt;/a&gt;.', SegmentKind::Plain],
    'rule 6: an escaped < that starts no tag, moved' => ['Kids &lt; 12 stay free', 'Δωρεάν για παιδιά &lt; 12', SegmentKind::Plain],
    'rule 6: an escaped tag around a formatted run, kept' => ['Type &lt;b&gt;<1>bold</1>&lt;/b&gt; here', 'Γράψτε &lt;b&gt;<1>έντονα</1>&lt;/b&gt; εδώ', SegmentKind::Inline],
    'rule 7: link destinations stay atoms' => ['See [our rooms](<x1/> "Rooms") and ![a view](<x2/>).', 'Δείτε [τα δωμάτιά μας](<x1/> "Δωμάτια") και ![θέα](<x2/>).', SegmentKind::Markdown],
    'rule 7: the addresses of the source' => ['Visit www.kyma.test, https://kyma.test/rooms or write to Info@Kyma.test.', 'Δείτε το www.kyma.test και το https://kyma.test/rooms ή γράψτε στο info@kyma.test', SegmentKind::Markdown],
    'rule 7: addresses count only in markdown' => ['Our rooms', 'Τα δωμάτιά μας στο https://kyma.test', SegmentKind::Plain],
    'rule 7: the attribute lists and braces of the source' => ['Pick a number from {1, 2} or *this*<x1/>.', 'Διαλέξτε έναν αριθμό από {1, 2} ή *αυτό*<x1/>.', SegmentKind::Markdown],
    'rule 8: not empty' => ['Home', 'Αρχική', SegmentKind::Plain],
    'rule 9: at the length limit' => ['Hi', str_repeat('a', 208), SegmentKind::Plain],
]);

it('rejects an invalid translation', function (string $source, string $translated, SegmentKind $kind, string $problem) {
    expect(Placeholders::validate($source, $translated, $kind))->toBe($problem);
})->with([
    'rule 1: a dropped closing tag' => ['Our <1>spa</1> is open.', 'Το <1>σπα μας είναι ανοιχτό.', SegmentKind::Plain, '<1> is not closed.'],
    'rule 1: a dropped pair' => ['Our <1>spa</1> is open.', 'Το σπα μας είναι ανοιχτό.', SegmentKind::Plain, '<1>…</1> is missing.'],
    'rule 1: a pair twice outside inline' => ['Our <1>spa</1> is open.', 'Το <1>σπα</1> μας <1>είναι</1> ανοιχτό.', SegmentKind::Html, '<1>…</1> appears 2 times.'],
    'rule 1: crossed pairs' => ['<1>Our</1> <2>spa</2>', '<1>Το <2>σπα</1> μας</2>', SegmentKind::Html, '</1> does not close an open <1>; <1> is not closed.'],
    'rule 1: nesting in inline' => ['<1>Our</1> <2>spa</2>', '<1>Το <2>σπα</2> μας</1>', SegmentKind::Inline, '<2> is nested inside <1>.'],
    'rule 1: a close before its open' => ['<1>Spa</1>', '</1>Σπα<1>', SegmentKind::Plain, '</1> does not close an open <1>; <1> is not closed.'],
    'rule 2: an empty pair' => ['Read the <1>policy</1>.', 'Διαβάστε την πολιτική<1></1>.', SegmentKind::Plain, '<1>…</1> encloses no text.'],
    'rule 2: a pair around whitespace' => ['Read the <1>policy</1>.', "Διαβάστε την πολιτική <1> \u{00A0}</1>.", SegmentKind::Inline, '<1>…</1> encloses no text.'],
    'rule 3: a dropped atom' => ['Call <x1/> today.', 'Καλέστε σήμερα.', SegmentKind::Plain, '<x1/> is missing.'],
    'rule 3: an atom twice' => ['Call <x1/> today.', 'Καλέστε <x1/> σήμερα <x1/>.', SegmentKind::Plain, '<x1/> appears 2 times.'],
    'rule 3: a renamed atom' => ['Call <x1/> today.', 'Καλέστε <x2/> σήμερα.', SegmentKind::Plain, '<x1/> is missing; <x2/> is not in the source.'],
    'rule 4: a dropped break' => ['Check-in 15:00.<br/>Check-out 11:00.', 'Check-in 15:00. Check-out 11:00.', SegmentKind::Inline, '<br/> appears 0 times instead of 1.'],
    'rule 4: an added break' => ['Check-in 15:00.', 'Check-in<br/>15:00.', SegmentKind::Inline, '<br/> appears 1 times instead of 0.'],
    'rule 5: a pair id the source did not have' => ['Our <1>spa</1>', 'Το <1>σπα</1> <2>μας</2>', SegmentKind::Plain, '<2> is not in the source.'],
    'rule 5: an atom id the source did not have' => ['Our spa', 'Το σπα <x1/>', SegmentKind::Plain, '<x1/> is not in the source.'],
    'rule 5: an html tag' => ['Our <1>spa</1>', 'Το <1>σπα</1> <b>μας</b>', SegmentKind::Html, '<b> is not a placeholder; </b> is not a placeholder.'],
    'rule 5: an unclosed html tag' => ['Our spa', 'Το σπα <img src=x onerror=alert(1) ', SegmentKind::Plain, '<img src=x onerror=alert(1)  is not a placeholder.'],
    'rule 5: a malformed break' => ['A<br/>B', 'A<br>B', SegmentKind::Inline, '<br/> appears 0 times instead of 1; <br> is not a placeholder.'],
    'rule 5: an added Antlers tag' => ['Hello <x1/>', 'Γεια <x1/> {{ user:email }}', SegmentKind::Plain, 'It adds an Antlers tag ({{ … }}).'],
    'rule 5: a dropped end of an Antlers tag' => ['Use <x1/>", "") }} for the title', 'Χρησιμοποιήστε <x1/>", "") για τον τίτλο', SegmentKind::Markdown, 'It drops the end of an Antlers tag (}}).'],
    'rule 6: an added escaped script tag' => ['Read the news', 'Διαβάστε &lt;script&gt;alert(1)&lt;/script&gt;', SegmentKind::Plain, 'It adds an escaped < or > (&lt; or &gt;); &lt;script&gt; is not in the source; &lt;/script&gt; is not in the source.'],
    'rule 6: an added escaped < in inline' => ['A &lt; B', 'A &lt; B &lt;', SegmentKind::Inline, 'It adds an escaped < or > (&lt; or &gt;).'],
    'rule 6: an escaped tag rewritten' => ['Served &lt;b&gt;daily&lt;/b&gt;', 'Σερβίρεται &lt;script&gt;alert(1)&lt;/script&gt;', SegmentKind::Plain, '&lt;b&gt; is missing; &lt;/b&gt; is missing; &lt;script&gt; is not in the source; &lt;/script&gt; is not in the source.'],
    'rule 6: an attribute of an escaped tag changed' => ['Read our &lt;a href="<x1/>"&gt;terms&lt;/a&gt;.', 'Lesen Sie unsere &lt;a href="javascript:alert(1)" title="<x1/>"&gt;AGB&lt;/a&gt;.', SegmentKind::Plain, '&lt;a href="<x1/>"&gt; is missing; &lt;a href="javascript:alert(1)" title="<x1/>"&gt; is not in the source.'],
    'rule 6: an attribute added after an atom' => ['Read our &lt;a href="<x1/>"&gt;terms&lt;/a&gt;.', 'Lesen Sie unsere &lt;a href="<x1/>" onmouseover="alert(1)"&gt;AGB&lt;/a&gt;.', SegmentKind::Plain, '&lt;a href="<x1/>"&gt; is missing; &lt;a href="<x1/>" onmouseover="alert(1)"&gt; is not in the source.'],
    'rule 6: an escaped closing tag dropped' => ['Read our &lt;a href="<x1/>"&gt;terms&lt;/a&gt;.', 'Lesen Sie unsere &lt;a href="<x1/>"&gt;AGB.', SegmentKind::Plain, '&lt;/a&gt; is missing.'],
    'rule 6: an escaped < turned into a tag' => ['Kids &lt; 12 eat free', 'Kinder &lt;img src=x onerror=alert(1) essen gratis', SegmentKind::Plain, '&lt;img src=x onerror=alert(1) essen gratis is not in the source.'],
    'rule 6: an escaped < moved before an atom' => ['Kids &lt; 12 at <x1/>', 'Παιδιά 12 στο &lt;<x1/>', SegmentKind::Plain, '&lt;<x1/> is not in the source.'],
    'rule 6: an escaped tag changed around a formatted run' => ['Type &lt;b&gt;<1>bold</1>&lt;/b&gt; here', 'Γράψτε &lt;img src=x onerror=alert(1)&gt;<1>έντονα</1>&lt;/b&gt; εδώ', SegmentKind::Inline, '&lt;b&gt; is missing; &lt;img src=x onerror=alert(1)&gt; is not in the source.'],
    'rule 7: an inline link destination' => ['See [our rooms](<x1/>).', 'Δείτε [τα δωμάτιά μας](<x1/>) και [εδώ](javascript:alert(1)).', SegmentKind::Markdown, 'A link destination is not a placeholder.'],
    'rule 7: a replaced destination' => ['See [our rooms](<x1/>).', 'Δείτε <x1/> [τα δωμάτιά μας](https://evil.test).', SegmentKind::Markdown, 'A link destination is not a placeholder; it adds or changes a web or email address.'],
    'rule 7: a reference definition' => ['See [our rooms][1].', "Δείτε [τα δωμάτιά μας][1].\n\n[1]: https://evil.test", SegmentKind::Markdown, 'A link destination is not a placeholder; it adds or changes a web or email address.'],
    'rule 7: an added web address' => ['Book a room.', 'Κλείστε ένα δωμάτιο στο https://evil.test.', SegmentKind::Markdown, 'It adds or changes a web or email address.'],
    'rule 7: an added www address' => ['Book a room.', 'Κλείστε στο www.evil.test', SegmentKind::Markdown, 'It adds or changes a web or email address.'],
    'rule 7: a changed web address' => ['Visit https://kyma.test/rooms today.', 'Δείτε το https://kyma.test/rooms/../evil σήμερα.', SegmentKind::Markdown, 'It adds or changes a web or email address.'],
    'rule 7: an added email address' => ['Write to us.', 'Γράψτε στο evil@evil.test.', SegmentKind::Markdown, 'It adds or changes a web or email address.'],
    'rule 7: an added attribute list' => ['Book *a room*.', 'Κλείστε *ένα δωμάτιο*{.hidden}.', SegmentKind::Markdown, 'It adds an attribute list or a curly brace ({…}).'],
    'rule 7: an added closing brace' => ['Book *a room*<x1/>.', 'Κλείστε *ένα δωμάτιο*<x1/> x-init=go()}.', SegmentKind::Markdown, 'It adds an attribute list or a curly brace ({…}).'],
    'rule 8: empty' => ['Home', '', SegmentKind::Plain, 'The translation is empty.'],
    'rule 8: whitespace only' => ['<1>Home</1>', "  \n", SegmentKind::Inline, 'The translation is empty.'],
    'rule 9: over the length limit' => ['Hi', str_repeat('a', 209), SegmentKind::Plain, 'It is too long.'],
]);

// The restored block, read back as Markdown, must give each placeholder back as what it stood for, and nothing in the
// text may turn into markup the source does not hold: a broken code span or a moved link destination restores its text
// as raw HTML, and a dropped backslash makes a tag, a link or an attribute list of escaped text (§A3 rule 7).
it('rejects a Markdown translation that changes what a placeholder stands for or makes markup of text once restored', function (string $source, string $translated, string $problem) {
    $tokenized = Tokenizer::markdown($source);

    expect(Placeholders::validate($tokenized->text, $translated, SegmentKind::Markdown, $tokenized->references + $tokenized->map))->toBe($problem);
})->with([
    'a backslash before a code span' => ['Use `<script>alert(1)</script>` to test.', 'Χρησιμοποιήστε \\<x1/> για δοκιμή.', 'A backslash, backtick or bracket in the text changes what <x1/> stands for; it turns text into markup (<script>alert(1)</script>).'],
    'a backtick before a code span' => ['Use `<script>alert(1)</script>` to test.', 'Χρησιμοποιήστε `<x1/> για δοκιμή.', 'A backslash, backtick or bracket in the text changes what <x1/> stands for; it turns text into markup (<script>alert(1)</script>).'],
    'a backtick after a code span' => ['Use `<script>alert(1)</script>` to test.', 'Χρησιμοποιήστε <x1/>` για δοκιμή.', 'A backslash, backtick or bracket in the text changes what <x1/> stands for; it turns text into markup (<script>alert(1)</script>).'],
    'a backtick earlier in the text' => ['Use `<script>alert(1)</script>` to test.', 'Χρη`σιμοποιήστε <x1/> για δοκιμή.', 'A backslash, backtick or bracket in the text changes what <x1/> stands for; it turns text into markup (<script>alert(1)</script>).'],
    'backticks of the source moved before a code span' => ['Use ``<script>alert(1)</script>`` to test ``.', 'Χρησιμοποιήστε `` <x1/> για δοκιμή.', 'A backslash, backtick or bracket in the text changes what <x1/> stands for; it turns text into markup (<script>alert(1)</script>).'],
    'a link destination moved out of its link' => ['Read [the story](x<script>alert(1)</script>) today.', 'Διαβάστε [την ιστορία] <x1/>) σήμερα.', 'A backslash, backtick or bracket in the text changes what <x1/> stands for; it turns text into markup (<script>alert(1)</script>).'],
    'a backslash before an attribute list' => ['Read *this*{title="<script>alert(1)</script>"} today.', 'Διαβάστε *αυτό*\\<x1/> σήμερα.', 'A backslash, backtick or bracket in the text changes what <x1/> stands for.'],
    'a backslash dropped before an attribute list' => ['Read *this*\\{x-init="go()"} today.', 'Διαβάστε *αυτό*<x1/> σήμερα.', 'A backslash, backtick or bracket in the text changes what <x1/> stands for.'],
    'a backslash dropped before a tag' => ['Type \\<script>alert(1)\\</script> to test.', 'Πληκτρολογήστε <script>alert(1)</script> για δοκιμή.', 'It turns text into markup (<script>alert(1)</script>).'],
    'a backslash dropped before an autolink' => ['Type \\<javascript:alert(1)> to test.', 'Πληκτρολογήστε <javascript:alert(1)> για δοκιμή.', 'It turns text into markup (<javascript:alert(1)>).'],
    'backslashes dropped before a link' => ['Type \\[click\\](javascript:alert(1)) to test.', 'Πληκτρολογήστε [click](javascript:alert(1)) για δοκιμή.', 'It turns text into markup (javascript:alert(1)).'],
    'a backslash before a raw tag' => ['Read <b>this</b> today.', 'Διαβάστε \\<1>αυτό</1> σήμερα.', 'A backslash, backtick or bracket in the text changes what <1> stands for.'],
    'a reference label moved away from its text' => ['Read [the menu][menu] today.', 'Διαβάστε [το μενού] <x1/> σήμερα.', 'A backslash, backtick or bracket in the text changes what <x1/> stands for.'],
]);

it('accepts a Markdown translation that keeps what each placeholder stands for', function (string $source, string $translated) {
    $tokenized = Tokenizer::markdown($source);

    expect(Placeholders::validate($tokenized->text, $translated, SegmentKind::Markdown, $tokenized->references + $tokenized->map))->toBeNull();
})->with([
    'a code span moved' => ['Run `npm install` first.', 'Πρώτα τρέξτε <x1/>.'],
    'code spans of different lengths, reordered' => ['Use ``a`b`` or `c`.', 'Χρησιμοποιήστε <x2/> ή <x1/>.'],
    'a code span the translation adds' => ['Run `npm install` from the command line.', 'Τρέξτε <x1/> από τη `CLI`.'],
    'a hard break after a space' => ["One line  \nAnother", "Μία γραμμή <x1/>\nΆλλη"],
    'an escaped tag kept escaped' => ['Type \\<b> for bold', 'Γράψτε \\<b> για έντονα'],
    'an escaped backslash before a code span' => ['A \\\\`code` here', 'Ένα \\\\<x1/> εδώ'],
    'text shaped like a placeholder behind a backslash' => ['Type \\<x1/> next to `code`', 'Γράψτε \\<x1/> δίπλα στο <x2/>'],
    'a reference with its label' => ['Read [the menu][menu] today.', 'Διαβάστε σήμερα [το μενού]<x1/>.'],
    'a link and an image, reordered' => ['See [our rooms](/rooms "Rooms") and ![a view](/view.jpg).', 'Δείτε ![θέα](<x2/>) και [τα δωμάτιά μας](<x1/> "Δωμάτια").'],
    'raw HTML and an attribute list' => ['Our <b>spa</b> is *open*{.wide} daily.', 'Το <1>σπα</1> μας είναι *ανοιχτό*<x1/> καθημερινά.'],
    'an Antlers tag' => ['Hi {{ name }}, welcome.', 'Γεια <x1/>, καλώς ήρθατε.'],
    'an autolink' => ['Write to <mailto:info@kyma.test> today.', 'Γράψτε σήμερα στο <x1/>.'],
]);

it('lists every problem it finds', function () {
    expect(Placeholders::validate('<1>Spa</1> and <x1/>', '<2>Σπα</2> και <x1/> <x1/>', SegmentKind::Plain))
        ->toBe('<1>…</1> is missing; <x1/> appears 2 times; <2> is not in the source.');
});

it('measures the length in characters, not bytes', function () {
    expect(Placeholders::validate('Hi', str_repeat('α', 208), SegmentKind::Plain))->toBeNull();
});

it('accepts an empty translation of an empty source', function () {
    expect(Placeholders::validate(' ', '', SegmentKind::Plain))->toBeNull();
});

it('shows what a reader sees of a segment', function (string $text, SegmentKind $kind, string $visible) {
    expect(Placeholders::visible($text, $kind))->toBe($visible);
})->with([
    'plain unescapes' => ['<x1/> Kids &lt; 12 &amp; teens', SegmentKind::Plain, ' Kids < 12 & teens'],
    'html decodes character references' => ['<1>Kids &lt; 12 &amp; <2>o&#039;clock</2></1><x1/>&nbsp;€', SegmentKind::Html, "Kids < 12 & o'clock\u{a0}€"],
    'markdown keeps the text as written' => ['A &amp; B <x1/>', SegmentKind::Markdown, 'A &amp; B '],
]);
