<?php

namespace Reach\StatamicTolk\Content\Handlers;

use Closure;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\LeafRules;
use Reach\StatamicTolk\Content\Markup\Placeholders;
use Reach\StatamicTolk\Content\Markup\Tokenizer;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Reach\StatamicTolk\Enums\SegmentKind;
use Statamic\Fields\Field;

/**
 * One leaf per block, whatever the length of the value, so a source and its translation have the same leaves.
 */
final class MarkdownHandler implements FieldHandler
{
    private const string WHITESPACE = " \t\r\n";

    // A list item's marker at the start of a line, and the spaces between it and the item's content.
    private const string LIST_ITEM = '/^([ \t]*)([-+*]|\d{1,9}[.)])(?:([ \t]+)\S|[ \t]*\r?$)/m';

    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $blocks = Tokenizer::splitMarkdownBlocks($value);
        $labels = Tokenizer::referenceLabels($value);
        $hint = $field->fieldtype()->config()['tolk_hint'] ?? null;
        $list = null;
        $changed = false;

        foreach ($blocks as $index => $block) {
            if ($index % 2 === 1 || $this->isIndentedCode($block, $list)) {
                continue;
            }

            $list = $this->listColumn($block, $list);
            $core = trim($block, self::WHITESPACE);
            $tokenized = Tokenizer::markdown($core, $labels);

            if (! LeafRules::isTranslatable(Placeholders::visible($tokenized->text, SegmentKind::Markdown))) {
                continue;
            }

            $translated = $translate(new Leaf(
                text: $tokenized->text,
                kind: SegmentKind::Markdown,
                path: $context->path(),
                label: $context->label(),
                hint: is_string($hint) && trim($hint) !== '' ? trim($hint) : null,
                placeholders: $tokenized->placeholders,
            ));

            if ($translated !== $tokenized->text) {
                $lead = substr($block, 0, strspn($block, self::WHITESPACE));
                $blocks[$index] = $lead.Tokenizer::restore($translated, $tokenized).substr($block, strlen($lead) + strlen($core));
                $changed = true;
            }
        }

        return $changed ? implode('', $blocks) : $value;
    }

    /**
     * Every line indented four columns past the content of the list item the block continues, or past the margin
     * outside a list. A list item's continuation paragraph is indented too, but less.
     */
    private function isIndentedCode(string $block, ?int $list): bool
    {
        $lines = array_filter(explode("\n", $block), fn (string $line) => trim($line, self::WHITESPACE) !== '');

        foreach ($lines as $line) {
            if ($this->indentation($line) < ($list ?? 0) + 4) {
                return false;
            }
        }

        return $lines !== [];
    }

    /**
     * The content column of the list item the next block may continue: the last item of this block, or, for a block
     * without items, the list the block is indented into. A block at the margin ends the list.
     */
    private function listColumn(string $block, ?int $list): ?int
    {
        if (preg_match_all(self::LIST_ITEM, $block, $items, PREG_SET_ORDER) > 0) {
            $item = end($items);
            $spaces = $this->width($item[3] ?? '');

            return $this->width($item[1]) + strlen($item[2]) + ($spaces >= 1 && $spaces <= 4 ? $spaces : 1);
        }

        $indentation = $this->indentation($block);

        return $list === null || $indentation === 0 ? null : min($list, $indentation);
    }

    private function indentation(string $line): int
    {
        return $this->width(substr($line, 0, strspn($line, " \t")));
    }

    private function width(string $whitespace): int
    {
        return strlen(str_replace("\t", '    ', $whitespace));
    }
}
