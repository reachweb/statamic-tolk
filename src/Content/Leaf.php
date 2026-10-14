<?php

namespace Reach\StatamicTolk\Content;

use Reach\StatamicTolk\Enums\SegmentKind;

final readonly class Leaf
{
    /**
     * @param  list<string|int>  $path
     * @param  array<string, string>  $placeholders
     * @param  bool  $pluralForm  one form of a pluralised lang string, whose translation must hold no `|` (§A16)
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
    ) {}
}
