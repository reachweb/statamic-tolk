<?php

namespace Reach\StatamicTolk\Content\Markup;

use Reach\StatamicTolk\Enums\SegmentKind;

final readonly class Tokenized
{
    /**
     * @param  array<string, string>  $map  placeholder id → the original string it replaces; `/n` holds the closing tag of pair `n`
     * @param  array<string, string>  $placeholders  placeholder id → descriptor (§A3)
     */
    public function __construct(
        public string $text,
        public SegmentKind $kind,
        public array $map = [],
        public array $placeholders = [],
    ) {}
}
