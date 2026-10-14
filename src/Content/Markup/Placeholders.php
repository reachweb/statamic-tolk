<?php

namespace Reach\StatamicTolk\Content\Markup;

use Reach\StatamicTolk\Enums\SegmentKind;
use RuntimeException;

final class Placeholders
{
    // A paired tag's opening or closing half (the slash in group 1, the id in group 2), or an atom (its id in group 3).
    public const string IDS = '<(\/?)(\d+)>|<(x\d+)\/>';

    public const string PAIR = '<\/?\d+>';

    public const string ATOM = '<x\d+\/>';

    // Paired tags, atoms, hard breaks, then anything a browser would start parsing as a tag (a "<" before a letter, "/", "!" or "?"), closed or not.
    private const string TOKENS = '/'.self::IDS.'|(<br\/>)|<[a-zA-Z\/!?][^<>]*>?/';

    // An inline link or image destination, or a reference definition, that is not an atom.
    private const string RAW_DESTINATION = '/\]\((?!\s*'.self::ATOM.')|^ {0,3}\[[^\]\n]+\]:(?!\s*'.self::ATOM.')/m';

    // The "{" of a CommonMark attribute list, which Statamic's Markdown parser renders with every attribute but on*.
    private const string ATTRIBUTE_LIST = '/\{(?='.Tokenizer::ATTRIBUTES_START.')/i';

    // What a browser starts to read as a tag in the unescaped text of a `plain` or `inline` segment, up to its ">" or to the
    // next "<": a "<" before a letter, "/", "!", "?" or an atom (marked \x01x1\x02), which may stand for a letter once the
    // page is rendered. Bytewise, so text that is not valid UTF-8 cannot make the scan fail.
    private const string UNESCAPED_TAG = '/<[a-zA-Z\/!?\x01][^<>]*+>?/';

    // A web or email address in the text, which a Markdown field with `automatic_links` turns into a link (T12.3).
    private const string BARE_ADDRESS = '/(?:\b(?:https?|ftp):\/\/|\bwww\.)[^\s<>]+|[\w.+-]+@[\w-]+(?:\.[\w-]+)+/iu';

    // A placeholder of a Markdown segment and the backslashes before it.
    private const string ESCAPED_PLACEHOLDER = '/(\\\\*+)('.self::PAIR.'|'.self::ATOM.')/';

    // A Markdown hard break (two spaces or a backslash before a line break), which holds nothing to break.
    private const string HARD_BREAK = '/\A(?: {2,}+|\\\\)\z/';

    // A Markdown construct that a translation may make of its text, since it holds no markup and no link target of its
    // own: a code span (or a fence of backticks), a hard break, or a footnote reference or the label of a reference.
    private const string INERT = '/\A(?:`| {2,}+\z|\\\\\z|\[(?:[^\[\]\\\\]++|\\\\.)*+\]\z)/s';

    /**
     * @return array{pairs: list<string>, atoms: list<string>, breaks: int}
     */
    public static function signature(string $text): array
    {
        $scan = self::scan($text, inline: false);

        return [
            'pairs' => array_map('strval', array_keys($scan['pairs'])),
            'atoms' => array_keys($scan['atoms']),
            'breaks' => $scan['breaks'],
        ];
    }

    /**
     * The text without its paired tags, atoms and hard breaks.
     */
    public static function strip(string $text): string
    {
        return preg_replace('/'.self::IDS.'|<br\/>/', '', $text) ?? $text;
    }

    /**
     * What a reader sees of a segment's text: no placeholders, escaped characters undone in the kinds that escape, and
     * character references decoded in HTML.
     */
    public static function visible(string $text, SegmentKind $kind): string
    {
        $text = self::strip($text);

        return match (true) {
            $kind->escapes() => Tokenizer::unescape($text),
            $kind === SegmentKind::Html => html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            default => $text,
        };
    }

    /**
     * What a reader sees, with each hard break read as a space, which `strip()` would delete and so fuse two words.
     */
    public static function readable(string $text, SegmentKind $kind): string
    {
        return self::visible(str_replace('<br/>', ' ', $text), $kind);
    }

    /**
     * @param  array<string, string>|null  $originals  of a markdown segment: placeholder id → what it stands for in a
     *                                                 translation (Tokenized::originals()), for rule 7's round trip
     */
    public static function validate(string $source, string $translated, SegmentKind $kind, ?array $originals = null): ?string
    {
        if (self::blank($translated) && ! self::blank($source)) {
            return 'The translation is empty.';
        }

        $inline = $kind === SegmentKind::Inline;
        $expected = self::scan($source, $inline);
        $actual = self::scan($translated, $inline);
        $problems = $actual['problems'];

        foreach (array_keys($expected['pairs']) as $id) {
            $count = $actual['pairs'][$id] ?? 0;

            if ($count === 0) {
                $problems[] = "<{$id}>…</{$id}> is missing";
            } elseif ($count > 1 && ! $inline) {
                $problems[] = "<{$id}>…</{$id}> appears {$count} times";
            }
        }

        foreach (array_keys($actual['empty']) as $id) {
            if (isset($expected['pairs'][$id]) && ! isset($expected['empty'][$id])) {
                $problems[] = "<{$id}>…</{$id}> encloses no text";
            }
        }

        foreach (array_keys($expected['atoms']) as $id) {
            $count = $actual['atoms'][$id] ?? 0;

            if ($count !== 1) {
                $problems[] = $count === 0 ? "<{$id}/> is missing" : "<{$id}/> appears {$count} times";
            }
        }

        if ($actual['breaks'] !== $expected['breaks']) {
            $problems[] = "<br/> appears {$actual['breaks']} times instead of {$expected['breaks']}";
        }

        foreach (array_keys(array_diff_key($actual['pairs'], $expected['pairs'])) as $id) {
            $problems[] = "<{$id}> is not in the source";
        }

        foreach (array_keys(array_diff_key($actual['atoms'], $expected['atoms'])) as $id) {
            $problems[] = "<{$id}/> is not in the source";
        }

        foreach (array_diff($actual['unknown'], $expected['unknown']) as $token) {
            $problems[] = "{$token} is not a placeholder";
        }

        if (substr_count($translated, '{{') > substr_count($source, '{{')) {
            $problems[] = 'it adds an Antlers tag ({{ … }})';
        }

        // A "}}" in the text closes an Antlers tag that starts in an atom; without it, the tag runs on or never closes.
        if (substr_count($translated, '}}') < substr_count($source, '}}')) {
            $problems[] = 'it drops the end of an Antlers tag (}})';
        }

        if ($kind->escapes() && self::addsEscapes($source, $translated)) {
            $problems[] = 'it adds an escaped < or > (&lt; or &gt;)';
        }

        if ($kind->escapes()) {
            array_push($problems, ...self::changedTags($source, $translated));
        }

        if ($kind === SegmentKind::Markdown && preg_match_all(self::RAW_DESTINATION, $translated) > preg_match_all(self::RAW_DESTINATION, $source)) {
            $problems[] = 'a link destination is not a placeholder';
        }

        if ($kind === SegmentKind::Markdown && self::addsAddresses($source, $translated)) {
            $problems[] = 'it adds or changes a web or email address';
        }

        if ($kind === SegmentKind::Markdown && self::addsAttributes($source, $translated)) {
            $problems[] = 'it adds an attribute list or a curly brace ({…})';
        }

        if ($kind === SegmentKind::Markdown && $problems === [] && $translated !== $source) {
            array_push($problems, ...self::changedMarkdown($source, $translated, $originals));
        }

        if (mb_strlen($translated) > 4 * mb_strlen($source) + 200) {
            $problems[] = 'it is too long';
        }

        return $problems === [] ? null : ucfirst(implode('; ', array_unique($problems))).'.';
    }

    /**
     * @return array{pairs: array<string, int>, empty: array<string, true>, atoms: array<string, int>, breaks: int, unknown: list<string>, problems: list<string>}
     */
    private static function scan(string $text, bool $inline): array
    {
        $scan = ['pairs' => [], 'empty' => [], 'atoms' => [], 'breaks' => 0, 'unknown' => [], 'problems' => []];
        $open = [];

        preg_match_all(self::TOKENS, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

        foreach ($matches as $match) {
            [$token, $offset] = $match[0];

            if ($match[2][0] !== null && $match[1][0] === '') {
                $id = $match[2][0];

                if ($inline && $open !== []) {
                    $scan['problems'][] = "<{$id}> is nested inside <".end($open)['id'].'>';
                }

                $open[] = ['id' => $id, 'start' => $offset + strlen($token)];
                $scan['pairs'][$id] = ($scan['pairs'][$id] ?? 0) + 1;
            } elseif ($match[2][0] !== null) {
                $id = $match[2][0];

                if ($open === [] || end($open)['id'] !== $id) {
                    $scan['problems'][] = "</{$id}> does not close an open <{$id}>";

                    continue;
                }

                $pair = array_pop($open);

                if (self::blank(substr($text, $pair['start'], $offset - $pair['start']))) {
                    $scan['empty'][$id] = true;
                }
            } elseif ($match[3][0] !== null) {
                $id = $match[3][0];
                $scan['atoms'][$id] = ($scan['atoms'][$id] ?? 0) + 1;
            } elseif ($match[4][0] !== null) {
                $scan['breaks']++;
            } else {
                $scan['unknown'][] = $token;
            }
        }

        foreach ($open as $pair) {
            $scan['problems'][] = "<{$pair['id']}> is not closed";
        }

        return $scan;
    }

    private static function addsEscapes(string $source, string $translated): bool
    {
        return substr_count($translated, '&lt;') > substr_count($source, '&lt;')
            || substr_count($translated, '&gt;') > substr_count($source, '&gt;');
    }

    /**
     * The escaped tags of the source that the translation drops, then those it holds and the source does not. Once the
     * text is unescaped, each is a tag, so a translation that turns `&lt;b&gt;` into `&lt;script&gt;`, changes an
     * attribute, drops a closing tag or puts a letter after an escaped "<" would write markup. Paired tags and breaks
     * hold no text and are left out; an atom keeps its place, so an attribute after it still counts.
     *
     * @return list<string>
     */
    private static function changedTags(string $source, string $translated): array
    {
        $expected = self::unescapedTags($source);
        $actual = self::unescapedTags($translated);
        $problems = [];

        foreach ($expected as $tag => $count) {
            if (($actual[$tag] ?? 0) < $count) {
                $problems[] = self::escapedTag($tag).' is missing';
            }
        }

        foreach ($actual as $tag => $count) {
            if ($count > ($expected[$tag] ?? 0)) {
                $problems[] = self::escapedTag($tag).' is not in the source';
            }
        }

        return $problems;
    }

    /**
     * @return array<string, int>
     */
    private static function unescapedTags(string $text): array
    {
        // A raw tag that is no placeholder is rule 5's; left in, it would be reported again here.
        $text = preg_replace_callback(self::TOKENS, fn (array $match) => ($match[3] ?? '') !== '' ? "\x01{$match[3]}\x02" : '', $text) ?? $text;

        preg_match_all(self::UNESCAPED_TAG, Tokenizer::unescape($text), $matches);

        return array_count_values($matches[0]);
    }

    /**
     * A tag as the model wrote it, escaped and with its atoms, cut short when it runs on unclosed.
     */
    private static function escapedTag(string $tag): string
    {
        return self::excerpt(preg_replace('/\x01(x\d+)\x02/', '<$1/>', Tokenizer::escape($tag)) ?? $tag);
    }

    private static function excerpt(string $text): string
    {
        return mb_strlen($text) > 80 ? mb_substr($text, 0, 79).'…' : $text;
    }

    /**
     * Rule 7's round trip. Each placeholder keeps the backslashes before it: CommonMark reads `\{.class}` as text and
     * `{.class}` as an attribute list, which the tokenizer reads as the same atom. Given what the placeholders stand for,
     * the restored translation, tokenized again, must also give back each placeholder as the construct it stood for, and
     * hold no raw HTML, link or attribute list that they do not account for: a backslash before a code span, a backtick
     * next to or before one, or a link destination moved out of its link would restore that construct's text as text,
     * where its markup is raw HTML, and a dropped backslash would make a tag, a link or an attribute list of escaped text.
     *
     * @param  array<string, string>|null  $originals
     * @return list<string>
     */
    private static function changedMarkdown(string $source, string $translated, ?array $originals): array
    {
        $expected = self::escapedPlaceholders($source);
        $changed = array_keys(array_diff_assoc(array_intersect_key(self::escapedPlaceholders($translated), $expected), $expected));
        $problems = [];

        if ($originals !== null) {
            try {
                $restored = Tokenizer::restore($translated, new Tokenized($source, SegmentKind::Markdown, $originals));
                $left = array_count_values(Tokenizer::markdown($restored, [])->map);
            } catch (RuntimeException) {
                return ['it cannot be read back as Markdown'];
            }

            foreach (array_keys($expected) as $placeholder) {
                $original = (string) ($originals[rtrim(substr($placeholder, 1, -1), '/')] ?? '');

                if ($original === '' || preg_match(self::HARD_BREAK, $original) === 1) {
                    continue;
                }

                if (($left[$original] ?? 0) > 0) {
                    $left[$original]--;
                } else {
                    $changed[] = $placeholder;
                }
            }

            foreach ($left as $markup => $count) {
                if ($count > 0 && preg_match(self::INERT, (string) $markup) !== 1) {
                    $problems[] = 'it turns text into markup ('.self::excerpt((string) $markup).')';
                }
            }
        }

        return [
            ...array_map(fn (string $placeholder) => "a backslash, backtick or bracket in the text changes what {$placeholder} stands for", array_values(array_unique($changed))),
            ...$problems,
        ];
    }

    /**
     * @return array<string, bool> each placeholder as written → whether an odd run of backslashes stands before it
     */
    private static function escapedPlaceholders(string $text): array
    {
        preg_match_all(self::ESCAPED_PLACEHOLDER, $text, $matches, PREG_SET_ORDER);

        $escaped = [];

        foreach ($matches as [, $backslashes, $placeholder]) {
            $escaped[$placeholder] = strlen($backslashes) % 2 === 1;
        }

        return $escaped;
    }

    /**
     * Whether the translation holds more curly braces than the source, or more that open an attribute list. The source's
     * own attribute lists are atoms, so a list can only come from an added brace, from a "}" that lengthens a list ending
     * in an unquoted value, or from a brace of the source's text moved in front of an attribute (`{1, 2}`).
     */
    private static function addsAttributes(string $source, string $translated): bool
    {
        return substr_count($translated, '{') > substr_count($source, '{')
            || substr_count($translated, '}') > substr_count($source, '}')
            || preg_match_all(self::ATTRIBUTE_LIST, $translated) > preg_match_all(self::ATTRIBUTE_LIST, $source);
    }

    /**
     * Whether the translation holds an address more often than the source does. Trailing punctuation is not part of an
     * address, as for the autolink extension.
     */
    private static function addsAddresses(string $source, string $translated): bool
    {
        $addresses = function (string $text): array {
            preg_match_all(self::BARE_ADDRESS, $text, $matches);

            return array_count_values(array_map(fn (string $address) => mb_strtolower(rtrim($address, '.,:;!?\'")*_~]')), $matches[0]));
        };

        $known = $addresses($source);

        foreach ($addresses($translated) as $address => $count) {
            if ($count > ($known[$address] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    private static function blank(string $text): bool
    {
        return preg_match('/[^\s\p{Z}]/u', $text) !== 1;
    }
}
