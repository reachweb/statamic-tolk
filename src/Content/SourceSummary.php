<?php

namespace Reach\StatamicTolk\Content;

use Illuminate\Support\Str;
use Reach\StatamicTolk\Content\Markup\Placeholders;
use Reach\StatamicTolk\Content\Markup\Tokenizer;
use Reach\StatamicTolk\Contracts\Target;

final class SourceSummary
{
    public const int LENGTH = 600;

    public function __construct(private readonly ContentWalker $walker) {}

    /**
     * The first LENGTH characters of the source's longest in-scope field, as plain text.
     */
    public function of(Target $target): string
    {
        $longest = '';

        foreach ($target->fields() as $handle => $field) {
            if (! $target->sourceHasValue($handle)) {
                continue;
            }

            $text = $this->plainText($this->walker->leaves($field, $target->sourceValue($handle)));

            if (mb_strlen($text) > mb_strlen($longest)) {
                $longest = $text;
            }
        }

        return mb_substr($longest, 0, self::LENGTH);
    }

    /**
     * @param  list<Leaf>  $leaves
     */
    private function plainText(array $leaves): string
    {
        $texts = array_map(function (Leaf $leaf): string {
            $text = preg_replace('/'.Placeholders::IDS.'|<br\/>/', ' ', $leaf->text);

            return $leaf->kind->escapes() ? Tokenizer::unescape($text) : $text;
        }, $leaves);

        return Str::squish(implode(' ', $texts));
    }
}
