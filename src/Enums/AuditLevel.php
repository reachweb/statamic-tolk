<?php

namespace Reach\StatamicTolk\Enums;

enum AuditLevel: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';

    public static function worst(self ...$levels): self
    {
        foreach ([self::Error, self::Warning] as $case) {
            if (in_array($case, $levels, true)) {
                return $case;
            }
        }

        return self::Ok;
    }
}
