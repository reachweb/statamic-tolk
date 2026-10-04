<?php

namespace Reach\StatamicTolk\Content\Markup;

use InvalidArgumentException;
use Reach\StatamicTolk\Enums\SegmentKind;
use RuntimeException;

final class Tokenizer
{
    // Comments and PHP blocks first, so a "}}" inside them does not end the tag early.
    private const string ANTLERS_TAG = '@?\{\{(?:#.*?#|\?.*?\?|\$.*?\$|.*?)\}\}';

    private const string ANTLERS = '/('.self::ANTLERS_TAG.')/s';

    private const array ESCAPES = ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;'];

    // An element tag, closed with ">", or a comment or doctype; "Kids < 12 & seniors > 65" has none.
    private const string HTML_TAG = '/<\/?[a-zA-Z][^<>]*>|<!--|<![a-zA-Z][^<>]*>/';

    // What follows a tag's name. Antlers tags and quoted values are taken whole, so a ">" inside them does not end the tag.
    private const string ATTRIBUTES = '(?:'.self::ANTLERS_TAG.'|=\s*"(?:'.self::ANTLERS_TAG.'|[^"])*+"|=\s*\'(?:'.self::ANTLERS_TAG.'|[^\'])*+\'|[^<>{]|\{)*+';

    // Antlers first, so a tag inside an Antlers tag is never read as HTML. A script or style element that is never
    // closed runs to the end, as in a browser; text that looks like a paired placeholder is carried as an atom.
    private const string HTML_TOKENS = '/'.self::ANTLERS_TAG
        .'|'.Placeholders::PAIR
        .'|<!--.*?(?:-->|\z)'
        .'|<(?<element>script|style|pre|code)(?=[\s\/>])'.self::ATTRIBUTES.'>.*?<\/\k<element>\s*>'
        .'|<(?:script|style)(?=[\s\/>])'.self::ATTRIBUTES.'>.*'
        .'|<[!?][^>]*>'
        .'|<(?<slash>\/?)(?<name>[a-zA-Z][\w:.-]*)'.self::ATTRIBUTES.'>/si';

    private const array VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    private const array BLOCK_ELEMENTS = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote', 'table', 'figure', 'div', 'pre'];

    private const string PLACEHOLDERS = '/('.Placeholders::PAIR.'|'.Placeholders::ATOM.')/';

    public static function tokenize(string $text, SegmentKind $kind): Tokenized
    {
        return match ($kind) {
            SegmentKind::Plain => self::plain($text),
            SegmentKind::Html => self::html($text),
            default => throw new InvalidArgumentException("The tokenizer does not support the [{$kind->value}] kind."),
        };
    }

    public static function withoutAntlers(string $text): string
    {
        return preg_replace(self::ANTLERS, '', $text) ?? $text;
    }

    public static function containsHtml(string $text): bool
    {
        return preg_match(self::HTML_TAG, self::withoutAntlers($text)) === 1;
    }

    public static function unescape(string $text): string
    {
        return strtr($text, array_flip(self::ESCAPES));
    }

    public static function restore(string $text, Tokenized $source): string
    {
        $restored = '';

        foreach (preg_split(self::PLACEHOLDERS, $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $index => $part) {
            $restored .= match (true) {
                $index % 2 === 1 => $source->map[rtrim(substr($part, 1, -1), '/')] ?? $part,
                $source->kind->escapes() => self::unescape($part),
                default => $part,
            };
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

        foreach (self::htmlTokens($html) as $token) {
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

    private static function plain(string $text): Tokenized
    {
        $tokenized = '';
        $map = [];

        foreach (preg_split(self::ANTLERS, $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $index => $part) {
            if ($index % 2 === 1) {
                $id = 'x'.(count($map) + 1);
                $map[$id] = $part;
                $tokenized .= "<{$id}/>";
            } else {
                $tokenized .= strtr($part, self::ESCAPES);
            }
        }

        return new Tokenized($tokenized, SegmentKind::Plain, $map, $map);
    }

    private static function html(string $text): Tokenized
    {
        $tokenized = '';
        $map = [];
        $placeholders = [];
        $pairs = [];
        $atoms = 0;

        foreach (self::htmlTokens($text) as $index => $token) {
            if ($token['type'] === 'text') {
                $tokenized .= $token['text'];
            } elseif ($token['type'] === 'open') {
                $id = $pairs[$index] = count($pairs) + 1;
                $map[$id] = $placeholders[$id] = $token['text'];
                $tokenized .= "<{$id}>";
            } elseif ($token['type'] === 'close') {
                $id = $pairs[$token['partner']];
                $map["/{$id}"] = $token['text'];
                $tokenized .= "</{$id}>";
            } else {
                $id = 'x'.++$atoms;
                $map[$id] = $placeholders[$id] = $token['text'];
                $tokenized .= "<{$id}/>";
            }
        }

        return new Tokenized($tokenized, SegmentKind::Html, $map, $placeholders);
    }

    /**
     * Splits HTML into text, atoms (Antlers, comments, void, self-closing and unmatched tags), whole elements (script,
     * style, pre, code) and matched open/close pairs. A closing tag closes the nearest open tag of its name, and the tags
     * opened after that one stay unmatched, so the pairs are always properly nested.
     *
     * @return list<array{type: 'text'|'atom'|'element'|'open'|'close', text: string, name: ?string, partner?: int}>
     */
    private static function htmlTokens(string $html): array
    {
        if (preg_match_all(self::HTML_TOKENS, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) === false) {
            throw new RuntimeException('The HTML could not be tokenized: '.preg_last_error_msg());
        }

        $tokens = [];
        $open = [];
        $offset = 0;

        foreach ($matches as $match) {
            [$text, $start] = $match[0];

            if ($start > $offset) {
                $tokens[] = ['type' => 'text', 'text' => substr($html, $offset, $start - $offset), 'name' => null];
            }

            $offset = $start + strlen($text);
            $name = $match['name'][0] === null ? null : strtolower($match['name'][0]);

            if ($match['element'][0] !== null) {
                $tokens[] = ['type' => 'element', 'text' => $text, 'name' => strtolower($match['element'][0])];

                continue;
            }

            $tokens[] = ['type' => 'atom', 'text' => $text, 'name' => $name];

            if ($name === null) {
                continue;
            }

            if ($match['slash'][0] === '/') {
                self::close($tokens, $open, array_key_last($tokens));
            } elseif (! in_array($name, self::VOID_ELEMENTS, true) && ! str_ends_with($text, '/>')) {
                $open[] = array_key_last($tokens);
            }
        }

        if ($offset < strlen($html)) {
            $tokens[] = ['type' => 'text', 'text' => substr($html, $offset), 'name' => null];
        }

        return $tokens;
    }

    /**
     * @param  list<array>  $tokens
     * @param  list<int>  $open
     */
    private static function close(array &$tokens, array &$open, int $index): void
    {
        for ($i = count($open) - 1; $i >= 0; $i--) {
            if ($tokens[$open[$i]]['name'] === $tokens[$index]['name']) {
                $tokens[$open[$i]] = [...$tokens[$open[$i]], 'type' => 'open', 'partner' => $index];
                $tokens[$index] = [...$tokens[$index], 'type' => 'close', 'partner' => $open[$i]];
                array_splice($open, $i);

                return;
            }
        }
    }
}
