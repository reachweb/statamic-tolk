<?php

namespace Reach\StatamicTolk\Runs;

use Closure;
use Statamic\Contracts\Auth\User;

/**
 * Whom the saves a run makes are for: its creator, or nobody for a run from the command line. Their events cannot tell,
 * because a queue worker has no signed-in user, so the automatic update such a save starts (T11.1) asks here, and a
 * chain of localizations is updated with the rights of the user who saved its origin.
 */
final class RunSaves
{
    /**
     * @var list<?User> the creators of the runs saving now, the innermost last
     */
    private static array $creators = [];

    public static function as(?User $creator, Closure $callback): mixed
    {
        self::$creators[] = $creator;

        try {
            return $callback();
        } finally {
            array_pop(self::$creators);
        }
    }

    /**
     * Whom a save made now is for: the creator of the run making it, else $user.
     */
    public static function user(?User $user): ?User
    {
        return self::$creators === [] ? $user : self::$creators[array_key_last(self::$creators)];
    }
}
