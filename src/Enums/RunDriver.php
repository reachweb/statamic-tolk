<?php

namespace Reach\StatamicTolk\Enums;

enum RunDriver: string
{
    case Queue = 'queue';
    case Browser = 'browser';
    case Cli = 'cli';
}
