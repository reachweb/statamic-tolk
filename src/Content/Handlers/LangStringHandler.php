<?php

namespace Reach\StatamicTolk\Content\Handlers;

use Closure;
use Reach\StatamicTolk\Content\Handlers\Concerns\MakesLeaves;
use Reach\StatamicTolk\Content\Markup\Tokenized;
use Reach\StatamicTolk\Content\Markup\Tokenizer;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Statamic\Fields\Field;

/**
 * One string of a Laravel translation file, the value of a synthetic field of LangTarget or FormsTarget (§A16). Laravel's
 * parameters (`:name`, `:Name`, `:NAME`) are atoms. A string is cut at each `|`, as Laravel's MessageSelector cuts a
 * pluralised one, because nothing tells whether `trans_choice()` reads it: each variant is a leaf under {…path, index},
 * marked as a plural form, and its `{n}` or `[a,b]` prefix and the whitespace around its text stay outside the leaf. A
 * field whose config sets PLURAL to false is never cut, as for a form text, which Statamic looks up with `trans()`. A
 * text with HTML tags is an `html` leaf, as in a text field, and any other a `plain` one.
 */
final class LangStringHandler implements FieldHandler
{
    use MakesLeaves;

    public const string HANDLE = 'lang_string';

    // The field config key that, set to false, keeps a string with `|` whole.
    public const string PLURAL = 'tolk_plural';

    // A colon and a name, not after a letter, digit, colon or slash, so `10:30` and `https://…` hold no parameter.
    public const string PARAMETER = '/((?<![\p{L}\p{N}_:\/]):[A-Za-z_][A-Za-z0-9_]*)/u';

    // MessageSelector's condition: `{0}`, `{1}`, `[2,*]`, `[*,-1]` at the very start of a variant.
    public const string CONDITION = '/^[\{\[][-?\d*,.]*[\}\]]/';

    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $variants = $field->get(self::PLURAL) === false ? [$value] : self::variants($value);

        if (count($variants) === 1) {
            return $this->block($value, self::tokenize(...), null, $translate, $context);
        }

        foreach ($variants as $index => $variant) {
            $condition = preg_match(self::CONDITION, $variant, $match) === 1 ? $match[0] : '';
            $hint = sprintf(
                'Part %d of %d of a string divided by |%s. Translate only this part, without a | character.',
                $index + 1,
                count($variants),
                $condition === '' ? '' : ", used for the count {$condition}",
            );

            $variants[$index] = $condition.$this->block(substr($variant, strlen($condition)), self::tokenize(...), $hint, $translate, $context->with($index), pluralForm: true);
        }

        return implode('|', $variants);
    }

    /**
     * The variants of a string as Laravel's MessageSelector cuts it; a string without `|` is its only variant.
     *
     * @return list<string>
     */
    public static function variants(string $value): array
    {
        return explode('|', $value);
    }

    private static function tokenize(string $text): Tokenized
    {
        return Tokenizer::containsHtml($text)
            ? Tokenizer::htmlWithAtoms($text, self::PARAMETER)
            : Tokenizer::plainWithAtoms($text, self::PARAMETER);
    }
}
