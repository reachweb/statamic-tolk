<?php

namespace Reach\StatamicTolk\Enums;

enum FieldStatus: string
{
    case NotApplicable = 'not_applicable';
    case Untranslated = 'untranslated';
    case Current = 'current';
    case Outdated = 'outdated';
    case Manual = 'manual';
}
