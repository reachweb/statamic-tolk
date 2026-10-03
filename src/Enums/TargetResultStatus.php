<?php

namespace Reach\StatamicTolk\Enums;

enum TargetResultStatus: string
{
    case Done = 'done';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
