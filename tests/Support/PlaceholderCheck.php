<?php

namespace Reach\StatamicTolk\Tests\Support;

final class PlaceholderCheck
{
    public const array SYNTAXES = ['numeric' => '', 'g' => 'g'];

    public static function convert(string $text, string $syntax): string
    {
        return preg_replace('/<(\/?)(\d+)>/', '<$1'.self::SYNTAXES[$syntax].'$2>', $text);
    }

    /**
     * @param  list<array{id: string, text: string}>  $source
     * @param  array<string, string>  $translations
     * @return array<string, array{problems: list<string>, source: string, translation: ?string}>
     */
    public static function failures(array $source, array $translations, string $syntax): array
    {
        $failures = [];

        foreach ($source as $segment) {
            $translation = $translations[$segment['id']] ?? null;
            $problems = $translation === null ? ['missing'] : self::problems($segment['text'], $translation, $syntax);

            if ($problems !== []) {
                $failures[$segment['id']] = ['problems' => $problems, 'source' => $segment['text'], 'translation' => $translation];
            }
        }

        return $failures;
    }

    /**
     * The rules of §A3 that apply to an inline segment, without the escaping rule.
     *
     * @return list<string>
     */
    public static function problems(string $source, string $translation, string $syntax): array
    {
        if (trim($translation) === '') {
            return ['empty'];
        }

        $expected = self::scan($source, $syntax);
        $actual = self::scan($translation, $syntax);
        $problems = $actual['problems'];

        foreach (array_diff($expected['pairs'], $actual['pairs']) as $id) {
            $problems[] = "pair {$id} missing";
        }

        foreach (array_diff($actual['pairs'], $expected['pairs']) as $id) {
            $problems[] = "pair {$id} not in the source";
        }

        foreach (array_unique([...$expected['atoms'], ...$actual['atoms']]) as $id) {
            $count = count(array_keys($actual['atoms'], $id, true));

            if (! in_array($id, $expected['atoms'], true)) {
                $problems[] = "atom {$id} not in the source";
            } elseif ($count !== 1) {
                $problems[] = "atom {$id} appears {$count} times";
            }
        }

        if ($actual['breaks'] !== $expected['breaks']) {
            $problems[] = "{$actual['breaks']} line breaks instead of {$expected['breaks']}";
        }

        if (mb_strlen($translation) > 4 * mb_strlen($source) + 200) {
            $problems[] = 'too long';
        }

        return array_values(array_unique($problems));
    }

    /**
     * @return array{pairs: list<string>, atoms: list<string>, breaks: int, problems: list<string>}
     */
    private static function scan(string $text, string $syntax): array
    {
        $prefix = self::SYNTAXES[$syntax];
        $result = ['pairs' => [], 'atoms' => [], 'breaks' => 0, 'problems' => []];
        $open = null;
        $offset = 0;

        preg_match_all('/<[^<>]*>/', $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$tag, $position]) {
            if ($open !== null) {
                $open['text'] .= substr($text, $offset, $position - $offset);
            }

            $offset = $position + strlen($tag);

            if (preg_match("/^<{$prefix}(\d+)>$/", $tag, $id)) {
                if ($open !== null) {
                    $result['problems'][] = "pair {$id[1]} nested in {$open['id']}";
                }

                $open = ['id' => $id[1], 'text' => ''];
                $result['pairs'][] = $id[1];
            } elseif (preg_match("/^<\/{$prefix}(\d+)>$/", $tag, $id)) {
                if ($open === null || $open['id'] !== $id[1]) {
                    $result['problems'][] = "unbalanced close {$id[1]}";
                } elseif (trim($open['text']) === '') {
                    $result['problems'][] = "pair {$id[1]} is empty";
                }

                $open = null;
            } elseif (preg_match('/^<x(\d+)\/>$/', $tag, $id)) {
                $result['atoms'][] = $id[1];
            } elseif ($tag === '<br/>') {
                $result['breaks']++;
            } else {
                $result['problems'][] = "unknown tag {$tag}";
            }
        }

        if ($open !== null) {
            $result['problems'][] = "pair {$open['id']} not closed";
        }

        $result['pairs'] = array_values(array_unique($result['pairs']));

        return $result;
    }
}
