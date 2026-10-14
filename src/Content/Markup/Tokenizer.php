<?php

namespace Reach\StatamicTolk\Content\Markup;

use InvalidArgumentException;
use Reach\StatamicTolk\Enums\SegmentKind;
use RuntimeException;

final class Tokenizer
{
    // Comments and PHP blocks first, so a "}}" inside them does not end the tag early. Every scan here and below is
    // possessive, in chunks, so a tag, comment or fence that is never closed costs one pass and no backtracking.
    private const string ANTLERS_TAG = '@?\{\{(?:#(?:[^#]++|#(?!\}\}))*+#|\?(?:[^?]++|\?(?!\}\}))*+\?|\$(?:[^$]++|\$(?!\}\}))*+\$|(?:[^}]++|\}(?!\}))*+)\}\}';

    private const string ANTLERS = '/('.self::ANTLERS_TAG.')/s';

    private const array ESCAPES = ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;'];

    // An element tag, closed with ">", or a comment or doctype; "Kids < 12 & seniors > 65" has none.
    private const string HTML_TAG = '/<\/?[a-zA-Z][^<>]*>|<!--|<![a-zA-Z][^<>]*>/';

    // What follows a tag's name. Antlers tags and quoted values are taken whole, so a ">" inside them does not end the tag.
    private const string ATTRIBUTES = '(?:'.self::ANTLERS_TAG.'|=\s*"(?:'.self::ANTLERS_TAG.'|[^"{]++|\{)*+"|=\s*\'(?:'.self::ANTLERS_TAG.'|[^\'{]++|\{)*+\'|[^<>{=]++|[{=])*+';

    private const string COMMENT = '<!--(?:[^-]++|-(?!->))*+(?:-->|\z)';

    // Raw HTML, after Antlers: text that looks like a paired placeholder (carried as an atom), comments, whole script,
    // style, pre and code elements (a script or style element that is never closed runs to the end, as in a browser),
    // declarations, then tags.
    private const string HTML = Placeholders::PAIR
        .'|'.self::COMMENT
        .'|<(?<element>script|style|pre|code)(?=[\s\/>])'.self::ATTRIBUTES.'>(?:[^<]++|<(?!\/\k<element>\s*>))*+<\/\k<element>\s*>'
        .'|<(?:script|style)(?=[\s\/>])'.self::ATTRIBUTES.'>.*'
        .'|<[!?][^>]*+>'
        .'|<(?<slash>\/?)(?<name>[a-zA-Z][\w:.-]*)'.self::ATTRIBUTES.'>';

    // Antlers first, so a tag inside an Antlers tag is never read as HTML.
    private const string HTML_TOKENS = '/'.self::ANTLERS_TAG.'|'.self::HTML.'/si';

    // A fenced code block, blockquote markers and indentation included, from its opening line to its closing line, or to
    // the end when it is never closed. A backtick fence's info string holds no backtick.
    private const string FENCE = '(?m:^)[ \t]*(?:>[ \t]*)*(?<fence>(?<tick>[`~])\k<tick>{2,})(?:(?<=`)[^`\n]*+|(?<=~)[^\n]*+)(?=\n|\z)'
        .'(?:\n(?!'.self::FENCE_CLOSE.')[^\n]*+)*+(?:\n'.self::FENCE_CLOSE.')?';

    private const string FENCE_CLOSE = '[ \t]*(?:>[ \t]*)*\k<fence>\k<tick>*[ \t\r]*(?=\n|\z)';

    // A whole reference definition, its title included, also when the title is on the next line. `[^…]` is a footnote.
    private const string DEFINITION = '(?m:^)[ ]{0,3}\[(?!\^)(?<label>'.self::LABEL.')\]:[ \t]*(?:\r?\n[ \t]*)?(?:'.self::ANGLE_DESTINATION.'|(?!<)(?:'.self::ANTLERS_TAG.'|\{\{\S*+|[^\s{]++|\{)++)'
        .'(?:(?:[ \t]+|[ \t]*\r?\n[ \t]*)(?:"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\'|\((?:[^()\\\\]++|\\\\.)*+\)))?[ \t\r]*(?=\n|\z)';

    // A link label: no unescaped brackets, on one line.
    private const string LABEL = '(?:[^\[\]\\\\\n]++|\\\\.)++';

    // An inline link or image destination, right after "](" (\K leaves those two outside the match). Its title is text.
    private const string DESTINATION = '\]\([ \t]*(?:\r?\n[ \t]*)?\K(?:'.self::ANGLE_DESTINATION.'|(?!<)(?:'.self::DESTINATION_TEXT.'|\((?:'.self::DESTINATION_TEXT.')*+\))++)';

    // Destinations take Antlers tags whole, spaces and parameters included: a Markdown field that parses Antlers before
    // Markdown (`antlers` with `smartypants`) gives CommonMark the tag's output, and a tag's parameters are never text. A
    // "{{" that no "}}" closes takes the rest of the destination in one chunk, since no later "{{" can close either.
    private const string ANGLE_DESTINATION = '<(?:'.self::ANTLERS_TAG.'|\{\{[^<>\n]*+|[^<>\n{]++|\{)*+>';

    private const string DESTINATION_TEXT = self::ANTLERS_TAG.'|\{\{[^\s()\\\\]*+|[^\s()\\\\{]++|\{|\\\\.';

    private const string AUTOLINK = '<[a-zA-Z][a-zA-Z\d+.-]{1,31}:[^\s<>]*>'
        .'|<[\w.!#$%&\'*+\/=?^`{|}~-]+@[a-zA-Z\d](?:[a-zA-Z\d-]{0,61}[a-zA-Z\d])?(?:\.[a-zA-Z\d](?:[a-zA-Z\d-]{0,61}[a-zA-Z\d])?)*>';

    // What follows the "{" of a CommonMark attribute list (`{#id .class name="value"}`, `{: …}`), case-insensitively: a
    // class, an id, or a name and "=". No league/commonmark 2 release reads any other "{" as one.
    public const string ATTRIBUTES_START = ':?\s*+(?:\.[\w-]|#[^\s}]|[a-z_:][\w:.-]*+\s*+=)';

    // Whitespace inside an attribute list, which ends at a blank line with its paragraph.
    private const string ATTRIBUTE_SPACE = '(?:[ \t\r\f\x0B]|\n(?![ \t\r]*+\n))';

    private const string QUOTED = '"(?:[^"\n]++|\n(?![ \t\r]*+\n))*+"|\'(?:[^\'\n]++|\n(?![ \t\r]*+\n))*+\'';

    // An unquoted value, which CommonMark lets hold a "}" when another one closes the list (`{title=a}b}`).
    private const string UNQUOTED = '(?:'.self::ANTLERS_TAG.'|[^"\'=<>`\x00-\x20{}]++|\{(?!'.self::ATTRIBUTES_START.')|\}(?=[^"\'=<>`\x00-\x20}]*+\}))++';

    // An attribute list's names, ids, classes and whitespace, as CommonMark reads them but wider: an id or a class may
    // hold any character but whitespace and "}", and whitespace is followed by an attribute, "=" or the end. A "{" that
    // would open another list ends the list, so one that never closes costs one pass.
    private const string ATTRIBUTE_TEXT = '[^\s={}]++|\{(?!'.self::ATTRIBUTES_START.')|'.self::ATTRIBUTE_SPACE.'++(?=[.#a-z_:=}]|\{\{)';

    // An attribute list, from its "{" to its "}", at least as long as CommonMark reads it, also over several lines: Antlers
    // tags and values are taken whole. When that finds no "}" (`{.a="b}`: a quote in a class), quotes are read as text.
    private const string ATTRIBUTE_LIST = '\{(?='.self::ATTRIBUTES_START.')(?:'
        .'(?:'.self::ANTLERS_TAG.'|='.self::ATTRIBUTE_SPACE.'*+(?:'.self::QUOTED.'|'.self::UNQUOTED.')?|'.self::ATTRIBUTE_TEXT.')*+\}'
        .'|(?:'.self::ANTLERS_TAG.'|='.self::ATTRIBUTE_SPACE.'*+(?:'.self::UNQUOTED.')?|'.self::ATTRIBUTE_TEXT.')*+\})';

    // Atoms, in this order at each position: Antlers, fences, reference definitions, hard breaks (two spaces or a backslash
    // before a line break), code spans, then (as text) backslash escapes and unmatched backticks, footnote references,
    // link destinations, the label of a full or collapsed reference (`[text][label]`, `[text][]`), autolinks, attribute
    // lists, and raw HTML as in the html kind. A backslash before text shaped like a placeholder (`\<x1/>`) is text on its
    // own, so that text is an atom as in the html kind and never reads as a placeholder of the segment.
    private const string MARKDOWN_TOKENS = '/'.self::ANTLERS_TAG
        .'|'.self::FENCE
        .'|'.self::DEFINITION
        .'|(?<=\S) {2,}(?=\r?\n)|\\\\(?=\r?\n)'
        .'|(?<ticks>`++)(?:[^`\n]++|\n(?![ \t\r]*\n)|(?!\k<ticks>(?!`))`++)++\k<ticks>(?!`)'
        .'|(?<literal>\\\\(?!'.Placeholders::PAIR.'|'.Placeholders::ATOM.')[^\w\s{]|`+)'
        .'|\[\^[^\]\s]+\]'
        .'|'.self::DESTINATION
        .'|\]\K(?<reference>\[(?:'.self::LABEL.')?\])'
        .'|'.self::AUTOLINK
        .'|'.self::ATTRIBUTE_LIST
        .'|'.self::HTML.'/si';

    // A shortcut reference (`[text]` alone), when its text is the label of a definition.
    private const string SHORTCUT = '/(?<![\\\\\]])\[('.self::LABEL.')\](?![\[(])/';

    // CommonMark's HTML blocks that may hold blank lines: a comment, or a script, pre, style or textarea element, to the
    // end of the line where it closes.
    private const string RAW_HTML_BLOCK = '(?m:^)[ ]{0,3}(?:'.self::COMMENT.'|<(?<raw>script|pre|style|textarea)(?=[\s>]|\z)(?:[^<]++|<(?!\/\k<raw>\s*>))*+(?:<\/\k<raw>\s*>|\z))[^\n]*+';

    private const string MARKDOWN_BLOCKS = '/'.self::ANTLERS_TAG.'|(?<block>'.self::FENCE.'|'.self::RAW_HTML_BLOCK.')|(?<separator>\r?\n(?:[ \t\r]*\n)+)/si';

    private const array VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    private const array BLOCK_ELEMENTS = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote', 'table', 'figure', 'div', 'pre'];

    private const string PLACEHOLDERS = '/('.Placeholders::PAIR.'|'.Placeholders::ATOM.')/';

    public static function tokenize(string $text, SegmentKind $kind): Tokenized
    {
        return match ($kind) {
            SegmentKind::Plain => self::plain($text),
            SegmentKind::Html => self::html($text),
            SegmentKind::Markdown => self::markdown($text),
            default => throw new InvalidArgumentException("The tokenizer does not support the [{$kind->value}] kind."),
        };
    }

    /**
     * Tokenizes one block of a Markdown document. $labels are the document's reference labels (referenceLabels()); a
     * collapsed or shortcut reference to one of them gets an atom, so it keeps its label once its text is translated.
     * Without $labels, the definitions in $text count. Indented code is not recognised here: whether a block is indented
     * code depends on the blocks before it.
     *
     * @param  list<string>|null  $labels
     */
    public static function markdown(string $text, ?array $labels = null): Tokenized
    {
        $tokens = self::lex($text, self::MARKDOWN_TOKENS);
        $labels = array_flip($labels ?? self::labels($tokens));

        return self::build(self::references($tokens, $text, $labels), SegmentKind::Markdown);
    }

    /**
     * The labels of a Markdown document's reference definitions, normalised as CommonMark matches them.
     *
     * @return list<string>
     */
    public static function referenceLabels(string $markdown): array
    {
        if (! str_contains($markdown, ']:')) {
            return [];
        }

        return self::labels(self::lex($markdown, self::MARKDOWN_TOKENS));
    }

    public static function withoutAntlers(string $text): string
    {
        return preg_replace(self::ANTLERS, '', $text) ?? $text;
    }

    public static function containsHtml(string $text): bool
    {
        return preg_match(self::HTML_TAG, self::withoutAntlers($text)) === 1;
    }

    public static function escape(string $text): string
    {
        return strtr($text, self::ESCAPES);
    }

    public static function unescape(string $text): string
    {
        return strtr($text, array_flip(self::ESCAPES));
    }

    /**
     * The text cut around its Antlers tags, which are at the odd indexes.
     *
     * @return list<string>
     */
    public static function splitAntlers(string $text): array
    {
        return preg_split(self::ANTLERS, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    }

    public static function restore(string $text, Tokenized $source): string
    {
        // Once the text is translated, a Markdown reference's atom writes its label instead (Tokenized::$references).
        $originals = $text !== $source->text ? $source->references + $source->map : $source->map;
        $restored = '';

        foreach (preg_split(self::PLACEHOLDERS, $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $index => $part) {
            if ($index % 2 === 0) {
                $restored .= $source->kind->escapes() ? self::unescape($part) : $part;

                continue;
            }

            $id = rtrim(substr($part, 1, -1), '/');
            $restored .= $originals[$id] ?? $part;
        }

        return $restored;
    }

    /**
     * Cuts after each top-level block element; the pieces, joined, give the input back.
     *
     * @return list<string>
     */
    public static function splitHtmlBlocks(string $html): array
    {
        $pieces = [];
        $piece = '';
        $depth = 0;

        foreach (self::lex($html, self::HTML_TOKENS) as $token) {
            $piece .= $token['text'];
            $depth += match ($token['type']) {
                'open' => 1,
                'close' => -1,
                default => 0,
            };

            if ($depth === 0 && in_array($token['type'], ['close', 'element'], true) && in_array($token['name'], self::BLOCK_ELEMENTS, true)) {
                $pieces[] = $piece;
                $piece = '';
            }
        }

        if ($piece !== '') {
            $pieces[] = $piece;
        }

        return $pieces;
    }

    /**
     * The opening tag, the content and the closing tag of HTML that is exactly one block element, or null when anything
     * stands before its opening tag or after its closing tag.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    public static function unwrapBlock(string $html): ?array
    {
        $tokens = self::lex($html, self::HTML_TOKENS);
        $first = $tokens[0] ?? null;
        $last = end($tokens);

        if ($first === null || $first['type'] !== 'open' || $last['type'] !== 'close' || $last['pair'] !== $first['pair']
            || ! in_array($first['name'], self::BLOCK_ELEMENTS, true)) {
            return null;
        }

        return [$first['text'], substr($html, strlen($first['text']), -strlen($last['text'])), $last['text']];
    }

    /**
     * Cuts a Markdown document at blank lines outside code fences, Antlers tags and the HTML blocks that may hold blank
     * lines. A fence or such an HTML block is a block of its own, cut from the lines around it at their line breaks.
     * Blocks are at the even indexes and the separators at the odd ones; joined, they give the input back.
     *
     * @return list<string>
     */
    public static function splitMarkdownBlocks(string $markdown): array
    {
        $pieces = [];
        $offset = 0;
        $after = null;

        $cut = function (int $at, string $separator) use ($markdown, &$pieces, &$offset): void {
            $pieces[] = substr($markdown, $offset, $at - $offset);
            $pieces[] = $separator;
            $offset = $at + strlen($separator);
        };

        foreach (self::matches(self::MARKDOWN_BLOCKS, $markdown) as $match) {
            [$text, $start] = $match[0];
            $separator = isset($match['separator'][0]);

            if ($after !== null && ! ($separator && $start === $after)) {
                $cut($after, "\n");
            }

            $after = null;

            if ($separator) {
                $cut($start, $text);
            } elseif (isset($match['block'][0])) {
                if ($start > $offset) {
                    $cut($start - 1, "\n");
                }

                $end = $start + strlen($text);

                if ($end < strlen($markdown)) {
                    $after = $end;
                }
            }
        }

        if ($after !== null) {
            $cut($after, "\n");
        }

        $pieces[] = substr($markdown, $offset);

        return $pieces;
    }

    /**
     * A `plain` text in which, besides Antlers tags, each match of the pattern $atoms is an atom, such as Laravel's
     * `:parameters` (LangStringHandler).
     */
    public static function plainWithAtoms(string $text, string $atoms): Tokenized
    {
        return self::plain($text, $atoms);
    }

    /**
     * An `html` text in which, besides tags and Antlers tags, each match of the pattern $atoms in the text between tags is
     * an atom, such as Laravel's `:parameters` (LangStringHandler). One inside a tag is part of that tag's placeholder.
     */
    public static function htmlWithAtoms(string $text, string $atoms): Tokenized
    {
        $tokens = [];

        foreach (self::lex($text, self::HTML_TOKENS) as $token) {
            if ($token['type'] !== 'text') {
                $tokens[] = $token;

                continue;
            }

            foreach (preg_split($atoms, $token['text'], -1, PREG_SPLIT_DELIM_CAPTURE) as $position => $piece) {
                $tokens[] = $position % 2 === 1 ? ['type' => 'atom', 'text' => $piece, 'name' => null] : ['type' => 'text', 'text' => $piece];
            }
        }

        return self::build($tokens, SegmentKind::Html);
    }

    private static function plain(string $text, ?string $atoms = null): Tokenized
    {
        $tokenized = '';
        $map = [];

        foreach (self::splitAntlers($text) as $index => $part) {
            $parts = $index % 2 === 1 ? ['', $part] : ($atoms === null ? [$part] : preg_split($atoms, $part, -1, PREG_SPLIT_DELIM_CAPTURE));

            foreach ($parts as $position => $piece) {
                if ($position % 2 === 1) {
                    $id = 'x'.(count($map) + 1);
                    $map[$id] = $piece;
                    $tokenized .= "<{$id}/>";
                } else {
                    $tokenized .= self::escape($piece);
                }
            }
        }

        return new Tokenized($tokenized, SegmentKind::Plain, $map, $map);
    }

    private static function html(string $text): Tokenized
    {
        return self::build(self::lex($text, self::HTML_TOKENS), SegmentKind::Html);
    }

    /**
     * @param  list<array>  $tokens
     */
    private static function build(array $tokens, SegmentKind $kind): Tokenized
    {
        $tokenized = '';
        $map = [];
        $placeholders = [];
        $references = [];
        $pairs = [];
        $atoms = 0;

        foreach ($tokens as $token) {
            if ($token['type'] === 'text') {
                $tokenized .= $token['text'];
            } elseif ($token['type'] === 'open') {
                $id = $pairs[$token['pair']] = count($pairs) + 1;
                $map[$id] = $placeholders[$id] = $token['text'];
                $tokenized .= "<{$id}>";
            } elseif ($token['type'] === 'close') {
                $id = $pairs[$token['pair']];
                $map["/{$id}"] = $token['text'];
                $tokenized .= "</{$id}>";
            } else {
                $id = 'x'.++$atoms;
                $map[$id] = $token['text'];
                $placeholders[$id] = $token['label'] ?? $token['text'];
                $tokenized .= "<{$id}/>";

                if (isset($token['label'])) {
                    $references[$id] = $token['label'];
                }
            }
        }

        return new Tokenized($tokenized, $kind, $map, $placeholders, $references);
    }

    /**
     * A collapsed (`[text][]`) or shortcut (`[text]`) reference names its definition by its text, so a translated text
     * would no longer find it. Its atom (the `[]`, or an empty one after a shortcut) carries the label as `[text]`, which
     * restore() writes once the text is translated, turning the reference into a full one: `[translated][text]`.
     *
     * @param  list<array>  $tokens
     * @param  array<string, int>  $labels
     * @return list<array>
     */
    private static function references(array $tokens, string $markdown, array $labels): array
    {
        if ($labels === []) {
            return $tokens;
        }

        preg_match_all(self::SHORTCUT, $markdown, $shortcuts, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $result = [];
        $offset = 0;
        $next = 0;

        foreach ($tokens as $token) {
            $start = $offset;
            $offset += strlen($token['text']);

            if ($token['type'] === 'text') {
                $cut = 0;

                for (; $next < count($shortcuts) && $shortcuts[$next][0][1] < $offset; $next++) {
                    [[$shortcut, $at], [$label]] = $shortcuts[$next];
                    $end = $at + strlen($shortcut);

                    // Only a reference wholly inside this text: one that holds or crosses an atom is left alone.
                    if ($at >= $start && $end <= $offset && isset($labels[self::normalize($label)])) {
                        $result[] = ['type' => 'text', 'text' => substr($token['text'], $cut, $end - $start - $cut)];
                        $result[] = ['type' => 'atom', 'text' => '', 'label' => "[{$label}]"];
                        $cut = $end - $start;
                    }
                }

                $token['text'] = substr($token['text'], $cut);
            } elseif (($token['reference'] ?? false) && $token['text'] === '[]') {
                $label = self::collapsedLabel(end($result) ?: null);

                if ($label !== null && isset($labels[self::normalize($label)])) {
                    $token['label'] = "[{$label}]";
                }
            }

            $result[] = $token;
        }

        return $result;
    }

    private static function collapsedLabel(?array $previous): ?string
    {
        if ($previous === null || $previous['type'] !== 'text' || ! str_ends_with($previous['text'], ']')) {
            return null;
        }

        $open = strrpos($previous['text'], '[');
        $label = $open === false ? '' : substr($previous['text'], $open + 1, -1);

        return $label === '' || strpbrk($label, "]\n") !== false ? null : $label;
    }

    /**
     * @param  list<array>  $tokens
     * @return list<string>
     */
    private static function labels(array $tokens): array
    {
        $labels = [];

        foreach ($tokens as $token) {
            if (isset($token['definition'])) {
                $labels[self::normalize($token['definition'])] = true;
            }
        }

        return array_keys($labels);
    }

    private static function normalize(string $label): string
    {
        return mb_convert_case(preg_replace('/\s+/u', ' ', trim($label)) ?? $label, MB_CASE_FOLD, 'UTF-8');
    }

    /**
     * Splits HTML, or Markdown with its raw HTML, into text, atoms (Antlers, comments, void, self-closing and unmatched
     * tags, and the Markdown constructs), whole elements (script, style, pre, code) and matched open/close pairs. A
     * closing tag closes the nearest open tag of its name, and the tags opened after that one stay unmatched, so the
     * pairs are always properly nested. A pair's two tokens share the key `pair`.
     *
     * @return list<array{type: 'text'|'atom'|'element'|'open'|'close', text: string, name?: ?string, pair?: int, reference?: true, definition?: string}>
     */
    private static function lex(string $text, string $pattern): array
    {
        $tokens = [];
        $open = [];
        $offset = 0;

        foreach (self::matches($pattern, $text) as $match) {
            [$token, $start] = $match[0];

            if ($start > $offset) {
                self::text($tokens, substr($text, $offset, $start - $offset));
            }

            $offset = $start + strlen($token);

            if (isset($match['literal'][0])) {
                self::text($tokens, $token);

                continue;
            }

            if (isset($match['element'][0])) {
                $tokens[] = ['type' => 'element', 'text' => $token, 'name' => strtolower($match['element'][0])];

                continue;
            }

            $name = isset($match['name'][0]) ? strtolower($match['name'][0]) : null;
            $atom = ['type' => 'atom', 'text' => $token, 'name' => $name];

            if (isset($match['reference'][0])) {
                $atom['reference'] = true;
            }

            if (isset($match['label'][0])) {
                $atom['definition'] = $match['label'][0];
            }

            $tokens[] = $atom;

            if ($name === null) {
                continue;
            }

            if ($match['slash'][0] === '/') {
                self::close($tokens, $open, array_key_last($tokens));
            } elseif (! in_array($name, self::VOID_ELEMENTS, true) && ! str_ends_with($token, '/>')) {
                $open[] = array_key_last($tokens);
            }
        }

        if ($offset < strlen($text)) {
            self::text($tokens, substr($text, $offset));
        }

        return $tokens;
    }

    private static function matches(string $pattern, string $text): array
    {
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) === false) {
            throw new RuntimeException('The text could not be tokenized: '.preg_last_error_msg());
        }

        return $matches;
    }

    private static function text(array &$tokens, string $text): void
    {
        $last = array_key_last($tokens);

        if ($last !== null && $tokens[$last]['type'] === 'text') {
            $tokens[$last]['text'] .= $text;
        } else {
            $tokens[] = ['type' => 'text', 'text' => $text];
        }
    }

    /**
     * @param  list<array>  $tokens
     * @param  list<int>  $open
     */
    private static function close(array &$tokens, array &$open, int $index): void
    {
        for ($i = count($open) - 1; $i >= 0; $i--) {
            if ($tokens[$open[$i]]['name'] === $tokens[$index]['name']) {
                $tokens[$open[$i]] = [...$tokens[$open[$i]], 'type' => 'open', 'pair' => $open[$i]];
                $tokens[$index] = [...$tokens[$index], 'type' => 'close', 'pair' => $open[$i]];
                array_splice($open, $i);

                return;
            }
        }
    }
}
