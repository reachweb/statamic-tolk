<?php

namespace Reach\StatamicTolk\Content\Handlers\Concerns;

use Closure;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\LeafRules;
use Reach\StatamicTolk\Content\Markup\Placeholders;
use Reach\StatamicTolk\Content\Markup\Tokenizer;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Enums\SegmentKind;
use Statamic\Fields\Fieldtype;

trait MakesLeaves
{
    /**
     * Offers one string as a `plain` leaf and returns its translation, or the string itself when it is no leaf or its
     * leaf kept its text.
     */
    private function plain(string $text, ?string $hint, Closure $translate, WalkContext $context): string
    {
        $tokenized = Tokenizer::tokenize($text, SegmentKind::Plain);

        if (! LeafRules::isTranslatable(Placeholders::visible($tokenized->text, SegmentKind::Plain))) {
            return $text;
        }

        $translated = $translate($this->leaf($tokenized->text, SegmentKind::Plain, $tokenized->placeholders, $hint, $context));

        return $translated === $tokenized->text ? $text : Tokenizer::restore($translated, $tokenized);
    }

    private function leaf(string $text, SegmentKind $kind, array $placeholders, ?string $hint, WalkContext $context): Leaf
    {
        return new Leaf(
            text: $text,
            kind: $kind,
            path: $context->path(),
            label: $context->label(),
            hint: $hint,
            placeholders: $placeholders,
        );
    }

    private function hint(Fieldtype $fieldtype): ?string
    {
        $hint = $fieldtype->config('tolk_hint');

        return is_string($hint) && trim($hint) !== '' ? trim($hint) : null;
    }
}
