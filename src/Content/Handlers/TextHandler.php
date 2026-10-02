<?php

namespace Reach\StatamicTolk\Content\Handlers;

use Closure;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\LeafRules;
use Reach\StatamicTolk\Content\Markup\Tokenizer;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Reach\StatamicTolk\Enums\SegmentKind;
use Statamic\Fields\Field;

final class TextHandler implements FieldHandler
{
    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        if (! is_string($value) || ! LeafRules::isTranslatable($value)) {
            return $value;
        }

        $config = $field->fieldtype()->config();

        // Only the text input type holds prose; email, url, tel, number, color and the like are data.
        if (! in_array($config['input_type'] ?? null, [null, 'text'], true)) {
            return $value;
        }

        if (Tokenizer::containsHtml($value)) {
            $context->warn("{$context->label()} (".implode('.', $context->path()).') contains HTML and was left untouched.');

            return $value;
        }

        $tokenized = Tokenizer::tokenize($value, SegmentKind::Plain);

        $translated = $translate(new Leaf(
            text: $tokenized->text,
            kind: SegmentKind::Plain,
            path: $context->path(),
            label: $context->label(),
            hint: $this->hint($config['tolk_hint'] ?? null),
            maxLength: $this->maxLength($config['character_limit'] ?? null),
            placeholders: $tokenized->placeholders,
        ));

        return $translated === $tokenized->text ? $value : Tokenizer::restore($translated, $tokenized);
    }

    private function hint(mixed $hint): ?string
    {
        return is_string($hint) && trim($hint) !== '' ? trim($hint) : null;
    }

    private function maxLength(mixed $limit): ?int
    {
        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;
    }
}
