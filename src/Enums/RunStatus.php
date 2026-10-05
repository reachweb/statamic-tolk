<?php

namespace Reach\StatamicTolk\Enums;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Cancelled = 'cancelled';
    case Aborted = 'aborted';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Pending, self::Running => false,
            default => true,
        };
    }
}
