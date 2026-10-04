<?php

namespace Reach\StatamicTolk\Content\Handlers;

use Closure;
use Reach\StatamicTolk\Content\Handlers\Concerns\MakesLeaves;
use Reach\StatamicTolk\Content\Handlers\Concerns\MapsSets;
use Reach\StatamicTolk\Content\LeafRules;
use Reach\StatamicTolk\Content\Markup\InlineMarkup;
use Reach\StatamicTolk\Content\Markup\Placeholders;
use Reach\StatamicTolk\Content\Markup\Tokenizer;
use Reach\StatamicTolk\Content\WalkContext;
use Reach\StatamicTolk\Contracts\FieldHandler;
use Reach\StatamicTolk\Enums\SegmentKind;
use Statamic\Fields\Field;
use Statamic\Fields\Fieldtype;

/**
 * Walks Bard's ProseMirror nodes block by block (D25): the inline content of each block is one `inline` leaf, link
 * titles and image alts are `plain` leaves, and set values are mapped with the set's fields. A value saved as HTML is
 * one `html` leaf per top-level block.
 */
final class BardHandler implements FieldHandler
{
    use MakesLeaves;
    use MapsSets;

    private const array BLOCK_TYPES = [
        'blockquote', 'bulletList', 'codeBlock', 'heading', 'horizontalRule', 'listItem', 'orderedList', 'paragraph',
        'set', 'table', 'tableCell', 'tableHeader', 'tableRow',
    ];

    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        $fieldtype = $field->fieldtype();
        $hint = $this->hint($fieldtype);

        if (is_string($value)) {
            return $this->mapHtml($value, $hint, $translate, $context);
        }

        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return $value;
        }

        if (! $fieldtype->config('inline') && $this->isLegacy($fieldtype, $value)) {
            $context->warn("{$context->label()} (".implode('.', $context->path()).') is stored in the Bard format of Statamic 2 and was left untouched. Saving it once in the control panel converts it.');

            return $value;
        }

        return $this->mapNodes($value, $fieldtype, $hint, $translate, $context);
    }

    private function mapHtml(string $value, ?string $hint, Closure $translate, WalkContext $context): string
    {
        $pieces = array_map(
            fn (string $piece) => $this->htmlBlock($piece, $hint, $translate, $context),
            Tokenizer::splitHtmlBlocks($value),
        );

        return implode('', $pieces);
    }

    /**
     * The leaf of a block element is its content, so a translation cannot put text outside the block: text between
     * blocks would belong to the next block on the next walk.
     */
    private function htmlBlock(string $piece, ?string $hint, Closure $translate, WalkContext $context): string
    {
        $core = trim($piece, self::WHITESPACE);
        [$open, $content] = Tokenizer::unwrapBlock($core) ?? ['', $core];
        $translated = $this->block($content, fn (string $html) => Tokenizer::tokenize($html, SegmentKind::Html), $hint, $translate, $context);

        return substr_replace($piece, $translated, strspn($piece, self::WHITESPACE) + strlen($open), strlen($content));
    }

    private function mapNodes(array $nodes, Fieldtype $fieldtype, ?string $hint, Closure $translate, WalkContext $context): array
    {
        if ($this->isInline($nodes)) {
            return $this->mapInline($nodes, $hint, $translate, $context);
        }

        foreach ($nodes as $index => $node) {
            $type = $node['type'] ?? null;
            $content = $node['content'] ?? null;

            if ($type === 'set') {
                if (is_array($node['attrs']['values'] ?? null)) {
                    $nodes[$index]['attrs']['values'] = $this->mapSet($fieldtype, $node['attrs']['values'], $index, $translate, $context, 'attrs', 'values');
                }
            } elseif ($type === 'image') {
                $nodes[$index] = $this->mapAlt($node, $translate, $context);
            } elseif ($type !== 'codeBlock' && is_array($content) && array_is_list($content)) {
                $nodes[$index]['content'] = $this->mapNodes($content, $fieldtype, $hint, $translate, $context);
            }
        }

        return $nodes;
    }

    /**
     * Link titles first, then the block's text, then the alts of its images, in source order.
     */
    private function mapInline(array $content, ?string $hint, Closure $translate, WalkContext $context): array
    {
        $nodes = InlineMarkup::mapLinkTitles($content, fn (string $title) => $this->plain($title, 'link title', $translate, $context));
        $serialized = InlineMarkup::serialize($nodes);
        $translated = $serialized->text;

        if (LeafRules::isTranslatable(Placeholders::visible($serialized->text, SegmentKind::Inline))) {
            $translated = $translate($this->leaf($serialized->text, SegmentKind::Inline, $serialized->placeholders, $hint, $context));
        }

        $altered = false;

        foreach ($nodes as $index => $node) {
            if (($node['type'] ?? null) === 'image') {
                $nodes[$index] = $this->mapAlt($node, $translate, $context);
                $altered = $altered || $nodes[$index] !== $node;
            }
        }

        // Alts and titles are in neither the text nor the descriptors, so a second serialization only carries them into the map.
        return InlineMarkup::parse($translated, $altered ? InlineMarkup::serialize($nodes) : $serialized);
    }

    private function mapAlt(array $node, Closure $translate, WalkContext $context): array
    {
        $alt = $node['attrs']['alt'] ?? null;

        if (is_string($alt)) {
            $node['attrs']['alt'] = $this->plain($alt, 'image alt text', $translate, $context);
        }

        return $node;
    }

    /**
     * Inline content holds text and inline nodes only. A node with content of its own makes a list of blocks, unless
     * text sits next to it: ProseMirror never mixes the two.
     */
    private function isInline(array $nodes): bool
    {
        $text = false;
        $nested = false;

        foreach ($nodes as $node) {
            if (! is_array($node) || in_array($node['type'] ?? null, self::BLOCK_TYPES, true)) {
                return false;
            }

            $text = $text || in_array($node['type'] ?? null, ['text', 'hardBreak'], true);
            $nested = $nested || is_array($node['content'] ?? null);
        }

        return $text || ! $nested;
    }

    /**
     * Statamic 2 stored HTML in `text` items between the sets; Bard converts such a value only when the CP loads it
     * (Bard::isLegacyData()).
     */
    private function isLegacy(Fieldtype $fieldtype, array $value): bool
    {
        $types = array_map('strval', array_keys($fieldtype->flattenedSetsConfig()->all()));

        if ($types === []) {
            return false;
        }

        $types[] = 'text';

        foreach ($value as $node) {
            if (! in_array($node['type'] ?? null, $types, true)) {
                return false;
            }
        }

        return true;
    }
}
