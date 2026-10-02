<?php

namespace Reach\StatamicTolk\Enums;

enum SegmentKind: string
{
    case Plain = 'plain';
    case Inline = 'inline';
    case Markdown = 'markdown';
    case Html = 'html';
}
