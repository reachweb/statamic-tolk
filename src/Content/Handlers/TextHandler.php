<?php

namespace Reach\StatamicTolk\Content\Handlers;

use Closure;
use Reach\StatamicTolk\Content\Handlers\Concerns\MakesLeaves;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\LeafRules;
use Reach\StatamicTolk\Content\Markup\Placeholders;
use Reach\StatamicTolk\Content\Markup\Tokenizer;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Reach\StatamicTolk\Enums\SegmentKind;
use Statamic\Fields\Field;

final class TextHandler implements FieldHandler
{
    use MakesLeaves;

    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $fieldtype = $field->fieldtype();
        $config = $fieldtype->config();

        if (! self::holdsProse($config)) {
            return $value;
        }

        $kind = Tokenizer::containsHtml($value) ? SegmentKind::Html : SegmentKind::Plain;
        $tokenized = Tokenizer::tokenize($value, $kind);

        if (! LeafRules::isTranslatable(Placeholders::visible($tokenized->text, $kind))) {
            return $value;
        }

        $translated = $translate(new Leaf(
            text: $tokenized->text,
            kind: $kind,
            path: $context->path(),
            label: $context->label(),
            hint: $this->hint($fieldtype),
            maxLength: $this->maxLength($config['character_limit'] ?? null),
            placeholders: $tokenized->placeholders,
        ));

        return $translated === $tokenized->text ? $value : Tokenizer::restore($translated, $tokenized);
    }

    // Only the text input type holds prose; email, url, tel, number, color and the like are data.
    public static function holdsProse(array $config): bool
    {
        return in_array($config['input_type'] ?? null, [null, 'text'], true);
    }

    private function maxLength(mixed $limit): ?int
    {
        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;
    }
}
