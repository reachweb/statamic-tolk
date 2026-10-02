<?php

namespace Reach\StatamicTolk\Content\Markup;

use InvalidArgumentException;
use Reach\StatamicTolk\Enums\SegmentKind;

final class Tokenizer
{
    // Comments and PHP blocks first, so a "}}" inside them does not end the tag early.
    private const string ANTLERS = '/(@?\{\{(?:#.*?#|\?.*?\?|\$.*?\$|.*?)\}\})/s';

    private const array ESCAPES = ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;'];

    // An element tag, closed with ">", or a comment or doctype; "Kids < 12 & seniors > 65" has none.
    private const string HTML_TAG = '/<\/?[a-zA-Z][^<>]*>|<!--|<![a-zA-Z][^<>]*>/';

    public static function tokenize(string $text, SegmentKind $kind): Tokenized
    {
        return match ($kind) {
            SegmentKind::Plain => self::plain($text),
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

    public static function restore(string $text, Tokenized $source): string
    {
        $restored = '';

        foreach (preg_split('/('.Placeholders::ATOM.')/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $index => $part) {
            $restored .= match (true) {
                $index % 2 === 1 => $source->map[substr($part, 1, -2)] ?? $part,
                $source->kind->escapes() => strtr($part, array_flip(self::ESCAPES)),
                default => $part,
            };
        }

        return $restored;
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
}
