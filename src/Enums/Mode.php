<?php

namespace Reach\StatamicTolk\Enums;

enum Mode: string
{
    case Missing = 'missing';
    case Update = 'update';
    case All = 'all';
    case Review = 'review';
}
