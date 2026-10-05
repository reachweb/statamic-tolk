<?php

namespace Reach\StatamicTolk\Enums;

enum UsageSource: string
{
    case Editor = 'editor';
    case Run = 'run';
    case Cli = 'cli';
}
