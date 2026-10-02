<?php

namespace Reach\StatamicTolk\Enums;

enum SegmentKind: string
{
    case Plain = 'plain';
    case Inline = 'inline';
    case Markdown = 'markdown';
    case Html = 'html';

    public function escapes(): bool
    {
        return $this === self::Plain || $this === self::Inline;
    }
}
