<?php

namespace Reach\StatamicTolk\Content;

use Reach\StatamicTolk\Enums\SegmentKind;

final readonly class Leaf
{
    /**
     * @param  list<string|int>  $path
     * @param  array<string, string>  $placeholders
     * @param  bool  $pluralForm  one form of a pluralised lang string, whose translation must hold no `|` (§A16)
     * @param  array<string, string>  $originals  of a markdown leaf: placeholder id → what it stands for in a translation (Tokenized::originals()), which the validator reads the translation back with (§A3 rule 7)
     */
    public function __construct(
        public string $text,
        public SegmentKind $kind,
        public array $path,
        public string $label,
        public ?string $hint = null,
        public ?int $maxLength = null,
        public array $placeholders = [],
        public bool $pluralForm = false,
        public array $originals = [],
    ) {}
}
