<?php

namespace Reach\StatamicTolk\Content\Handlers;

use Closure;
use InvalidArgumentException;
use League\CommonMark\Environment\EnvironmentInterface;
use Reach\StatamicTolk\Content\Handlers\Concerns\MakesLeaves;
use Reach\StatamicTolk\Content\Leaf;
use Reach\StatamicTolk\Content\Markup\MarkdownDocument;
use Reach\StatamicTolk\Content\Markup\Tokenizer;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Statamic\Facades\Markdown;
use Statamic\Fields\Field;

/**
 * One leaf per block, whatever the length of the value, so a source and its translation have the same leaves. Each block
 * is validated on its own, so the translated value as a whole must still hold the source's code as code (§A3 rule 7).
 */
final class MarkdownHandler implements FieldHandler
{
    use MakesLeaves;

    // A list item's marker at the start of a line, and the spaces between it and the item's content.
    private const string LIST_ITEM = '/^([ \t]*)([-+*]|\d{1,9}[.)])(?:([ \t]+)\S|[ \t]*\r?$)/m';

    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $blocks = $source = Tokenizer::splitMarkdownBlocks($value);
        $labels = Tokenizer::referenceLabels($value);
        $hint = $this->hint($field->fieldtype());
        $list = null;
        $offered = [];

        foreach ($blocks as $index => $block) {
            if ($index % 2 === 1 || $this->isIndentedCode($block, $list)) {
                continue;
            }

            $list = $this->listColumn($block, $list);
            $blocks[$index] = $this->block($block, fn (string $core) => Tokenizer::markdown($core, $labels), $hint, function (Leaf $leaf) use ($translate, &$offered, $index): string {
                $offered[$index] = $leaf;

                return $translate($leaf);
            }, $context);
        }

        return implode('', $blocks === $source ? $blocks : $this->keepingCode($field, $source, $blocks, $offered, $context));
    }

    /**
     * The translated blocks, as long as the document they make still holds the source's code as code and no raw HTML tag
     * beyond the source's (MarkdownDocument): a list marker, a description or a wider list item a translation adds makes
     * the indented code of the next block the item's content, and a heading marker it drops makes the code under it text.
     * Otherwise each translated block is taken in turn while the document stays so, and one that would change it keeps
     * its source, which is reported to the walk.
     *
     * @param  list<string>  $source
     * @param  list<string>  $blocks
     * @param  array<int, Leaf>  $offered  block index → the leaf offered for it
     * @return list<string>
     */
    private function keepingCode(Field $field, array $source, array $blocks, array $offered, WalkContext $context): array
    {
        $environment = $this->environment($field);
        $expected = MarkdownDocument::parse(implode('', $source), $environment);

        if (MarkdownDocument::parse(implode('', $blocks), $environment)->keeps($expected)) {
            return $blocks;
        }

        $kept = $source;

        foreach ($blocks as $index => $block) {
            if ($block === $source[$index]) {
                continue;
            }

            $candidate = $kept;
            $candidate[$index] = $block;

            if (MarkdownDocument::parse(implode('', $candidate), $environment)->keeps($expected)) {
                $kept = $candidate;
            } else {
                $context->keep($offered[$index]);
            }
        }

        return $kept;
    }

    /**
     * The environment of the field's Markdown parser, which reads the page's code and HTML as the page will.
     */
    private function environment(Field $field): EnvironmentInterface
    {
        $parser = $field->fieldtype()->config('parser');

        try {
            return Markdown::parser(is_string($parser) && $parser !== '' ? $parser : 'default')->environment();
        } catch (InvalidArgumentException) {
            return Markdown::parser('default')->environment();
        }
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
