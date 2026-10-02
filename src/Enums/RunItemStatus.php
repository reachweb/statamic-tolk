<?php

namespace Reach\StatamicTolk\Enums;

enum RunItemStatus: string
{
    case Pending = 'pending';
    case Translating = 'translating';
    case Translated = 'translated';
    case Applying = 'applying';
    case Done = 'done';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Done, self::Skipped, self::Failed => true,
            default => false,
        };
    }
}
