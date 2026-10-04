<?php

namespace Reach\StatamicTolk\Translation;

use Normalizer;
use Reach\StatamicTolk\Content\Markup\Placeholders;
use Reach\StatamicTolk\Enums\SegmentKind;
use Reach\StatamicTolk\Support\Settings;

/**
 * The glossary of one target site: the rows its prompts show, and the notes a translation gets when it seems to ignore
 * one of them. Terms are compared case-insensitively and without accents, so a capital or a moved accent never counts.
 */
final readonly class Glossary
{
    public const string WARNING = 'warning';

    public const string INFO = 'info';

    // Inflected languages change word endings, so a glossary translation counts as used when the first 70% of each of its words is there.
    private const float PREFIX = 0.7;

    private const string WORD = '[\p{L}\p{N}_]';

    // Replaces a term found in the source, so a shorter term inside it ("Bay" in "Kyma Bay") is not checked as well.
    private const string FOUND = "\u{FFFC}";

    /**
     * @var list<array{term: string, site: ?string, translation: string, do_not_translate: bool, note: string}>
     */
    private array $rows;

    /**
     * @var list<array{term: string, pattern: string, rows: list<array{term: string, translation: string, do_not_translate: bool, usage: ?string}>}> longest term first
     */
    private array $terms;

    /**
     * @param  list<array{term: string, site: ?string, translation: string, do_not_translate: bool, note: string}>  $rows  the rows of one site, as `Settings::glossary()` returns them
     */
    public function __construct(array $rows)
    {
        $ownTerms = [];

        foreach ($rows as $row) {
            if ($row['site'] !== null) {
                $ownTerms[self::fold($row['term'])] = true;
            }
        }

        // A term the site has its own row for drops the rows for every site.
        $this->rows = array_values(array_filter(
            $rows,
            fn (array $row) => $row['site'] !== null || ! isset($ownTerms[self::fold($row['term'])]),
        ));
        $this->terms = self::index($this->rows);
    }

    public static function fromSettings(Settings $settings, string $site): self
    {
        return new self($settings->glossary($site));
    }

    /**
     * @return list<array{term: string, site: ?string, translation: string, do_not_translate: bool, note: string}> sorted by term
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * Notes for a translation that leaves out a term the source uses: a warning for a term that must stay untranslated,
     * information for a term with a translation. They never fail a segment.
     *
     * @return list<array{level: string, message: string}>
     */
    public function check(string $source, string $translation, SegmentKind $kind = SegmentKind::Plain): array
    {
        if ($this->terms === []) {
            return [];
        }

        $source = self::visible($source, $kind);
        $translation = self::visible($translation, $kind);
        $notes = [];

        foreach ($this->terms as ['term' => $term, 'pattern' => $pattern, 'rows' => $rows]) {
            $source = preg_replace($pattern, self::FOUND, $source, -1, $found) ?? $source;

            if ($found === 0) {
                continue;
            }

            foreach ($rows as $row) {
                if ($row['do_not_translate'] && ! str_contains($translation, $term)) {
                    $notes[] = [
                        'level' => self::WARNING,
                        'message' => "the glossary keeps \"{$row['term']}\" untranslated, but the translation does not contain it.",
                    ];
                } elseif ($row['usage'] !== null && preg_match($row['usage'], $translation) !== 1) {
                    $notes[] = [
                        'level' => self::INFO,
                        'message' => "the glossary translates \"{$row['term']}\" as \"{$row['translation']}\", but the translation does not seem to use it.",
                    ];
                }
            }
        }

        return $notes;
    }

    /**
     * The folded terms, longest first, each with the pattern that finds it in a source and its rows. A row that gives a
     * translation carries the pattern that finds that translation in the target; the others carry none.
     */
    private static function index(array $rows): array
    {
        $terms = [];

        foreach ($rows as $row) {
            if (($term = self::fold($row['term'])) !== '') {
                $terms[$term][] = [
                    'term' => $row['term'],
                    'translation' => $row['translation'],
                    'do_not_translate' => $row['do_not_translate'],
                    'usage' => $row['do_not_translate'] ? null : self::usage(self::fold($row['translation'])),
                ];
            }
        }

        uksort($terms, fn (string|int $a, string|int $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a) ?: strcmp((string) $a, (string) $b));

        return array_map(
            fn (string|int $term, array $rows) => ['term' => (string) $term, 'pattern' => self::pattern((string) $term), 'rows' => $rows],
            array_keys($terms),
            array_values($terms),
        );
    }

    private static function pattern(string $term): string
    {
        $before = preg_match('/^'.self::WORD.'/u', $term) === 1 ? '(?<!'.self::WORD.')' : '';
        $after = preg_match('/'.self::WORD.'$/u', $term) === 1 ? '(?!'.self::WORD.')' : '';

        return '/'.$before.preg_quote($term, '/').$after.'/u';
    }

    /**
     * The first 70% of each word of a folded translation, in order, or null when there is nothing to look for.
     */
    private static function usage(string $expected): ?string
    {
        if ($expected === '') {
            return null;
        }

        $prefixes = array_map(
            fn (string $word) => preg_quote(mb_substr($word, 0, (int) ceil(mb_strlen($word) * self::PREFIX)), '/'),
            explode(' ', $expected),
        );

        return '/'.implode(self::WORD.'* ', $prefixes).'/u';
    }

    private static function visible(string $text, SegmentKind $kind): string
    {
        return self::fold(Placeholders::readable($text, $kind));
    }

    /**
     * Compatibility-decomposed, case-folded, without combining marks and with single spaces.
     */
    private static function fold(string $text): string
    {
        $text = mb_convert_case(Normalizer::normalize($text, Normalizer::FORM_KD) ?: $text, MB_CASE_FOLD, 'UTF-8');
        $text = preg_replace('/\p{Mn}+/u', '', $text) ?? $text;

        return trim(preg_replace('/[\s\p{Z}]+/u', ' ', $text) ?? $text);
    }
}
