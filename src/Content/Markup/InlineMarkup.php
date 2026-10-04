<?php

namespace Reach\StatamicTolk\Content\Markup;

use Closure;
use Reach\StatamicTolk\Content\Fingerprint;

/**
 * Bard inline content (a paragraph's, heading's or table cell's `content`, or an `inline: true` value) as one `inline`
 * segment: a formatted run is `<n>…</n>`, a hard break `<br/>`, and a code run, any other inline node and an Antlers
 * tag an atom.
 */
final class InlineMarkup
{
    private const string TOKENS = '/('.Placeholders::PAIR.'|'.Placeholders::ATOM.'|<br\/>)/';

    // Human text inside a mark or node that is translated as a leaf of its own (link titles here, image alts in the Bard
    // handler). Descriptors leave it out, so a target's placeholders still match its source's after it is translated.
    private const array TRANSLATED_ATTRIBUTES = ['link' => 'title', 'image' => 'alt'];

    /**
     * @param  list<array>  $nodes
     */
    public static function serialize(array $nodes): Serialized
    {
        $text = '';
        $map = [];
        $placeholders = [];
        $breaks = [];
        $pairs = 0;
        $atoms = 0;

        $atom = function (mixed $value, string $descriptor) use (&$text, &$map, &$placeholders, &$atoms): void {
            $id = 'x'.++$atoms;
            $map[$id] = $value;
            $placeholders[$id] = $descriptor;
            $text .= "<{$id}/>";
        };

        foreach (self::runs($nodes) as [$marks, $run]) {
            $node = $run[0];

            if ($marks === null) {
                if (self::isBreak($node)) {
                    $breaks[] = $node;
                    $text .= '<br/>';
                } else {
                    $atom($node, self::descriptor($node));
                }

                continue;
            }

            $content = implode('', array_column($run, 'text'));

            if ($content === '') {
                continue;
            }

            if (self::hasCode($marks)) {
                $node['text'] = $content;
                $atom($node, self::descriptor($node));

                continue;
            }

            if ($marks !== []) {
                $id = ++$pairs;
                $map[$id] = $marks;
                $placeholders[$id] = self::descriptor($marks);
                $text .= "<{$id}>";
            }

            foreach (Tokenizer::splitAntlers($content) as $index => $part) {
                if ($index % 2 === 1) {
                    $atom($part, $part);
                } else {
                    $text .= Tokenizer::escape($part);
                }
            }

            if ($marks !== []) {
                $text .= "</{$id}>";
            }
        }

        return new Serialized($text, $map, $placeholders, $nodes, $breaks);
    }

    /**
     * @return list<array>
     */
    public static function parse(string $text, Serialized $source): array
    {
        if ($text === $source->text) {
            return $source->nodes;
        }

        $nodes = [];
        $breaks = $source->breaks;
        $open = null;

        foreach (preg_split(self::TOKENS, $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $index => $part) {
            $marks = $open === null ? [] : $source->map[$open];

            if ($index % 2 === 0) {
                $nodes[] = self::text(Tokenizer::unescape($part), $marks);

                continue;
            }

            $id = trim(substr($part, 1, -1), '/');
            $value = $source->map[$id] ?? null;

            if ($part === '<br/>') {
                $nodes[] = array_shift($breaks) ?? ['type' => 'hardBreak'];
            } elseif ($value === null) {
                $nodes[] = self::text($part, $marks);
            } elseif (str_starts_with($id, 'x')) {
                $nodes[] = is_string($value) ? self::text($value, $marks) : $value;
            } elseif (str_starts_with($part, '</')) {
                $open = $open === (int) $id ? null : $open;
            } else {
                $open = (int) $id;
            }
        }

        return self::merge($nodes);
    }

    /**
     * Offers the title of each link once, in order, to $translate, and writes what it returns into every mark of that
     * link. Called before serialize(), so the marks in its map carry the translated title.
     *
     * @param  list<array>  $nodes
     * @param  Closure(string): string  $translate
     * @return list<array>
     */
    public static function mapLinkTitles(array $nodes, Closure $translate): array
    {
        $titles = [];

        foreach ($nodes as $index => $node) {
            foreach (is_array($node['marks'] ?? null) ? $node['marks'] : [] as $position => $mark) {
                $title = self::linkTitle($mark);

                if ($title === null) {
                    continue;
                }

                $translated = $titles[Fingerprint::canonicalJson($mark)] ??= $translate($title);

                if ($translated !== $title) {
                    $nodes[$index]['marks'][$position]['attrs']['title'] = $translated;
                }
            }
        }

        return $nodes;
    }

    /**
     * Consecutive text nodes with equal marks form one run; any other node is a run of its own, without marks (null).
     *
     * @return list<array{0: array|null, 1: list<array>}>
     */
    private static function runs(array $nodes): array
    {
        $runs = [];
        $previous = null;

        foreach ($nodes as $node) {
            $key = self::isText($node) ? self::marksKey($node) : null;

            if ($key !== null && $key === $previous) {
                $runs[array_key_last($runs)][1][] = $node;
            } else {
                $runs[] = [$key === null ? null : ($node['marks'] ?? []), [$node]];
            }

            $previous = $key;
        }

        return $runs;
    }

    private static function merge(array $nodes): array
    {
        $merged = [];
        $previous = null;

        foreach ($nodes as $node) {
            $key = self::isText($node) ? self::marksKey($node) : null;

            if ($key !== null && $node['text'] === '') {
                continue;
            }

            if ($key !== null && $key === $previous) {
                $merged[array_key_last($merged)]['text'] .= $node['text'];
            } else {
                $merged[] = $node;
                $previous = $key;
            }
        }

        return $merged;
    }

    private static function text(string $text, array $marks): array
    {
        return $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'marks' => $marks, 'text' => $text];
    }

    // A node with keys other than these is carried whole, as an atom, so nothing in it is lost.
    private static function isText(mixed $node): bool
    {
        return is_array($node)
            && ($node['type'] ?? null) === 'text'
            && is_string($node['text'] ?? null)
            && is_array($node['marks'] ?? [])
            && array_diff(array_keys($node), ['type', 'marks', 'text']) === [];
    }

    private static function isBreak(mixed $node): bool
    {
        return is_array($node) && ($node['type'] ?? null) === 'hardBreak';
    }

    private static function hasCode(array $marks): bool
    {
        foreach ($marks as $mark) {
            if (is_array($mark) && ($mark['type'] ?? null) === 'code') {
                return true;
            }
        }

        return false;
    }

    private static function marksKey(array $node): string
    {
        return Fingerprint::canonicalJson($node['marks'] ?? []);
    }

    private static function linkTitle(mixed $mark): ?string
    {
        $title = is_array($mark) && ($mark['type'] ?? null) === 'link' ? $mark['attrs']['title'] ?? null : null;

        return is_string($title) && trim($title) !== '' ? $title : null;
    }

    private static function descriptor(mixed $value): string
    {
        return Fingerprint::canonicalJson(self::withoutTranslatedText($value));
    }

    private static function withoutTranslatedText(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::withoutTranslatedText(...), $value);
        }

        $type = $value['type'] ?? null;
        $attribute = is_string($type) ? self::TRANSLATED_ATTRIBUTES[$type] ?? null : null;

        if ($attribute !== null && is_array($value['attrs'] ?? null)) {
            unset($value['attrs'][$attribute]);
        }

        if (is_array($value['marks'] ?? null)) {
            $value['marks'] = self::withoutTranslatedText($value['marks']);
        }

        return $value;
    }
}
