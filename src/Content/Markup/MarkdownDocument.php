<?php

namespace Reach\StatamicTolk\Content\Markup;

use League\CommonMark\Environment\EnvironmentInterface;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Parser\MarkdownParser;

/**
 * A whole Markdown document as Statamic's parser reads it: its code (fenced, indented and inline) and the tags of its raw
 * HTML. A field is validated block by block (§A3 rule 7), but a container one block leaves open reaches into the next: a
 * list marker a translation adds makes the indented code after it the item's content, rendered as Markdown or raw HTML.
 */
final readonly class MarkdownDocument
{
    // What a browser starts to read as a tag in raw HTML, up to its ">" or to the next "<".
    private const string TAG = '/<[a-zA-Z\/!?][^<>]*+>?/';

    /**
     * @param  array<string, int>  $code  each literal of a code block or span → how often
     * @param  array<string, int>  $tags  each tag in raw HTML → how often
     */
    private function __construct(private array $code, private array $tags) {}

    public static function parse(string $markdown, EnvironmentInterface $environment): self
    {
        $code = $tags = [];
        $walker = (new MarkdownParser($environment))->parse($markdown)->walker();

        while ($event = $walker->next()) {
            $node = $event->getNode();

            if (! $event->isEntering()) {
                continue;
            }

            if ($node instanceof FencedCode || $node instanceof IndentedCode || $node instanceof Code) {
                $code[$node->getLiteral()] = ($code[$node->getLiteral()] ?? 0) + 1;
            } elseif ($node instanceof HtmlBlock || $node instanceof HtmlInline) {
                preg_match_all(self::TAG, $node->getLiteral(), $matches);

                foreach ($matches[0] as $tag) {
                    $tags[$tag] = ($tags[$tag] ?? 0) + 1;
                }
            }
        }

        return new self($code, $tags);
    }

    /**
     * Whether this document, a translation of $source, still holds every piece of the source's code as code, and no tag
     * of raw HTML more often than the source does.
     */
    public function keeps(self $source): bool
    {
        foreach ($source->code as $literal => $count) {
            if (($this->code[$literal] ?? 0) < $count) {
                return false;
            }
        }

        foreach ($this->tags as $tag => $count) {
            if ($count > ($source->tags[$tag] ?? 0)) {
                return false;
            }
        }

        return true;
    }
}
