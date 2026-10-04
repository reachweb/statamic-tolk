<?php

namespace Reach\StatamicTolk\Content\Handlers;

use Closure;
use Reach\StatamicTolk\Content\Handlers\Concerns\MapsSets;
use Reach\StatamicTolk\Content\Leaf;
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
    use MapsSets;

    private const string WHITESPACE = " \t\r\n";

    private const array BLOCK_TYPES = [
        'blockquote', 'bulletList', 'codeBlock', 'heading', 'horizontalRule', 'listItem', 'orderedList', 'paragraph',
        'set', 'table', 'tableCell', 'tableHeader', 'tableRow',
    ];

    public function map(Field $field, mixed $value, Closure $translate, WalkContext $context): mixed
    {
        $fieldtype = $field->fieldtype();
        $hint = $fieldtype->config('tolk_hint');
        $hint = is_string($hint) && trim($hint) !== '' ? trim($hint) : null;

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
        $pieces = Tokenizer::splitHtmlBlocks($value);
        $changed = false;

        foreach ($pieces as $index => $piece) {
            $core = trim($piece, self::WHITESPACE);
            $tokenized = Tokenizer::tokenize($core, SegmentKind::Html);

            if (! LeafRules::isTranslatable(Placeholders::visible($tokenized->text, SegmentKind::Html))) {
                continue;
            }

            $translated = $translate($this->leaf($tokenized->text, SegmentKind::Html, $tokenized->placeholders, $hint, $context));

            if ($translated !== $tokenized->text) {
                $lead = substr($piece, 0, strspn($piece, self::WHITESPACE));
                $pieces[$index] = $lead.Tokenizer::restore($translated, $tokenized).substr($piece, strlen($lead) + strlen($core));
                $changed = true;
            }
        }

        return $changed ? implode('', $pieces) : $value;
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

    private function plain(string $text, string $hint, Closure $translate, WalkContext $context): string
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

        foreach ($value as $node) {
            if (! in_array($node['type'] ?? null, [...$types, 'text'], true)) {
                return false;
            }
        }

        return true;
    }
}
