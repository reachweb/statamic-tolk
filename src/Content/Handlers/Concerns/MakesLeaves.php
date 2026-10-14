<?php

namespace Reach\StatamicTolk\Content\Handlers\Concerns;

use Closure;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\LeafRules;
use Reach\StatamicTolk\Content\Markup\Placeholders;
use Reach\StatamicTolk\Content\Markup\Tokenized;
use Reach\StatamicTolk\Content\Markup\Tokenizer;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Enums\SegmentKind;
use Statamic\Fields\Fieldtype;

trait MakesLeaves
{
    private const string WHITESPACE = " \t\r\n";

    /**
     * Offers one string as a leaf and returns its translation, or the string itself when it is no leaf or its leaf kept
     * its text. A string with HTML tags is an `html` leaf, as in a text field, so its tags are placeholders the model
     * must return; any other is a `plain` one.
     */
    private function plain(string $text, ?string $hint, Closure $translate, WalkContext $context): string
    {
        $kind = Tokenizer::containsHtml($text) ? SegmentKind::Html : SegmentKind::Plain;

        return $this->offer(Tokenizer::tokenize($text, $kind), $hint, $translate, $context) ?? $text;
    }

    /**
     * Offers one block of a field trimmed of its surrounding whitespace, which stays outside the leaf, and returns the
     * block with its translation, or the block itself when it is no leaf or its leaf kept its text.
     *
     * @param  Closure(string): Tokenized  $tokenize
     */
    private function block(string $block, Closure $tokenize, ?string $hint, Closure $translate, WalkContext $context, bool $pluralForm = false): string
    {
        $core = trim($block, self::WHITESPACE);
        $restored = $this->offer($tokenize($core), $hint, $translate, $context, $pluralForm);

        return $restored === null ? $block : substr_replace($block, $restored, strspn($block, self::WHITESPACE), strlen($core));
    }

    /**
     * The restored translation of a tokenized text, or null when it is no leaf or its leaf kept its text.
     */
    private function offer(Tokenized $tokenized, ?string $hint, Closure $translate, WalkContext $context, bool $pluralForm = false): ?string
    {
        if (! LeafRules::isTranslatable(Placeholders::visible($tokenized->text, $tokenized->kind))) {
            return null;
        }

        $translated = $translate($this->leaf($tokenized->text, $tokenized->kind, $tokenized->placeholders, $hint, $context, $pluralForm));

        return $translated === $tokenized->text ? null : Tokenizer::restore($translated, $tokenized);
    }

    private function leaf(string $text, SegmentKind $kind, array $placeholders, ?string $hint, WalkContext $context, bool $pluralForm = false): Leaf
    {
        return new Leaf(
            text: $text,
            kind: $kind,
            path: $context->path(),
            label: $context->label(),
            hint: $hint,
            placeholders: $placeholders,
            pluralForm: $pluralForm,
        );
    }

    private function hint(Fieldtype $fieldtype): ?string
    {
        $hint = $fieldtype->config('tolk_hint');

        return is_string($hint) && trim($hint) !== '' ? trim($hint) : null;
    }
}
