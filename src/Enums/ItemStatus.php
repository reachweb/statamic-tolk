<?php

namespace Reach\StatamicTolk\Enums;

enum ItemStatus: string
{
    case Missing = 'missing';
    case Untranslated = 'untranslated';
    case Outdated = 'outdated';
    case Attention = 'attention';
    case Current = 'current';

    public static function worst(self ...$statuses): self
    {
        foreach (self::cases() as $case) {
            if (in_array($case, $statuses, true)) {
                return $case;
            }
        }

        return self::Current;
    }
}
