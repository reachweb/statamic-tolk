<?php

namespace Reach\StatamicTolk\Content\Markup;

final readonly class Serialized
{
    /**
     * @param  array<int|string, array|string>  $map  run id → its marks; atom id → the node it stands for (a code run as one text node), or the Antlers tag
     * @param  array<int|string, string>  $placeholders  placeholder id → descriptor (§A3)
     * @param  list<array>  $nodes  the inline nodes as they were serialized
     * @param  list<array>  $breaks  the hardBreak nodes, in order
     */
    public function __construct(
        public string $text,
        public array $map = [],
        public array $placeholders = [],
        public array $nodes = [],
        public array $breaks = [],
    ) {}
}
