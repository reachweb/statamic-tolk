<?php

namespace Reach\StatamicTolk\Tests\Support;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * A simple reading of a Vue single-file component's template for `tests/Feature/StringsTest.php`: the text a user sees
 * that does not go through `__()` or `__n()`, buttons without a name, and badges without text. It is a heuristic, not
 * a parser: tags, attributes, text and `{{ }}` are told apart, and inside an expression a string literal counts as text
 * when it holds a space or starts with a capital letter, after the first arguments of `__()` and `__n()` are removed.
 */
final class TemplateStrings
{
    /**
     * Attributes whose static value the user reads or hears.
     */
    private const array TEXT_ATTRIBUTES = [
        'alt', 'append', 'aria-description', 'aria-label', 'aria-valuetext', 'body-text', 'button-text', 'cancel-text',
        'description', 'heading', 'instructions', 'label', 'placeholder', 'prepend', 'text', 'title',
    ];

    private const string LITERAL = '(?:\'(?:[^\'\\\\\n]|\\\\.)*\'|"(?:[^"\\\\\n]|\\\\.)*"|`(?:[^`\\\\]|\\\\.)*`)';

    // The first argument of `__()` or `__n()`, with the call.
    private const string TRANSLATED = '/\b__n?\(\s*'.self::LITERAL.'/s';

    /**
     * Bound attributes that hold classes and styles, not text.
     */
    private const array STYLE_ATTRIBUTES = [':class', ':style', 'v-bind:class', 'v-bind:style'];

    /**
     * The CP's scripts and components, without their tests.
     *
     * @return list<SplFileInfo>
     */
    public static function cpScripts(): array
    {
        return collect(File::allFiles(dirname(__DIR__, 2).'/resources/js'))
            ->filter(fn (SplFileInfo $file) => in_array($file->getExtension(), ['vue', 'js'], true) && ! str_ends_with($file->getFilename(), '.test.js'))
            ->values()
            ->all();
    }

    /**
     * @return list<string> one line per finding: "{line}: {what}"
     */
    public static function findings(string $component): array
    {
        $template = self::template($component);

        if ($template === null) {
            return [];
        }

        [$source, $offset] = $template;
        $findings = [];

        foreach (self::nodes($source) as $node) {
            $line = substr_count($component, "\n", 0, $offset + $node['at']) + 1;

            foreach (self::check($node, $source) as $finding) {
                $findings[] = "{$line}: {$finding}";
            }
        }

        return $findings;
    }

    /**
     * The sentences in script code that do not go through `__()` or `__n()`: string literals that start with a capital
     * letter and hold two words, or hold three. Comments, the messages of thrown errors and lists of classes are left
     * out. A Vue component's `<script>` blocks are read; other files whole.
     *
     * @return list<string> one line per finding: "{line}: {literal}"
     */
    public static function scriptFindings(string $file, bool $component): array
    {
        $blocks = $component
            ? (preg_match_all('/<script\b[^>]*>(.*?)<\/script>/s', $file, $matches, PREG_OFFSET_CAPTURE) ? $matches[1] : [])
            : [[$file, 0]];
        $findings = [];

        foreach ($blocks as [$code, $offset]) {
            // Blank out what is not code to read, keeping every character's position for the line numbers.
            $blank = fn (array $match) => preg_replace('/[^\n]/', ' ', $match[0]);
            $code = preg_replace_callback('/(?<![:\'"`\\\\])\/\/[^\n]*|\/\*.*?\*\//s', $blank, $code);
            $code = preg_replace_callback('/\bnew\s+\w*Error\(\s*'.self::LITERAL.'/s', $blank, $code);
            $code = preg_replace_callback(self::TRANSLATED, $blank, $code);

            preg_match_all('/'.self::LITERAL.'/s', $code, $literals, PREG_OFFSET_CAPTURE);

            foreach ($literals[0] as [$literal, $at]) {
                if (self::isSentence($literal)) {
                    $findings[] = (substr_count($file, "\n", 0, $offset + $at) + 1).": {$literal}";
                }
            }
        }

        return $findings;
    }

    /**
     * The root template's content and where it starts in the file, or null without one.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function template(string $component): ?array
    {
        if (preg_match('/<template(?:\s[^>]*)?>/', $component, $open, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = $open[0][1] + strlen($open[0][0]);
        $end = strrpos($component, '</template>');

        return $end === false || $end < $start ? null : [substr($component, $start, $end - $start), $start];
    }

    /**
     * @return list<array{kind: string, at: int, text?: string, name?: string, attributes?: list<array{name: string, value: ?string}>, end?: int, closing?: bool, selfClosing?: bool}>
     */
    private static function nodes(string $source): array
    {
        $nodes = [];
        $length = strlen($source);
        $i = 0;

        while ($i < $length) {
            if (str_starts_with(substr($source, $i, 4), '<!--')) {
                $end = strpos($source, '-->', $i);
                $i = $end === false ? $length : $end + 3;

                continue;
            }

            if (preg_match('/\G<(\/?)([A-Za-z][\w.:-]*)/', $source, $match, 0, $i) === 1) {
                $nodes[] = $tag = self::tag($source, $i, $match);
                $i = $tag['end'];

                continue;
            }

            if (str_starts_with(substr($source, $i, 2), '{{')) {
                $end = strpos($source, '}}', $i);
                $end = $end === false ? $length : $end;
                $nodes[] = ['kind' => 'expression', 'at' => $i, 'text' => substr($source, $i + 2, $end - $i - 2)];
                $i = $end + 2;

                continue;
            }

            $next = self::nextSpecial($source, $i + 1);
            $text = substr($source, $i, $next - $i);
            $nodes[] = ['kind' => 'text', 'at' => $i + strlen($text) - strlen(ltrim($text)), 'text' => $text];
            $i = $next;
        }

        return $nodes;
    }

    private static function nextSpecial(string $source, int $from): int
    {
        $positions = array_filter([
            preg_match('/<(?:\/?[A-Za-z]|!--)/', $source, $match, PREG_OFFSET_CAPTURE, $from) === 1 ? $match[0][1] : null,
            strpos($source, '{{', $from),
        ], fn ($position) => is_int($position));

        return $positions === [] ? strlen($source) : min($positions);
    }

    /**
     * @return array{kind: string, at: int, name: string, closing: bool, selfClosing: bool, attributes: list<array{name: string, value: ?string}>, end: int} the tag, `end` the position after it
     */
    private static function tag(string $source, int $at, array $match): array
    {
        $length = strlen($source);
        $i = $at + strlen($match[0]);
        $attributes = [];
        $selfClosing = false;

        while ($i < $length) {
            if (preg_match('/\G\s+/', $source, $space, 0, $i) === 1) {
                $i += strlen($space[0]);

                continue;
            }

            if ($source[$i] === '>') {
                $i++;

                break;
            }

            if (substr($source, $i, 2) === '/>') {
                $selfClosing = true;
                $i += 2;

                break;
            }

            preg_match('/\G[^\s=\/>]+/', $source, $name, 0, $i);
            $name = $name[0] ?? $source[$i];
            $i += strlen($name);
            $value = null;

            if (preg_match('/\G\s*=\s*(["\'])/', $source, $quote, 0, $i) === 1) {
                $start = $i + strlen($quote[0]);
                $end = strpos($source, $quote[1], $start);
                $end = $end === false ? $length : $end;
                $value = substr($source, $start, $end - $start);
                $i = $end + 1;
            }

            $attributes[] = ['name' => $name, 'value' => $value];
        }

        return [
            'kind' => 'tag',
            'at' => $at,
            'name' => $match[2],
            'closing' => $match[1] === '/',
            'selfClosing' => $selfClosing,
            'attributes' => $attributes,
            'end' => $i,
        ];
    }

    /**
     * @return list<string>
     */
    private static function check(array $node, string $source): array
    {
        return match ($node['kind']) {
            'text' => preg_match('/\p{L}/u', $node['text']) === 1 ? ['text outside __(): '.self::excerpt($node['text'])] : [],
            'expression' => array_map(fn (string $literal) => "string literal outside __(): {$literal}", self::literals($node['text'])),
            'tag' => $node['closing'] ? [] : self::checkTag($node, $source),
        };
    }

    /**
     * @return list<string>
     */
    private static function checkTag(array $tag, string $source): array
    {
        $findings = [];
        $names = [];

        foreach ($tag['attributes'] as ['name' => $name, 'value' => $value]) {
            $names[] = self::boundName($name);

            if ($value === null) {
                continue;
            }

            if (self::isExpression($name)) {
                if (! in_array($name, self::STYLE_ATTRIBUTES, true)) {
                    foreach (self::literals($value) as $literal) {
                        $findings[] = "string literal outside __() in {$name}: {$literal}";
                    }
                }
            } elseif (in_array($name, self::TEXT_ATTRIBUTES, true) && preg_match('/\p{L}/u', $value) === 1) {
                $findings[] = "static {$name} outside __(): ".self::excerpt($value);
            }
        }

        $content = self::content($tag, $source);
        $named = array_intersect($names, ['text', 'aria-label', 'aria-labelledby', 'v-bind']) !== [] || $content;

        if (in_array($tag['name'], ['Button', 'button'], true) && ! $named) {
            $findings[] = "{$tag['name']} without text or aria-label";
        }

        if ($tag['name'] === 'Badge' && ! $named) {
            $findings[] = 'Badge without text';
        }

        return $findings;
    }

    private static function isExpression(string $name): bool
    {
        return str_starts_with($name, ':') || str_starts_with($name, '@') || str_starts_with($name, 'v-');
    }

    // `:text` and `v-bind:text` name the same prop as `text`; a bare `v-bind` spreads an object of props.
    private static function boundName(string $name): string
    {
        return preg_replace('/^(?::|v-bind:)/', '', $name);
    }

    /**
     * Whether a tag that is not self-closing holds anything before its closing tag.
     */
    private static function content(array $tag, string $source): bool
    {
        if ($tag['selfClosing']) {
            return false;
        }

        $close = strpos($source, "</{$tag['name']}>", $tag['end']);

        return $close !== false && trim(substr($source, $tag['end'], $close - $tag['end'])) !== '';
    }

    /**
     * The string literals of an expression that read as text, once the keys of `__()` and `__n()` are taken out.
     *
     * @return list<string>
     */
    private static function literals(string $expression): array
    {
        $expression = preg_replace(self::TRANSLATED, '__(', $expression);

        preg_match_all('/'.self::LITERAL.'/s', $expression, $matches);

        return array_values(array_filter($matches[0], function (string $match) {
            $text = self::literalText($match);

            return preg_match('/\p{L}/u', $text) === 1 && (preg_match('/\s/', $text) === 1 || preg_match('/^\p{Lu}\p{Ll}/u', $text) === 1);
        }));
    }

    // In script code, short identifiers and keys are everywhere, so only what reads as a sentence counts.
    private static function isSentence(string $literal): bool
    {
        $text = trim(self::literalText($literal));

        if (preg_match('/\p{L}/u', $text) !== 1 || self::isClassList($text)) {
            return false;
        }

        // Words of letters only, so that selectors and paths do not count.
        $words = preg_match_all('/(?<!\S)\p{L}+[.,:;!?…]*(?!\S)/u', $text);

        return (preg_match('/^\p{Lu}\p{Ll}/u', $text) === 1 && $words >= 2) || $words >= 3;
    }

    // Tailwind classes such as `rounded-sm bg-red-100 dark:bg-red-900/40`.
    private static function isClassList(string $text): bool
    {
        $tokens = preg_split('/\s+/', $text);

        return array_filter($tokens, fn (string $token) => preg_match('/^[a-z0-9:!\[\]\/._%-]+$/', $token) !== 1) === []
            && array_filter($tokens, fn (string $token) => str_contains($token, '-')) !== [];
    }

    // A literal's text without its quotes and the `${…}` of a template literal.
    private static function literalText(string $literal): string
    {
        return preg_replace('/\$\{[^}]*\}/', '', substr($literal, 1, -1));
    }

    private static function excerpt(string $text): string
    {
        return mb_strimwidth(trim(preg_replace('/\s+/', ' ', $text)), 0, 80, '…');
    }
}
