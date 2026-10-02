<?php

namespace Reach\StatamicTolk\Content\Markup;

use Reach\StatamicTolk\Enums\SegmentKind;

final class Placeholders
{
    // A paired tag's opening or closing half (the slash in group 1, the id in group 2), or an atom (its id in group 3).
    public const string IDS = '<(\/?)(\d+)>|<(x\d+)\/>';

    public const string ATOM = '<x\d+\/>';

    // Paired tags, atoms, hard breaks, then anything a browser would start parsing as a tag (a "<" before a letter, "/", "!" or "?"), closed or not.
    private const string TOKENS = '/'.self::IDS.'|(<br\/>)|<[a-zA-Z\/!?][^<>]*>?/';

    // An inline link or image destination, or a reference definition, that is not an atom.
    private const string RAW_DESTINATION = '/\]\((?!\s*'.self::ATOM.')|^ {0,3}\[[^\]\n]+\]:(?!\s*'.self::ATOM.')/m';

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

    public static function validate(string $source, string $translated, SegmentKind $kind): ?string
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

        if ($kind->escapes() && self::addsEscapes($source, $translated)) {
            $problems[] = 'it adds an escaped < or > (&lt; or &gt;)';
        }

        if ($kind === SegmentKind::Markdown && preg_match_all(self::RAW_DESTINATION, $translated) > preg_match_all(self::RAW_DESTINATION, $source)) {
            $problems[] = 'a link destination is not a placeholder';
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

    private static function blank(string $text): bool
    {
        return preg_match('/[^\s\p{Z}]/u', $text) !== 1;
    }
}
